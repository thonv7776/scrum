<?php

/** US15/17/20/23 - Payment, receipts and customer booking history. */
if (in_array($action, ['my_bookings',
    'payment_context',
    'pay',
    'receipt',
    'arrival_log'], true)) {
    $owner = require_auth();
    if ($action === 'my_bookings') {
        $q = $pdo->prepare('SELECT booking_id FROM bookings WHERE customer_id=? ORDER BY booking_id DESC LIMIT 100');
        $q->execute([$owner]);
        $rows = [];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) $rows[] = s2_booking_public(s2_query_booking($pdo, (int)$id, $owner));
        response(['ok' => true,
            'bookings' => $rows]);
    }
    if ($action === 'payment_context') {
        $id = (int)($d['booking_id'] ?? ($_SESSION['draft_booking_id'] ?? 0));
        if (!$id) {
            $q = $pdo->prepare("SELECT booking_id FROM bookings WHERE customer_id=? AND booking_status='Held' AND slot_hold_until>NOW() ORDER BY booking_id DESC LIMIT 1");
            $q->execute([$owner]);
            $id = (int)$q->fetchColumn();
        }
        if (!$id) failure('No active reserved booking. Return to Booking and confirm a trip.', 404);
        $b = s2_query_booking($pdo, $id, $owner);
        if (!in_array($b['booking_status'], ['Held',
            'Paid & Confirmed',
            'Forwarded to Control Centre'], true)) failure('This booking has not reached checkout.', 422);
        response(['ok' => true,
            'booking' => s2_booking_public($b)]);
    }
    if ($action === 'pay') {
        $errs = s2_card_errors($d);
        if ($errs) failure('Please correct the card fields.', 422, $errs);
        $card = preg_replace('/\s+/', '', (string)$d['card_number']);
        $id = (int)($d['booking_id'] ?? ($_SESSION['draft_booking_id'] ?? 0));
        if (!$id) failure('Please reserve a vehicle before checkout.');
        $pdo->beginTransaction();
        try {
            $b = s2_query_booking($pdo, $id, $owner, true);
            if ($b['booking_status'] !== 'Held' || !$b['slot_hold_until'] || strtotime($b['slot_hold_until']) <= time()) throw new DomainException('The booking slot has expired. Return to Booking to reserve a new time.');
            if (!$b['minicab_id'] || !$b['pickup_datetime'] || (float)$b['final_payable_fare'] <= 0) throw new DomainException('Booking details are incomplete.');
            // Serialize checkout with telephone reservations using the minicab row lock.
            $carLock = $pdo->prepare('SELECT minicab_id FROM minicabs WHERE minicab_id=? FOR UPDATE');
            $carLock->execute([$b['minicab_id']]);
            if (!$carLock->fetchColumn()) throw new DomainException('The selected minicab is no longer available.');
            // Recheck against all confirmed bookings at commit, including telephone bookings.
            $q = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE booking_id<>? AND minicab_id=? AND pickup_datetime<? AND estimated_end_datetime>? AND booking_status IN ('Paid & Confirmed','Telephone Confirmed','Forwarded to Control Centre')");
            $q->execute([$id,
                $b['minicab_id'],
                $b['estimated_end_datetime'],
                $b['pickup_datetime']]);
            if ((int)$q->fetchColumn() > 0) throw new DomainException('This vehicle already has a confirmed journey at that time.');
            $g = s2_gateway_charge($card, (string)$d['expiry'], (string)$d['cvv'], (string)$d['card_name'], (float)$b['final_payable_fare'], s2_ref($b), (string)($d['verification'] ?? ''));
            if (!$g['ok']) throw new DomainException($g['error']);
            $pdo->prepare("INSERT INTO payments(booking_id,transaction_id,card_last4,amount_gbp,payment_status,gateway_mode) VALUES (?,?,?,?, 'Approved',?)")->execute([$id,
                $g['txn'],
                substr($card,
                - 4),
                $b['final_payable_fare'],
                $g['mode']]);
            $opt = !empty ($d['opt_in_email']) ? 1 : 0;
            $pdo->prepare("UPDATE bookings SET booking_status='Paid & Confirmed',slot_hold_until=NULL,opt_in_email=? WHERE booking_id=?")->execute([$opt,
                $id]);
            $b = s2_query_booking($pdo, $id, $owner);
            s2_enqueue_confirmation($pdo, $b);
            s2_enqueue_payment_sms($pdo, $b);
            s2_enqueue_dispatch($pdo, $id);
            // US23/US33 demo: register the ETA event for this NEW paid booking.
            // Worker forwards the dispatch and triggers SMS later; checkout stays fast.
            phc_demo_schedule_eta($pdo, $id, (int)$b['service_id'], (bool)$b['include_driver']);
            $pdo->commit();
            // Queue to worker (not network calls during checkout); receipt returned immediately.
            response(['ok' => true,
                'receipt' => s2_receipt($pdo,
                $id,
                $owner),
                'notification' => 'queued']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    if ($action === 'receipt') {
        $id = isset ($d['ref']) ? s2_booking_id((string)$d['ref']) : (int)($d['booking_id'] ?? 0);
        response(['ok' => true,
            'receipt' => s2_receipt($pdo,
            $id,
            $owner)]);
    }
    if ($action === 'arrival_log') {
        $id = (int)($d['booking_id'] ?? 0);
        $b = s2_query_booking($pdo, $id, $owner);
        $q = $pdo->prepare("SELECT n.status,n.trigger_at,n.sent_at,n.provider_reference,n.body_text,n.last_error,a.eta_minutes FROM notification_jobs n LEFT JOIN arrival_events a ON a.notification_id=n.notification_id WHERE n.booking_id=? AND n.channel='SMS' ORDER BY n.notification_id DESC LIMIT 10");
        $q->execute([$id]);
        response(['ok' => true,
            'booking' => s2_booking_public($b),
            'vehicle' => ['plate' => $b['registration_no'],
            'model' => $b['make_and_model'],
            'colour' => $b['vehicle_colour'],
            'driver' => $b['driver_full_name']],
            'mobile' => $b['customer_phone'],
            'log' => $q->fetchAll()]);
    }
}
// Staff-only operations (role scoped per endpoint).
