<?php

/** Email adapter shared by Sprint 1 verification and Sprint 2 notification flows. */
declare(strict_types = 1);
require_once __DIR__ . '/Settings.php';

/** @param ?callable $http Test-only dependency injection; no network is used by tests. */
function phc_email_send(string $to, string $subject, string $message, ? callable $http = null): array {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false,
        'provider' => 'config',
        'error' => 'Recipient email is invalid.'];
    // Gmail SMTP (free personal Gmail): enable only when explicitly configured.
    // Keep original Brevo and generic API paths unchanged when Gmail is not enabled.
    $gmailUser = phc_notification_value('PHC_GMAIL_USER', 'gmail_user');
    $gmailPass = phc_notification_value('PHC_GMAIL_APP_PASSWORD', 'gmail_app_password');
    if ($gmailUser !== '' || $gmailPass !== '') {
        require_once __DIR__ . '/Gmail.php';
        return phc_gmail_send($to, $subject, $message);
    }
    $http = $http ?? 'phc_notification_http';
    // Brevo transactional email: explicitly configured, so also works in development mode.
    $brevo = phc_notification_value('PHC_BREVO_API_KEY', 'brevo_api_key');
    if ($brevo !== '') {
        $from = phc_notification_value('PHC_EMAIL_FROM', 'email_from');
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return ['ok' => false,
            'provider' => 'brevo',
            'error' => 'Set a verified sender email (PHC_EMAIL_FROM) in Brevo.'];
        $result = $http('POST', 'https://api.brevo.com/v3/smtp/email', ['Accept: application/json',
            'Content-Type: application/json',
            'api-key: ' . $brevo,
        ], ['sender' => ['name' => 'PrivateHire Cars',
            'email' => $from],
            'to' => [['email' => $to]],
            'subject' => $subject,
            'textContent' => $message]);
        $code = (int)($result['code'] ?? 0);
        $data = $result['json'] ?? null;
        $messageId = is_array($data) ? (string)($data['messageId'] ?? '') : '';
        $ok = $code >= 200 && $code < 300 && $messageId !== '' && empty ($result['error']);
        return ['ok' => $ok,
            'provider' => $ok ? 'brevo:' . $messageId : 'brevo',
            'error' => $ok ? '' : ('Brevo did not accept the email (HTTP ' . $code . '). ' . substr((string)($data['message'] ?? $result['error'] ?? ''),
            0,
            180))];
    }
    // Backward compatibility: original generic bearer-token email provider.
    $api = phc_notification_value('PHC_EMAIL_API_URL', 'generic_email_url');
    if ($api !== '') {
        if (!str_starts_with($api, 'https://')) return ['ok' => false,
            'provider' => 'config',
            'error' => 'Email endpoint must use HTTPS.'];
        $token = phc_notification_value('PHC_EMAIL_API_TOKEN', 'generic_email_token');
        if ($token === '') return ['ok' => false,
            'provider' => 'config',
            'error' => 'Email API token is missing.'];
        $from = phc_notification_value('PHC_EMAIL_FROM', 'email_from', 'no-reply@privatehire.example');
        $result = $http('POST', $api, ['Content-Type: application/json',
            'Authorization: Bearer ' . $token], ['to' => $to,
            'from' => $from,
            'subject' => $subject,
            'text' => $message]);
        $code = (int)($result['code'] ?? 0);
        $payload = $result['json'] ?? null;
        $rejected = is_array($payload) && (($payload['success'] ?? true) === false || ($payload['ok'] ?? true) === false);
        $ok = $code >= 200 && $code < 300 && !$rejected && empty ($result['error']);
        return ['ok' => $ok,
            'provider' => 'email-api-' . $code,
            'error' => $ok ? '' : ('Email provider rejected request (HTTP ' . $code . '). ' . substr((string)($result['error'] ?? $payload['message'] ?? ''),
            0,
            180))];
    }
    // Preserve original legacy behavior if explicitly enabled. mail() acceptance is
    // NOT proof of delivery to the recipient's inbox.
    if (getenv('PHC_DEVELOPMENT') === '0') {
        $from = phc_notification_value('PHC_EMAIL_FROM', 'email_from');
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return ['ok' => false,
            'provider' => 'config',
            'error' => 'Set a valid PHC_EMAIL_FROM.'];
        $headers = 'From: ' . $from . "\r\nContent-Type: text/plain; charset=UTF-8";
        $submitted = @mail($to, $subject, $message, $headers);
        return ['ok' => $submitted,
            'provider' => $submitted ? 'php-mail-accepted' : 'php-mail-failed',
            'error' => $submitted ? '' : 'mail() returned false'];
    }
    $file = __DIR__ . '/../runtime/notification_outbox.log';
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
    file_put_contents($file, date('c') . " [EMAIL PREVIEW — NOT SENT] to=$to\nsubject=$subject\n$message\n---\n", FILE_APPEND|LOCK_EX);
    return ['ok' => false,
        'provider' => 'local-preview-not-sent',
        'error' => 'Preview saved locally. No email provider configured; message was NOT delivered.'];
}
