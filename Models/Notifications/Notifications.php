<?php
declare(strict_types = 1);

/** Email opt-in, textbee, 10-second payment SMS and GPS ETA alerts (US20, US23). */
function s2_enqueue_confirmation(PDO $pdo, array $b): void {
    if (!(int)$b['opt_in_email']) {
        $pdo->prepare("UPDATE bookings SET email_status='Not requested' WHERE booking_id=?")->execute([$b['booking_id']]);
        return;
    }
    $text = "PrivateHire Cars booking confirmation\n\nReference: " . s2_ref($b) . "\nService: " . $b['service_name'] . "\nPickup: " . $b['pickup_location'] . "\nDestination: " . $b['destination'] . "\nDate/time (London): " . $b['pickup_datetime'] . "\nMinicab size: " . $b['category_name'] . "\nTotal paid: GBP " . number_format((float)$b['final_payable_fare'], 2) . "\nCancellation/amendment: request changes at least 24 hours before scheduled pickup.\n";
    $pdo->prepare("INSERT INTO notification_jobs(booking_id,channel,event_key,recipient,subject,body_text,status) VALUES (?,'Email',?,?,?,?,'Queued') ON DUPLICATE KEY UPDATE notification_id=notification_id")->execute([$b['booking_id'],
        'confirm-' . $b['booking_id'],
        $b['customer_email'],
        'PrivateHire Cars booking ' . s2_ref($b),
        $text]);
    $pdo->prepare("UPDATE bookings SET email_status='Queued' WHERE booking_id=?")->execute([$b['booking_id']]);
}

/**
 * Coursework transactional SMS: queue once, 10 seconds after a successful sandbox payment.
 * This is a BOOKING CONFIRMATION, not a simulated GPS/arrival notification.
 * Delivery stays in the existing worker and textbee queue, outside checkout.
 */
function s2_payment_sms_text(array $b): string {
    $pickup = substr((string)($b['pickup_datetime'] ?? ''), 0, 16);
    return 'PrivateHire Cars: Payment received. Booking ' . s2_ref($b) . ' confirmed. Pickup ' . $pickup . ' (London). Total GBP' . number_format((float)$b['final_payable_fare'], 2, '.', '') . '.';
}

function s2_enqueue_payment_sms(PDO $pdo, array $b): void {
    if (($b['booking_status'] ?? '') !== 'Paid & Confirmed') return;
    $phone = trim((string)($b['customer_phone'] ?? ''));
    if (!valid_phone($phone)) return;
    // An invalid mobile must not block a paid receipt.
    $id = (int)$b['booking_id'];
    $pdo->prepare("INSERT INTO notification_jobs
       (booking_id,channel,event_key,recipient,body_text,status,trigger_at,due_at)
       VALUES (?,'SMS',?,?,?,'Queued',NOW(),DATE_ADD(NOW(), INTERVAL 10 SECOND))
       ON DUPLICATE KEY UPDATE notification_id=notification_id")->execute([$id,
        'payment-confirmed-sms-' . $id,
        $phone,
        s2_payment_sms_text($b)]);
}

