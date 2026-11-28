<?php

/** Genuine GPS provider events. Separate bearer token; no browser session needed. */
declare(strict_types = 1);
require dirname(__DIR__) . '/Models/Bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$secret = (string)getenv('PHC_GPS_WEBHOOK_SECRET');
$header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (!$secret || !hash_equals('Bearer ' . $secret, $header)) {
    http_response_code(401);
    exit(json_encode(['ok' => false,
        'error' => 'Unauthorized']));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false,
        'error' => 'POST only']));
}
try {
    $d = json_decode(file_get_contents('php://input'), true);
    if (!is_array($d)) throw new DomainException('Invalid payload');
    $id = (int)($d['booking_id'] ?? 0);
    $event = trim((string)($d['event_id'] ?? ''));
    $eta = filter_var($d['eta_minutes'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || strlen($event) < 3 || strlen($event) > 120 || $eta === false || ($eta !== 0 && ($eta < 10 || $eta > 15))) throw new DomainException('Invalid GPS event or ETA');
    $db = db();
    $b = s2_query_booking($db, $id);
    if (!in_array($b['booking_status'], ['Paid & Confirmed',
        'Telephone Confirmed',
        'Forwarded to Control Centre'], true)) throw new DomainException('Booking is not confirmed');
    if (!$b['registration_no'] || !$b['driver_full_name'] || !valid_phone($b['customer_phone'])) throw new DomainException('Vehicle, driver or customer phone missing');
    $sms = 'PrivateHire Cars pickup: ' . $b['vehicle_colour'] . ' ' . $b['make_and_model'] . ' plate ' . $b['registration_no'] . ', driver ' . $b['driver_full_name'] . ', ETA ' . $eta . ' mins. Pickup: ' . $b['pickup_location'];
    $db->beginTransaction();
    $q = $db->prepare('INSERT IGNORE INTO arrival_events(booking_id,provider_event_id,eta_minutes) VALUES (?,?,?)');
    $q->execute([$id,
        $event,
        $eta]);
    if (!$q->rowCount()) {
        $db->commit();
        exit(json_encode(['ok' => true,
            'duplicate' => true]));
    }
    $db->prepare("INSERT INTO notification_jobs(booking_id,channel,event_key,recipient,body_text,status,trigger_at) VALUES (?,'SMS',?,?,?,'Queued',NOW())")->execute([$id,
        'sms-' . $event,
        $b['customer_phone'],
        $sms]);
    $db->prepare('UPDATE arrival_events SET notification_id=? WHERE provider_event_id=?')->execute([$db->lastInsertId(),
        $event]);
    $db->commit();
    echo json_encode(['ok' => true,
        'queued' => true]);
} catch (Throwable $e) {
    if (isset ($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(422);
    echo json_encode(['ok' => false,
        'error' => $e->getMessage()]);
}
