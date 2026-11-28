<?php

/** US06/07/09/10/11/12 - Service, journey, quote, availability and hold. */
if ($action === 'select_service') {
    $id = filter_var($d['service_id'] ?? null, FILTER_VALIDATE_INT);
    $q = $pdo->prepare('SELECT service_id FROM transport_services WHERE service_id=?');
    $q->execute([$id]);
    if (!$id || !$q->fetch()) throw new DomainException('Select a valid service.');
    $flight = trim((string)($d['flight_number'] ?? ''));
    $days = filter_var($d['hire_days'] ?? 1, FILTER_VALIDATE_INT);
    if ($id === 3 && ($flight === '' || strlen($flight) > 20)) throw new DomainException('Flight number and terminal are required (maximum 20 characters).');
    if ($id === 4 && (!$days || $days < 1 || $days > 30)) throw new DomainException('Daily hire must be 1–30 days.');
    $draftId = (int)($_SESSION['draft_booking_id'] ?? 0);
    if ($draftId) {
        $exists = $pdo->prepare('SELECT booking_status FROM bookings WHERE booking_id=? AND customer_id=?');
        $exists->execute([$draftId,
            $owner]);
        if ($exists->fetchColumn() !== 'Draft') $draftId = 0;
    }
    if ($draftId) {
        $q = $pdo->prepare("UPDATE bookings SET service_id=?,category_id=NULL,minicab_id=NULL,flight_number=?,hire_days=?,pickup_location=NULL,destination=NULL,pickup_key=NULL,destination_key=NULL,distance_miles=0,passenger_count=1,pickup_datetime=NULL,estimated_end_datetime=NULL,booking_status='Draft',slot_hold_until=NULL,base_total_fare=0,online_discount_15=0,final_payable_fare=0,include_driver=0,driver_fee=0 WHERE booking_id=? AND customer_id=?");
        $q->execute([$id,
            $id === 3 ? $flight : null,
            $id === 4 ? $days : null,
            $draftId,
            $owner]);
    }
    if (!$draftId) {
        $pdo->prepare("INSERT INTO bookings (customer_id,service_id,flight_number,hire_days,booking_channel,booking_status) VALUES (?,?,?,?, 'Online','Draft')")->execute([$owner,
            $id,
            $id === 3 ? $flight : null,
            $id === 4 ? $days : null]);
        $draftId = (int)$pdo->lastInsertId();
    }
    $_SESSION['draft_booking_id'] = $draftId;
    response(['ok' => true,
        'booking_id' => $draftId]);
}
if ($action === 'quote') {
    $x = quote_inputs($pdo, $d);
    response(['ok' => true,
        'quote' => $x['fare']]);
}
if ($action === 'save_route') {
    $draft = find_draft($pdo, $owner);
    if ((int)($d['service_id'] ?? 0) !== (int)$draft['service_id']) throw new DomainException('The selected service has changed. Please continue from the Service step.');
    $x = quote_inputs($pdo, $d);
    $f = $x['fare'];
    $pdo->prepare("UPDATE bookings SET category_id=?,minicab_id=NULL,flight_number=?,hire_days=?,pickup_location=?,destination=?,pickup_key=?,destination_key=?,distance_miles=?,passenger_count=?,pickup_datetime=NULL,estimated_end_datetime=NULL,booking_status='Draft',slot_hold_until=NULL,base_total_fare=?,online_discount_15=?,final_payable_fare=?,include_driver=?,driver_fee=? WHERE booking_id=? AND customer_id=?")->execute([$x['category_id'],
        $x['flight'],
        $x['days'],
        $x['pickup'],
        $x['destination'],
        $x['pickup_key'],
        $x['destination_key'],
        $f['distance_miles'],
        $x['pax'],
        $f['base_total'],
        $f['discount'],
        $f['final_total'],
        $x['include_driver'] ? 1 : 0,
        $f['driver_fee'],
        $draft['booking_id'],
        $owner]);
    response(['ok' => true,
        'quote' => $f,
        'booking_id' => $draft['booking_id']]);
}
if ($action === 'availability') {
    $draft = find_draft($pdo, $owner);
    if (!$draft['category_id'] || !$draft['destination_key']) throw new DomainException('Enter your journey details before checking availability.');
    $start = pickup_datetime((string)($d['date'] ?? ''), (string)($d['time'] ?? ''));
    $duration = estimated_duration_minutes((float)$draft['distance_miles'], (int)$draft['service_id'], (int)($draft['hire_days'] ? : 1));
    $end = $start->modify('+' . $duration . ' minutes');
    $vehicles = available_minicabs($pdo, (int)$draft['category_id'], $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), (int)$draft['booking_id']);
    $pdo->prepare("UPDATE bookings SET pickup_datetime=?,estimated_end_datetime=?,minicab_id=NULL,booking_status='Draft',slot_hold_until=NULL WHERE booking_id=? AND customer_id=?")->execute([$start->format('Y-m-d H:i:s'),
        $end->format('Y-m-d H:i:s'),
        $draft['booking_id'],
        $owner]);
    $alternatives = [];
    if (!$vehicles) {
        foreach ([30,
            60,
            90,
            - 30,
            - 60] as $offset) {
            $st = $start->modify(($offset >= 0 ? '+' : '') . $offset . ' minutes');
            if ($st->getTimestamp() < time() + 1800) continue;
            $en = $st->modify('+' . $duration . ' minutes');
            if (available_minicabs($pdo, (int)$draft['category_id'], $st->format('Y-m-d H:i:s'), $en->format('Y-m-d H:i:s'), (int)$draft['booking_id'])) $alternatives[] = ['date' => $st->format('Y-m-d'),
                'time' => $st->format('H:i')];
            if (count($alternatives) >= 3) break;
        }
    }
    response(['ok' => true,
        'available' => (bool)$vehicles,
        'vehicle' => $vehicles ? $vehicles[0] : null,
        'duration_minutes' => $duration,
        'start' => $start->format('Y-m-d H:i'),
        'alternatives' => $alternatives]);
}
if ($action === 'hold') {
    if (empty ($d['confirmed'])) throw new DomainException('Tick Confirm Schedule before proceeding.');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
    try {
        $draft = find_draft($pdo, $owner);
        if ($draft['booking_status'] === 'Held' && strtotime((string)$draft['slot_hold_until']) > time()) {
            $pdo->commit();
            response(['ok' => true,
                'booking_id' => (int)$draft['booking_id'],
                'hold_until' => $draft['slot_hold_until'],
                'hold_until_iso' => (new DateTimeImmutable($draft['slot_hold_until'],
                new DateTimeZone('Europe/London')))->format(DATE_ATOM),
                'final_total' => (float)$draft['final_payable_fare']]);
        }
        if (!$draft['pickup_datetime'] || !$draft['estimated_end_datetime'] || !$draft['category_id']) throw new DomainException('Check availability before confirming your schedule.');
        if (strtotime($draft['pickup_datetime']) < time() + 1800) throw new DomainException('Pickup must be at least 30 minutes from now.');
        $available = available_minicabs($pdo, (int)$draft['category_id'], $draft['pickup_datetime'], $draft['estimated_end_datetime'], (int)$draft['booking_id'], true);
        if (!$available) throw new DomainException('This slot has just been reserved. Please select another time.');
        $vehicle = $available[0];
        $pdo->prepare("UPDATE bookings SET minicab_id=?,booking_status='Held',slot_hold_until=DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE booking_id=? AND customer_id=?")->execute([$vehicle['minicab_id'],
            $draft['booking_id'],
            $owner]);
        $pdo->commit();
        $q = $pdo->prepare('SELECT slot_hold_until,final_payable_fare FROM bookings WHERE booking_id=?');
        $q->execute([$draft['booking_id']]);
        $r = $q->fetch();
        response(['ok' => true,
            'booking_id' => (int)$draft['booking_id'],
            'vehicle' => $vehicle,
            'hold_until' => $r['slot_hold_until'],
            'hold_until_iso' => (new DateTimeImmutable($r['slot_hold_until'],
            new DateTimeZone('Europe/London')))->format(DATE_ATOM),
            'final_total' => (float)$r['final_payable_fare']]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
