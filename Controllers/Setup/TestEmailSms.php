<?php

/** Manual provider connectivity test; CLI only. Does not alter booking or notification_jobs. */
declare(strict_types = 1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once dirname(__DIR__, 2) . '/Models/Bootstrap.php';

function prompt(string $label): string {
    echo $label;
    return trim((string)fgets(STDIN));
}
echo "PrivateHire Cars - TEST EMAIL & SMS (only to accounts/numbers you control)\n";
echo "1. Gmail/Brevo email test\n2. textbee SMS test\n3. Poll textbee SMS batch status\n";
$choice = prompt('Select 1/2/3: ');
try {
    if ($choice === '1') {
        $to = prompt('Email recipient (your inbox): ');
        $r = phc_email_send($to, 'PrivateHire Cars - Email connection test', "This is a PrivateHire Cars transactional email test.\nNo booking has been created.");
        echo ($r['ok'] ? 'ACCEPTED by email provider' : 'FAILED') . "\n";
        echo 'Provider reference: ' . ($r['provider'] ?? 'unknown') . "\n";
        if (!$r['ok']) echo 'Reason: ' . ($r['error'] ?? 'unknown') . "\n";
        echo "Provider acceptance does not guarantee inbox delivery; check inbox/spam and Gmail Sent/Spam folders.\n";
    } elseif ($choice === '2') {
        $to = prompt('SMS recipient (E.164 +84..., +44..., or local phone): ');
        $r = phc_textbee_send($to, 'PrivateHire Cars SMS test. Your WAMP integration is connected.');
        echo (!empty ($r['pending']) ? 'ACCEPTED / QUEUED BY TEXTBEE' : 'FAILED') . "\n";
        if (!empty ($r['provider'])) echo 'Batch reference: ' . $r['provider'] . "\n";
        if (!$r['ok']) echo 'Reason: ' . ($r['error'] ?? 'unknown') . "\n";
        echo "Confirm actual SENT/DELIVERED from textbee dashboard or choose menu 3.\n";
    } elseif ($choice === '3') {
        $ref = prompt('Paste full textbee:device:batch reference from test: ');
        $r = phc_textbee_check($ref);
        echo 'Result: ' . $r['state'] . "\n";
        if ($r['error'] !== '') echo 'Details: ' . $r['error'] . "\n";
    } else echo "No action taken.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
