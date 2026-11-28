<?php
declare(strict_types = 1);

/** Sessions, CSRF, API response format and return links (US01, US03, US04). */
function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('phc_session');
    session_set_cookie_params(['lifetime' => 0,
        'path' => '/',
        'secure' => !empty ($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax']);
    session_start();
    if (!isset ($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function response(array $value, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function failure(string $message, int $status = 422, array $fields = []): never {
    response(['ok' => false,
        'error' => $message,
        'fields' => $fields], $status);
}

function require_auth(): int {
    if (empty ($_SESSION['customer_id'])) failure('Please sign in to continue booking.', 401);
    return (int)$_SESSION['customer_id'];
}

function validate_csrf(): void {
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) failure('Your session expired. Reload the page and try again.', 419);
}

function body(): array {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw ? : '{}', true);
    if (!is_array($j)) failure('Invalid JSON payload.');
    return $j;
}

function current_base_url(): string {
    if (cfg()['base_url']) return rtrim(cfg()['base_url'], '/');
    $secure = !empty ($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/^[a-zA-Z0-9.\-:\[\]]+$/', $host)) $host = 'localhost';
    $path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if (str_ends_with($path, '/Controllers')) $path = substr($path, 0, - strlen('/Controllers')) ? : '/';
    return ($secure ? 'https' : 'http') . '://' . $host . ($path === '/' ? '' : $path);
}
