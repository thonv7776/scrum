<?php

/** textbee sending and delivery polling; uses only the pre-existing Sprint 2 queue. */
declare(strict_types = 1);
require_once __DIR__ . '/Settings.php';

function phc_textbee_configured(): bool {
    return phc_notification_value('PHC_TEXTBEE_API_KEY', 'textbee_api_key') !== '';
}

function phc_textbee_device(): string {
    return phc_notification_value('PHC_TEXTBEE_DEVICE_ID', 'textbee_device_id');
}

/** Convert explicitly supported local mobile formats; never silently guess country. */
function phc_textbee_phone(string $number): ? string {
    $number = preg_replace('/[\s().-]+/', '', trim($number));
    if (str_starts_with($number, '00')) $number = '+' . substr($number, 2);
    if (preg_match('/^\+[1-9]\d{7,14}$/D', $number)) return $number;
    $country = strtoupper(phc_notification_value('PHC_SMS_DEFAULT_COUNTRY', 'sms_country', 'GB'));
    if ($country === 'VN' && preg_match('/^0[35789]\d{8}$/D', $number)) return '+84' . substr($number, 1);
    if ($country === 'GB' && preg_match('/^07\d{9}$/D', $number)) return '+44' . substr($number, 1);
    return null;
}

function phc_textbee_reference(string $device, string $batch): string {
    if (!preg_match('/^[a-zA-Z0-9_-]{8,48}$/D', $device) || !preg_match('/^[a-zA-Z0-9_-]{8,60}$/D', $batch)) throw new DomainException('Invalid textbee device or SMS batch id.');
    return 'textbee:' . $device . ':' . $batch;
}

function phc_textbee_parse_reference(string $ref): ? array {
    if (!preg_match('/^textbee:([a-zA-Z0-9_-]{8,48}):([a-zA-Z0-9_-]{8,60})$/D', $ref, $m)) return null;
    return ['device' => $m[1],
        'batch' => $m[2]];
}

/** Accept means queued on textbee, NOT yet sent by the handset. */
function phc_textbee_send(string $to, string $message, ? callable $http = null): array {
    $api = phc_notification_value('PHC_TEXTBEE_API_KEY', 'textbee_api_key');
    $device = phc_textbee_device();
    if ($api === '' || $device === '') return ['ok' => false,
        'error' => 'Configure textbee API key and Device ID in the local credentials file.'];
    if (!preg_match('/^[a-zA-Z0-9_-]{8,48}$/D', $device)) return ['ok' => false,
        'error' => 'Invalid textbee Device ID.'];
    $phone = phc_textbee_phone($to);
    if ($phone === null) return ['ok' => false,
        'error' => 'SMS number must be E.164, or a supported GB/VN mobile number.'];
    $http = $http ?? 'phc_notification_http';
    $r = $http('POST', 'https://api.textbee.dev/api/v1/gateway/send-sms', ['Content-Type: application/json',
        'x-api-key: ' . $api,
    ], ['recipients' => [$phone],
        'message' => $message,
        'deviceId' => $device]);
    $code = (int)($r['code'] ?? 0);
    $data = $r['json']['data'] ?? null;
    $batch = is_array($data) ? (string)($data['smsBatchId'] ?? '') : '';
    $accepted = $code >= 200 && $code < 300 && $batch !== '' && ($data['success'] ?? true) !== false && empty ($r['error']);
    if (!$accepted) return ['ok' => false,
        'error' => 'textbee did not queue the SMS (HTTP ' . $code . '). ' . substr((string)($r['json']['message'] ?? $r['error'] ?? 'Check Device ID, key, sender SIM, quota and phone number.'),
        0,
        180)];
    try {
        $ref = phc_textbee_reference($device, $batch);
    } catch (DomainException $e) {
        return ['ok' => false,
            'error' => 'textbee returned a batch id that cannot be tracked. Check dashboard before retrying to avoid duplicates.'];
    }
    return ['ok' => true,
        'pending' => true,
        'provider' => $ref,
        'error' => ''];
}

/** @return array{state:string,error:string} state pending|sent|failed */
function phc_textbee_check(string $ref, ? callable $http = null): array {
    $parts = phc_textbee_parse_reference($ref);
    if (!$parts) return ['state' => 'failed',
        'error' => 'Malformed textbee tracking reference.'];
    $api = phc_notification_value('PHC_TEXTBEE_API_KEY', 'textbee_api_key');
    if (!$api) return ['state' => 'pending',
        'error' => 'textbee API key not configured for status polling.'];
    $url = 'https://api.textbee.dev/api/v1/gateway/devices/' . rawurlencode($parts['device']) . '/sms-batch/' . rawurlencode($parts['batch']);
    $http = $http ?? 'phc_notification_http';
    $r = $http('GET', $url, ['x-api-key: ' . $api,
        'Accept: application/json']);
    $code = (int)($r['code'] ?? 0);
    if ($code < 200 || $code >= 300 || !empty ($r['error'])) return ['state' => 'pending',
        'error' => 'textbee status lookup failed (HTTP ' . $code . ').'];
    $data = $r['json']['data'] ?? null;
    if (!is_array($data)) return ['state' => 'pending',
        'error' => 'Missing batch status in textbee response.'];
    $items = $data['messages'] ?? null;
    if (is_array($items) && count($items) === 1) {
        $status = strtolower((string)($items[0]['status'] ?? ''));
        if (in_array($status, ['sent',
            'delivered'], true)) return ['state' => 'sent',
            'error' => ''];
        if ($status === 'failed') return ['state' => 'failed',
            'error' => substr((string)($items[0]['errorMessage'] ?? $items[0]['error'] ?? 'Phone failed to send SMS.'),
            0,
            300)];
        return ['state' => 'pending',
            'error' => $status === 'unknown' ? 'textbee reports unknown; inspect dashboard; no automatic resend.' : ''];
    }
    $batch = $data['batch'] ?? null;
    $status = is_array($batch) ? strtolower((string)($batch['status'] ?? '')) : '';
    if (in_array($status, ['completed',
        'sent',
        'delivered'], true) && (int)($batch['successCount'] ?? 1) > 0) return ['state' => 'sent',
        'error' => ''];
    if ($status === 'failed') return ['state' => 'failed',
        'error' => 'textbee indicates send failure.'];
    return ['state' => 'pending',
        'error' => $status === 'unknown' ? 'textbee batch status unknown; check dashboard.' : ''];
}
