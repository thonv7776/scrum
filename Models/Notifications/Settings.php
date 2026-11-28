<?php

/**
 * Optional local credentials for notification providers. This is intentionally not
 * a web route and contains no credentials. Real credentials must stay OUTSIDE www.
 */
declare(strict_types = 1);

function phc_notification_settings(): array {
    static $settings = null;
    if ($settings !== null) return $settings;
    $custom = trim((string)(getenv('PHC_NOTIFICATIONS_CONFIG') ? : ''));
    // Find the WAMP installation root even if the project folder is nested in www.
    // PHC_NOTIFICATIONS_CONFIG can override the location. Never store secrets here.
    $candidate = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'privatehire_notifications.local.php';
    $ancestor = __DIR__;
    while (dirname($ancestor) !== $ancestor) {
        if (is_dir($ancestor . DIRECTORY_SEPARATOR . 'www') && is_dir($ancestor . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php')) {
            $candidate = $ancestor . DIRECTORY_SEPARATOR . 'privatehire_notifications.local.php';
            break;
        }
        $ancestor = dirname($ancestor);
    }
    $path = $custom !== '' ? $custom : $candidate;
    $settings = [];
    if (is_file($path)) {
        $values = require $path;
        if (!is_array($values)) throw new RuntimeException('Notification credentials file must return an array.');
        $settings = $values;
    }
    return $settings;
}

function phc_notification_value(string $env, string $key, string $default = ''): string {
    $v = getenv($env);
    if ($v !== false && trim((string)$v) !== '') return trim((string)$v);
    $settings = phc_notification_settings();
    return trim((string)($settings[$key] ?? $default));
}

/** Single reusable HTTPS/JSON client; prevents insecure TLS options and response leaks. */
function phc_notification_http(string $method, string $url, array $headers, ? array $payload = null): array {
    if (!str_starts_with($url, 'https://')) return ['code' => 0,
        'json' => null,
        'error' => 'Provider URL must use HTTPS.'];
    if (!function_exists('curl_init')) return ['code' => 0,
        'json' => null,
        'error' => 'Enable the PHP cURL extension in WAMP.'];
    $ch = curl_init($url);
    $opt = [CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if ($payload !== null) {
        $data = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $opt[CURLOPT_POSTFIELDS] = $data;
    }
    curl_setopt_array($ch, $opt);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = $raw === false ? curl_error($ch) : '';
    unset($ch); // PHP 8+: releases cURL handle without deprecated curl_close()
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return ['code' => $code,
        'json' => is_array($json) ? $json : null,
        'error' => $err];
}
