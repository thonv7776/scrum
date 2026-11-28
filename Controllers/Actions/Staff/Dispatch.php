<?php

/** US23/33 - GPS dispatch and arrival-event SMS. */
if ($action === 'dispatch_list') {
    s2_require_staff_ready($pdo, ['Call Centre Staff',
        'Manager',
        'Admin']);
    $q = $pdo->query("SELECT j.dispatch_id,j.booking_id,j.status AS dispatch_status,j.forwarded_at,j.gps_reference,j.last_error,b.booking_channel FROM dispatch_jobs j JOIN bookings b ON b.booking_id=j.booking_id ORDER BY j.dispatch_id DESC LIMIT 100");
    $rows = [];
    foreach ($q->fetchAll() as $r) {
        $b = s2_query_booking($pdo, (int)$r['booking_id']);
        $payload = null;
        $bad = null;
        try {
            $payload = s2_dispatch_payload($b);
        } catch (DomainException $e) {
            $bad = $e->getMessage();
        }
        $rows[] = array_merge(s2_booking_public($b), ['dispatch_status' => $r['dispatch_status'],
            'forwarded_at' => $r['forwarded_at'],
            'gps_reference' => $r['gps_reference'],
            'dispatch_error' => $r['last_error'],
            'dispatch_bad' => $bad,
            'payload' => $payload]);
    }
    response(['ok' => true,
        'jobs' => $rows]);
}
if ($action === 'dispatch_forward') {
    s2_require_staff_ready($pdo, ['Call Centre Staff',
        'Manager',
        'Admin']);
    $out = s2_forward_job($pdo, (int)($d['booking_id'] ?? 0));
    if ($out['status'] === 'Failed') failure('GPS provider failed: ' . $out['error'], 503);
    response(['ok' => true] + $out);
}
if ($action === 'arrival_event') {
    // Staff/manual demo events require staff auth; production GPS webhook should use its own secret via dedicated endpoint.
    s2_require_staff_ready($pdo, ['Call Centre Staff',
        'Manager',
        'Admin']);
    $id = (int)($d['booking_id'] ?? 0);
    $eta = (int)($d['eta_minutes'] ?? - 1);
    $event = trim((string)($d['event_id'] ?? ''));
    if ($event === '' || strlen($event) > 120) failure('An event ID is required.');
    if ($eta !== 0 && ($eta < 10 || $eta > 15)) failure('SMS triggers only at ETA 10–15 minutes or arrival (0).');
    $b = s2_query_booking($pdo, $id);
    if (!in_array($b['booking_status'], ['Paid & Confirmed',
        'Telephone Confirmed',
        'Forwarded to Control Centre'], true)) failure('Booking is not confirmed.');
    if (!$b['registration_no'] || !$b['driver_full_name'] || !valid_phone((string)$b['customer_phone'])) failure('SMS requires verified mobile, vehicle and driver details.');
    $text = 'PrivateHire Cars: Your minicab is arriving. Plate ' . $b['registration_no'] . ', ' . $b['vehicle_colour'] . ' ' . $b['make_and_model'] . ', driver ' . $b['driver_full_name'] . ', ETA ' . $eta . ' min. Pickup: ' . $b['pickup_location'] . '.';
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('INSERT IGNORE INTO arrival_events(booking_id,provider_event_id,eta_minutes) VALUES (?,?,?)');
        $q->execute([$id,
            $event,
            $eta]);
        if ($q->rowCount() === 0) {
            $pdo->commit();
            response(['ok' => true,
                'duplicate' => true]);
        }
        $pdo->prepare("INSERT INTO notification_jobs(booking_id,channel,event_key,recipient,body_text,status,trigger_at) VALUES (?,'SMS',?,?,?,'Queued',NOW())")->execute([$id,
            'sms-' . $event,
            $b['customer_phone'],
            $text]);
        $notif = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE arrival_events SET notification_id=? WHERE provider_event_id=?')->execute([$notif,
            $event]);
        $pdo->commit();
        response(['ok' => true,
            'queued' => true,
            'notification_id' => $notif]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
