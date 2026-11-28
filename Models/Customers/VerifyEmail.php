<?php
declare(strict_types = 1);

/** Account activation emails and 24-hour one-time link (US01, US03). */
function send_verification_email(PDO $db, int $id, string $email): array {
    require_once __DIR__ . '/../Notifications/Email.php';
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $db->prepare('UPDATE verification_tokens SET is_used=1 WHERE customer_id=? AND is_used=0')->execute([$id]);
    $db->prepare('INSERT INTO verification_tokens (customer_id,token_hash,expires_at,is_used) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR),0)')->execute([$id,
        $hash]);
    $url = current_base_url() . '/verify.php?token=' . urlencode($token);
    $subject = 'Activate your PrivateHire Cars account';
    $message = "Welcome to PrivateHire Cars!\n\nVerify your account within 24 hours:\n$url\n\nThis link works once.\n";
    $from = preg_replace('/[^A-Za-z0-9@._+\-]/', '', cfg()['mail_from']);
    $delivery = phc_email_send($email, $subject, $message);
    $out = ['delivery' => $delivery['ok'] ? 'submitted_to_provider' : 'not_configured',
        'message' => $delivery['ok'] ? 'Provider accepted message' : ($delivery['error'] ?? 'Delivery not available')];
    if (cfg()['development_mode']) {
        if (!is_dir(__DIR__ . '/../runtime')) mkdir(__DIR__ . '/../runtime', 0700, true);
        file_put_contents(sys_get_temp_dir() . '/phc_sprint1_mail.log', date('c') . ' ' . $email . ' ' . $url . "\n", FILE_APPEND|LOCK_EX);
        $out['development_link'] = $url;
    }
    return $out;
}