function s2_transport(string $channel, string $to, string $subject, string $message): array {
    if ($channel === 'Email') return phc_email_send($to, $subject, $message);
    // textbee takes priority when configured; only add a transport, not a booking flow.
    if ($channel === 'SMS' && phc_textbee_configured()) return phc_textbee_send($to, $message);
    // Keep the original generic SMS integration working for existing installations.
    $url = (string)getenv('PHC_SMS_URL');
    $token = (string)getenv('PHC_SMS_TOKEN');
    if (!$url || !$token) return ['ok' => false,
        'error' => 'SMS gateway not configured; no SMS was sent.'];
    if (!str_starts_with($url, 'https://')) return ['ok' => false,
        'error' => 'SMS endpoint must use HTTPS.'];
    if (!extension_loaded('curl')) return ['ok' => false,
        'error' => 'cURL not available.'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['to' => $to,
        'message' => $message]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json',
        'Authorization: Bearer ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true]);
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    unset($ch); // PHP 8+: releases cURL handle without deprecated curl_close()
    $reply = is_string($out) ? json_decode($out, true) : null;
    $rejected = is_array($reply) && (($reply['success'] ?? true) === false || ($reply['ok'] ?? true) === false);
    $success = $out !== false && $code >= 200 && $code < 300 && !$rejected;
    return ['ok' => $success,
        'provider' => 'sms-http-' . $code,
        'error' => $success ? '' : ($err ? : ('SMS provider returned HTTP ' . $code . ' or rejected the message'))];
}

function s2_process_notifications(PDO $pdo, int $limit = 10): array {
    $limit = max(1, min($limit, 100));
    $done = [];
    for ($i = 0; $i < $limit; $i++) {
        $pdo->beginTransaction();
        // Already accepted by textbee: NEVER POST again. Poll the original batch instead.
        $q = $pdo->query("SELECT * FROM notification_jobs WHERE status IN ('Queued','Failed') AND attempts<3 AND due_at<=NOW() AND (provider_reference IS NULL OR provider_reference NOT LIKE 'textbee:%') ORDER BY due_at,notification_id LIMIT 1 FOR UPDATE");
        $job = $q->fetch();
        if (!$job) {
            $pdo->commit();
            break;
        }
        $pdo->prepare("UPDATE notification_jobs SET status='Queued',attempts=attempts+1,due_at=DATE_ADD(NOW(), INTERVAL 60 SECOND) WHERE notification_id=?")->execute([$job['notification_id']]);
        $pdo->commit();
        $t = s2_transport($job['channel'], $job['recipient'], (string)$job['subject'], $job['body_text']);
        $ok = (bool)($t['ok'] ?? false);
        // A textbee 200 means accepted for sending. Status remains Queued until Android sends.
        $pending = $ok && !empty ($t['pending']);
        $status = $pending ? 'Queued' : ($ok ? 'Sent' : 'Failed');
        $reference = $t['provider'] ?? null;
        $pdo->prepare('UPDATE notification_jobs SET status=?,sent_at=IF(?=\'Sent\',NOW(),NULL),provider_reference=?,last_error=? WHERE notification_id=?')->execute([$status,
            $status,
            $reference,
            $ok ? null : substr((string)($t['error'] ?? 'Delivery failed'),
            0,
            400),
            $job['notification_id']]);
        if ($job['channel'] === 'Email') $pdo->prepare('UPDATE bookings SET email_status=?,email_retry_count=? WHERE booking_id=?')->execute([$status,
            (int)$job['attempts'] + 1,
            $job['booking_id']]);
        $done[] = ['id' => (int)$job['notification_id'],
            'status' => $status,
            'mode' => $reference];
    }
    return $done;
}

/** Poll accepted textbee batch ids, without consuming a second SMS or altering SQL schema. */
function s2_process_textbee_pending(PDO $pdo, int $limit = 20, ?callable $statusLookup = null): array {
    if (!phc_textbee_configured()) return [];
    $limit = max(1, min(100, $limit));
    $q = $pdo->query("SELECT notification_id,provider_reference FROM notification_jobs WHERE channel='SMS' AND status='Queued' AND provider_reference LIKE 'textbee:%' AND due_at<=NOW() ORDER BY notification_id LIMIT $limit");
    $done = [];
    foreach ($q->fetchAll() as $job) {
        $ref = (string)$job['provider_reference'];
        $result = $statusLookup !== null ? $statusLookup($ref) : phc_textbee_check($ref);
        $state = $result['state'];
        $newStatus = $state === 'sent' ? 'Sent' : ($state === 'failed' ? 'Failed' : 'Queued');
        // Pending gets another status check in 30s. Failed with ref is NOT re-sent automatically.
        $pdo->prepare("UPDATE notification_jobs SET status=?,sent_at=IF(?='Sent',NOW(),NULL),last_error=?,due_at=DATE_ADD(NOW(), INTERVAL 30 SECOND) WHERE notification_id=? AND channel='SMS' AND status='Queued' AND provider_reference=?")->execute([$newStatus,
            $newStatus, // bind the status again for IF(?='Sent', ...)
            $newStatus === 'Sent' ? null : ($result['error'] ? : null),
            $job['notification_id'],
            $ref]);
        $done[] = ['id' => (int)$job['notification_id'],
            'status' => $newStatus];
    }
    return $done;
}

/** Send one confirmed booking to the real GPS endpoint. Never report 'Forwarded' without HTTP acceptance. */
