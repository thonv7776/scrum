<?php
declare(strict_types = 1);

/** Control Centre, mock GPS dispatch and caller identity (US31, US33). */
function s2_coords(string $key): ? array {
    $m = ['heathrow' => [51.4700,
        - 0.4543],
        'paddington' => [51.5154,
        - 0.1755],
        'baker' => [51.5238,
        - 0.1585],
        'westminster' => [51.4994,
        - 0.1273],
        'savoy' => [51.5101,
        - 0.1204],
        'kingscross' => [51.5308,
        - 0.1238],
        'canary' => [51.5054,
        - 0.0235],
        'cityairport' => [51.5048,
        0.0495],
        'gatwick' => [51.1537,
        - 0.1821]];
    return $m[$key] ?? null;
}

function s2_dispatch_payload(array $b): array {
    $a = s2_coords((string)($b['pickup_key'] ?? ''));
    $z = s2_coords((string)($b['destination_key'] ?? ''));
    // Daily hire has an address for collecting/delivering the vehicle, not an A/B route.
    // A coursework demo can forward this as an address-only handover. Real GPS
    // integrations still require their own provider-specific coordinates.
    $dailyHireDemo = (int)($b['service_id'] ?? 0) === 4
        && phc_coursework_demo_enabled()
        && trim((string)getenv('PHC_GPS_URL')) === '';
    if ((!$a || !$z) && !$dailyHireDemo || empty ($b['registration_no']) || empty ($b['customer_phone']) || empty ($b['pickup_datetime'])) {
        throw new DomainException('Verified caller, vehicle, both location coordinates and schedule are required before dispatch.');
    }
    if ($dailyHireDemo) {
        return [
            'booking_reference' => s2_ref($b),
            'customer' => ['full_name' => $b['customer_name'], 'phone' => $b['customer_phone']],
            'handover' => ['address' => $b['pickup_location'], 'no_fixed_route' => true],
            'scheduled_at' => $b['pickup_datetime'],
            'passenger_count' => (int)$b['passenger_count'],
            'size' => $b['category_name'],
            'vehicle_plate' => $b['registration_no'],
            'simulated' => true,
        ];
    }
    return ['booking_reference' => s2_ref($b),
        'customer' => ['full_name' => $b['customer_name'],
        'phone' => $b['customer_phone']],
        'pickup' => ['name' => $b['pickup_location'],
        'lat' => $a[0],
        'lng' => $a[1]],
        'destination' => ['name' => $b['destination'],
        'lat' => $z[0],
        'lng' => $z[1]],
        'scheduled_at' => $b['pickup_datetime'],
        'passenger_count' => (int)$b['passenger_count'],
        'size' => $b['category_name'],
        'vehicle_plate' => $b['registration_no']];
}

function s2_enqueue_dispatch(PDO $pdo, int $id): void {
    $pdo->prepare("INSERT INTO dispatch_jobs(booking_id,status) VALUES (?, 'Pending') ON DUPLICATE KEY UPDATE booking_id=VALUES(booking_id)")->execute([$id]);
}

