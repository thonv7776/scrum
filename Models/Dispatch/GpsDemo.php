<?php

/**
 * Small opt-in switch for a coursework-only GPS dispatch simulation.
 * Never allowed for a non-local browser request. No real GPS provider is contacted.
 * Email/Gmail and textbee SMS remain real and are NOT simulated.
 */
declare(strict_types = 1);

function phc_coursework_demo_enabled(): bool {
    $flag = __DIR__ . DIRECTORY_SEPARATOR . 'COURSEWORK_DEMO_ON.flag';
    if (!is_file($flag)) return false;
    if (PHP_SAPI === 'cli') return true;
    // Local Windows WAMP worker
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return in_array($ip, ['127.0.0.1',
        '::1'], true);
}

function phc_coursework_demo_reference(int $bookingId): string {
    if ($bookingId <= 0) throw new DomainException('Invalid coursework booking ID');
    return 'DEMO-GPS-' . str_pad((string)$bookingId, 6, '0', STR_PAD_LEFT);
}


/**
 * US33/US23 coursework hand-off: create a DEMO ETA marker when a NEW booking
 * is confirmed. The flag file is the explicit local opt-in; existing bookings
 * do not retroactively receive messages after installing this feature.
 *
 * A self-drive hire WITHOUT the optional driver never simulates an arriving
 * chauffeur. The ordinary 10-second payment confirmation SMS is unaffected.
 */
function phc_demo_schedule_eta(PDO $pdo, int $bookingId, int $serviceId, bool $includeDriver): void {
    if (!is_file(__DIR__ . '/COURSEWORK_DEMO_ON.flag')) return;
    if ($serviceId === 4 && !$includeDriver) return;
    if ($bookingId <= 0) throw new DomainException('Invalid demo booking.');

    $event = 'coursework-auto-eta12-' . $bookingId;
    $q = $pdo->prepare(
        'INSERT IGNORE INTO arrival_events(booking_id, provider_event_id, eta_minutes) VALUES (?, ?, 12)'
    );
    $q->execute([$bookingId, $event]);
}

/** Build one ETA message from the REAL booking data, never invented plate/driver IDs. */
function phc_demo_eta_text(array $booking, int $eta = 12): string {
    return 'PrivateHire Cars: Your minicab is arriving. Plate '
        . $booking['registration_no'] . ', '
        . $booking['vehicle_colour'] . ' ' . $booking['make_and_model']
        . ', driver ' . $booking['driver_full_name']
        . ', ETA ' . $eta . ' min. Pickup: ' . $booking['pickup_location'] . '.';
}

/**
 * LOCAL SIMULATION: after a mock dispatch has been forwarded, a time-compressed
 * GPS ETA event is emitted 20s after confirmation (ETA represents 12 simulated
 * driving minutes). The unique event ID makes worker restarts idempotent.
 *
 * Only a marker created during a NEW payment/telephone booking is processed.
 * No MySQL migration, no external GPS call, no browser/staff button.
 * Gmail and textbee remain real services and are not simulated.
 */
function phc_demo_process_arrivals(PDO $pdo, int $limit = 10): array {
    if (!phc_coursework_demo_enabled() || trim((string)getenv('PHC_GPS_URL')) !== '') return [];

    $limit = max(1, min(30, $limit));
    $q = $pdo->query(
        "SELECT a.arrival_id, a.booking_id
         FROM arrival_events a
         JOIN dispatch_jobs j ON j.booking_id=a.booking_id
         WHERE a.provider_event_id LIKE 'coursework-auto-eta12-%'
           AND a.notification_id IS NULL
           AND a.event_at <= DATE_SUB(NOW(), INTERVAL 20 SECOND)
           AND j.status IN ('Forwarded','Synced')
         ORDER BY a.arrival_id
         LIMIT $limit"
    );
    $results = [];

    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $arrivalId = (int)$row['arrival_id'];
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare(
                'SELECT booking_id, provider_event_id, notification_id FROM arrival_events WHERE arrival_id=? FOR UPDATE'
            );
            $lock->execute([$arrivalId]);
            $event = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$event || $event['notification_id'] !== null) {
                $pdo->commit();
                continue;
            }

            $booking = s2_query_booking($pdo, (int)$event['booking_id']);
            if (!in_array($booking['booking_status'], ['Paid & Confirmed', 'Telephone Confirmed', 'Forwarded to Control Centre'], true)
                || !$booking['registration_no']
                || !$booking['driver_full_name']
                || !valid_phone((string)$booking['customer_phone'])) {
                // The arrival stays pending until the booking obtains real vehicle/
                // driver/phone data. No placeholder identities are invented.
                $pdo->commit();
                $results[] = ['booking_id' => (int)$event['booking_id'], 'status' => 'Waiting for driver/phone'];
                continue;
            }

            $key = 'sms-' . $event['provider_event_id'];
            $create = $pdo->prepare(
                "INSERT IGNORE INTO notification_jobs
                 (booking_id,channel,event_key,recipient,body_text,status,trigger_at,due_at)
                 VALUES (?,'SMS',?,?,?,'Queued',NOW(),NOW())"
            );
            $create->execute([
                (int)$event['booking_id'],
                $key,
                $booking['customer_phone'],
                phc_demo_eta_text($booking, 12),
            ]);
            $notif = $pdo->prepare('SELECT notification_id FROM notification_jobs WHERE event_key=?');
            $notif->execute([$key]);
            $notificationId = (int)$notif->fetchColumn();
            if (!$notificationId) throw new RuntimeException('Unable to queue simulated ETA SMS.');

            $pdo->prepare('UPDATE arrival_events SET notification_id=? WHERE arrival_id=? AND notification_id IS NULL')
                ->execute([$notificationId, $arrivalId]);
            $pdo->commit();
            $results[] = [
                'booking_id' => (int)$event['booking_id'],
                'notification_id' => $notificationId,
                'eta_minutes' => 12,
                'simulated' => true,
                'status' => 'Queued',
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $results[] = ['arrival_id' => $arrivalId, 'status' => 'Failed', 'error' => $e->getMessage()];
        }
    }
    return $results;
}
