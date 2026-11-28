<?php

/** Gmail SMTP adapter. No Composer/dependency or database schema change required. */
declare(strict_types = 1);
require_once __DIR__ . '/Settings.php';

/** Read a multiline SMTP response and check its final status code. */
function phc_smtp_read($stream, array $expected): void {
    $response = '';
    $code = 0;
    for ($i = 0; $i < 30; $i++) {
        $line = fgets($stream, 4096);
        if ($line === false) throw new RuntimeException('SMTP connection closed or timed out.');
        $response .= $line;
        if (strlen($response) > 16000) throw new RuntimeException('Oversized SMTP server response.');
        if (preg_match('/^(\d{3}) /', $line, $m)) {
            $code = (int)$m[1];
            break;
        }
        if (!preg_match('/^\d{3}[ -]/', $line)) throw new RuntimeException('Malformed SMTP response.');
    }
    if (!in_array($code, $expected, true)) {
        // Avoid returning server responses that might contain account information.
        throw new RuntimeException('Gmail SMTP rejected a command (status ' . ($code ? : 'unknown') . ').');
    }
}

function phc_smtp_write($stream, string $command): void {
    $total = strlen($command);
    $offset = 0;
    while ($offset < $total) {
        $n = fwrite($stream, substr($command, $offset));
        if ($n === false || $n === 0) throw new RuntimeException('Unable to write to Gmail SMTP server.');
        $offset += $n;
    }
}

function phc_smtp_command($stream, string $cmd, array $expected): void {
    phc_smtp_write($stream, $cmd . "\r\n");
    phc_smtp_read($stream, $expected);
}

/**
 * Sends a single plain-text email using Gmail SMTP port 587 + STARTTLS.
 * The optional $transport callback is for deterministic tests without network traffic.
 * Returns ok=true ONLY after Gmail SMTP has accepted the message (250), not delivery to inbox.
 */
function phc_gmail_send(string $to, string $subject, string $message, ? callable $transport = null): array {
    $user = phc_notification_value('PHC_GMAIL_USER', 'gmail_user');
    $pass = preg_replace('/\s+/', '', phc_notification_value('PHC_GMAIL_APP_PASSWORD', 'gmail_app_password'));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false,
        'provider' => 'gmail-smtp',
        'error' => 'Invalid recipient email.'];
    if (!filter_var($user, FILTER_VALIDATE_EMAIL) || $pass === '') return ['ok' => false,
        'provider' => 'gmail-smtp',
        'error' => 'Gmail username or App Password not configured.'];
    if (preg_match('/[\r\n]/', $to . $user . $subject) || strlen($subject) > 180) return ['ok' => false,
        'provider' => 'gmail-smtp',
        'error' => 'Invalid email header.'];
    if (strlen($message) > 200000) return ['ok' => false,
        'provider' => 'gmail-smtp',
        'error' => 'Email message too large.'];
    try {
        if ($transport !== null) {
            $accepted = (bool)$transport($user, $pass, $to, $subject, $message);
            if (!$accepted) throw new RuntimeException('SMTP transport declined the message.');
        } else {
            $ctx = stream_context_create(['ssl' => ['verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
                'peer_name' => 'smtp.gmail.com',
                ]]);
            $stream = @stream_socket_client('tcp://smtp.gmail.com:587', $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
            if (!$stream) throw new RuntimeException('Cannot connect to Gmail SMTP on port 587. Check firewall or Internet connection.');
            try {
                stream_set_timeout($stream, 15);
                phc_smtp_read($stream, [220]);
                phc_smtp_command($stream, 'EHLO localhost', [250]);
                phc_smtp_command($stream, 'STARTTLS', [220]);
                $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                if (@stream_socket_enable_crypto($stream, true, $crypto) !== true) throw new RuntimeException('TLS negotiation with Gmail failed. Check PHP OpenSSL / CA certificates.');
                phc_smtp_command($stream, 'EHLO localhost', [250]);
                phc_smtp_command($stream, 'AUTH LOGIN', [334]);
                phc_smtp_command($stream, base64_encode($user), [334]);
                phc_smtp_command($stream, base64_encode($pass), [235]);
                phc_smtp_command($stream, 'MAIL FROM:<' . $user . '>', [250]);
                phc_smtp_command($stream, 'RCPT TO:<' . $to . '>', [250,
                    251]);
                phc_smtp_command($stream, 'DATA', [354]);
                // Base64-encoded body prevents dot-stuffing issues and supports Vietnamese UTF-8.
                $headers = ['Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
                    'From: "PrivateHire Cars" <' . $user . '>',
                    'To: <' . $to . '>',
                    'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
                    'MIME-Version: 1.0',
                    'Content-Type: text/plain; charset=UTF-8',
                    'Content-Transfer-Encoding: base64',
                ];
                $payload = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($message), 76, "\r\n");
                phc_smtp_write($stream, $payload . ".\r\n");
                phc_smtp_read($stream, [250]);
                try {
                    phc_smtp_command($stream, 'QUIT', [221]);
                } catch (Throwable $ignore) {
                }
            } finally {
                fclose($stream);
            }
        }
        return ['ok' => true,
            'provider' => 'gmail-smtp-accepted',
            'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false,
            'provider' => 'gmail-smtp',
            'error' => $e->getMessage()];
    }
}