function s2_forward_job(PDO $pdo, int $id): array {
    $pdo->beginTransaction();
    try {
        $b = s2_query_booking($pdo, $id, 0, true);
        if (!in_array($b['booking_status'], ['Paid & Confirmed',
            'Telephone Confirmed',
            'Forwarded to Control Centre'], true)) throw new DomainException('Only confirmed bookings can be dispatched.');
        $payload = s2_dispatch_payload($b);
        $pdo->prepare("INSERT IGNORE INTO dispatch_jobs(booking_id,status) VALUES (?,'Pending')")->execute([$id]);
        $q = $pdo->prepare('SELECT * FROM dispatch_jobs WHERE booking_id=? FOR UPDATE');
        $q->execute([$id]);
        $job = $q->fetch();
        if (in_array($job['status'], ['Forwarded',
            'Synced'], true)) {
            $pdo->commit();
            return ['status' => $job['status'],
                'already_forwarded' => true];
        }
        $url = trim((string)getenv('PHC_GPS_URL'));
        $token = (string)getenv('PHC_GPS_TOKEN');
        if ($url === '') {
            // LOCAL COURSEWORK SIMULATION ONLY: store a mock forwarding acknowledgment.
            // The DEMO-GPS marker makes it impossible to confuse with a real GPS provider response.
            if (phc_coursework_demo_enabled()) {
                $ref = phc_coursework_demo_reference($id);
                $pdo->prepare("UPDATE dispatch_jobs SET status='Forwarded',forwarded_at=NOW(),attempts=attempts+1,gps_reference=?,payload_json=?,last_error=NULL WHERE booking_id=?")->execute([$ref,
                    json_encode($payload,
                    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                    $id]);
                $pdo->prepare("UPDATE bookings SET booking_status='Forwarded to Control Centre',forwarded_at=NOW() WHERE booking_id=?")->execute([$id]);
                $pdo->commit();
                return ['status' => 'Forwarded',
                    'gps_reference' => $ref,
                    'simulated' => true];
            }
            $pdo->prepare("UPDATE dispatch_jobs SET status='Pending',payload_json=?,last_error='GPS endpoint not configured; retained in queue' WHERE booking_id=?")->execute([json_encode($payload),
                $id]);
            $pdo->commit();
            return ['status' => 'Pending',
                'message' => 'Queued for GPS dispatch; configure PHC_GPS_URL to forward.'];
        }
        if (!str_starts_with($url, 'https://') || $token === '' || !extension_loaded('curl')) throw new DomainException('GPS endpoint HTTPS/token/cURL is not fully configured.');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'Idempotency-Key: dispatch-' . $id],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true]);
        $reply = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        unset($ch); // PHP 8+: releases cURL handle without deprecated curl_close()
        if ($reply === false || $code < 200 || $code >= 300) {
            $msg = substr($error ? : 'GPS provider HTTP ' . $code, 0, 300);
            $pdo->prepare("UPDATE dispatch_jobs SET status='Failed',attempts=attempts+1,payload_json=?,last_error=? WHERE booking_id=?")->execute([json_encode($payload),
                $msg,
                $id]);
            $pdo->commit();
            return ['status' => 'Failed',
                'error' => $msg];
        }
        $parsed = json_decode((string)$reply, true);
        if (is_array($parsed) && (($parsed['success'] ?? true) === false || ($parsed['ok'] ?? true) === false)) {
            $message = substr((string)($parsed['error'] ?? 'GPS provider explicitly rejected the booking.'), 0, 300);
            $pdo->prepare("UPDATE dispatch_jobs SET status='Failed',attempts=attempts+1,payload_json=?,last_error=? WHERE booking_id=?")->execute([json_encode($payload),
                $message,
                $id]);
            $pdo->commit();
            return ['status' => 'Failed',
                'error' => $message];
        }
        $ref = substr((string)(is_array($parsed) ? ($parsed['reference'] ?? $parsed['id'] ?? '') : ''), 0, 120);
        $pdo->prepare("UPDATE dispatch_jobs SET status='Forwarded',forwarded_at=NOW(),attempts=attempts+1,gps_reference=?,payload_json=?,last_error=NULL WHERE booking_id=?")->execute([$ref,
            json_encode($payload),
            $id]);
        $pdo->prepare("UPDATE bookings SET booking_status='Forwarded to Control Centre',forwarded_at=NOW() WHERE booking_id=?")->execute([$id]);
        $pdo->commit();
        return ['status' => 'Forwarded',
            'gps_reference' => $ref];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function s2_process_dispatch_queue(PDO $pdo, int $limit = 10): array {
    if (!getenv('PHC_GPS_URL') && !phc_coursework_demo_enabled()) return [];
    // No provider or local demo: leave jobs Pending.
    $limit = min(30, max(1, $limit));
    $q = $pdo->query("SELECT booking_id FROM dispatch_jobs WHERE status IN ('Pending','Failed') AND attempts<3 ORDER BY dispatch_id LIMIT $limit");
    $items = [];
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
        try {
            $items[] = ['id' => $id] + s2_forward_job($pdo, (int)$id);
        } catch (Throwable $e) {
            $items[] = ['id' => $id,
                'status' => 'Failed',
                'error' => $e->getMessage()];
        }
    }
    return $items;
}

/** US31: check three facts the caller provided, not fields copied from the DB search result. */
function s2_verify_caller_claims(array $record, array $claims): bool {
    $clean = static function (string $value): string {
        return strtolower(preg_replace('/\s+/u', '', trim($value)));
    };
    $name = trim((string)($claims['name'] ?? ''));
    $phone = trim((string)($claims['phone'] ?? ''));
    $address = trim((string)($claims['address'] ?? ''));
    if (strlen($name) < 2 || !valid_phone($phone) || strlen($address) < 5) return false;
    return hash_equals($clean((string)$record['full_name']), $clean($name)) && hash_equals((string)$record['telephone_number'], $phone) && hash_equals($clean((string)$record['residential_address']), $clean($address));
}
