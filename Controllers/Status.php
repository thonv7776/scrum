<?php

/** Read-only localhost diagnostic for WAMP setup. No credentials or personal data printed. */
declare(strict_types = 1);
$remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($remote, ['127.0.0.1',
    '::1'], true)) {
    http_response_code(403);
    exit('Localhost only.');
}
require dirname(__DIR__) . '/Models/Bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$checks = [];
$checks[] = ['PHP ' . PHP_VERSION,
    version_compare(PHP_VERSION,
    '8.1',
    '>='),
    'PHP 8.1+'];
$checks[] = ['PDO MySQL',
    extension_loaded('pdo_mysql'),
    'Enable pdo_mysql in WAMP PHP extensions'];
$demo = phc_coursework_demo_enabled();
$realRoute = (bool)getenv('PHC_ROUTING_URL');
$gpsHasUrl = (bool)getenv('PHC_GPS_URL');
$realGps = $gpsHasUrl && (bool)getenv('PHC_GPS_TOKEN');
$demoGps = $demo && !$gpsHasUrl;
$gmail = filter_var(phc_notification_value('PHC_GMAIL_USER', 'gmail_user'), FILTER_VALIDATE_EMAIL) && phc_notification_value('PHC_GMAIL_APP_PASSWORD', 'gmail_app_password') !== '';
$brevo = phc_notification_value('PHC_BREVO_API_KEY', 'brevo_api_key') !== '' && filter_var(phc_notification_value('PHC_EMAIL_FROM', 'email_from'), FILTER_VALIDATE_EMAIL);
$genericEmail = phc_notification_value('PHC_EMAIL_API_URL', 'generic_email_url') !== '';
$realSms = (phc_textbee_configured() && phc_textbee_device() !== '') || ((bool)getenv('PHC_SMS_URL') && (bool)getenv('PHC_SMS_TOKEN'));
$checks[] = ['Road routing provider',
    $realRoute || $demo,
    $realRoute ? 'Road provider URL configured; verify real results separately.' : ($demo ? 'COURSEWORK DEMO: original approximate route mileage for the nine supported locations. Not actual road routing.' : 'No routing provider; existing 9-place distances remain approximate.'),
    (!$realRoute && $demo) ? 'demo' : ''];
$checks[] = ['Email sender',
    (bool)($gmail || $brevo || $genericEmail),
    $gmail ? 'Gmail SMTP configured (check actual inbox to confirm delivery).' : ($brevo ? 'Brevo API configured (check actual inbox).' : ($genericEmail ? 'Email relay URL configured (test delivery).' : 'No real email sender configured.')),
    'configured'];
$checks[] = ['SMS provider',
    $realSms,
    phc_textbee_configured() ? 'textbee configured; keep Android/SIM and worker active, and check real SMS delivery.' : ($realSms ? 'Generic SMS provider configured; test delivery.' : 'No SMS provider configured.'),
    'configured'];
$checks[] = ['GPS dispatch',
    $realGps || $demoGps,
    $realGps ? 'GPS provider credentials configured; verify actual forwarding.' : ($demoGps ? 'COURSEWORK DEMO: forwards booking into a simulated Control Centre with DEMO-GPS reference; no provider contacted.' : 'Real GPS endpoint/token required. Pending queue is not delivered.'),
    $demoGps ? 'demo' : ''];
$checks[] = ['Card processor',
    true,
    'COURSEWORK DEMO: existing sandbox card validation and SBOX transactions. No real charges or VISA Check processing.',
    'demo'];
$tableNames = ['customers',
    'bookings',
    'minicabs',
    'minicab_categories',
    'transport_services',
    'staff_accounts',
    'payments',
    'vehicle_dispatch_profiles',
    'dispatch_jobs',
    'notification_jobs',
    'arrival_events'];
$engine = 'Unknown';
$dbError = '';
$missing = [];
if (extension_loaded('pdo_mysql')) {
    try {
        $pdo = db();
        $eng = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings'")->fetchColumn();
        $engine = (string)($eng ? : 'Missing');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_values(array_diff($tableNames, $tables));
        $checks[] = ['Database connection',
            true,
            'Connected to privatehire_cars_db'];
        $checks[] = ['Booking and staff tables',
            count($missing) === 0,
            count($missing) ? 'Missing: ' . implode(', ',
            $missing) : 'All 11 expected tables exist'];
        $checks[] = ['Bookings transaction engine',
            strcasecmp($engine,
            'InnoDB') === 0,
            'Current: ' . $engine];
        if (!count($missing)) {
            $cols = $pdo->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_COLUMN);
            $must = ['created_by_staff_id',
                'opt_in_email',
                'email_status',
                'email_retry_count',
                'forwarded_at'];
            $diff = array_values(array_diff($must, $cols));
            $checks[] = ['Payment and dispatch booking columns',
                count($diff) === 0,
                count($diff) ? 'Missing: ' . implode(', ',
                $diff) : 'Required columns found'];
        }
    } catch (Throwable $e) {
        $dbError = 'Could not connect to MySQL or read database. Verify that the WAMP icon is green, MySQL is running on port 3306, and database credentials match the project config.php.';
        $checks[] = ['Database connection',
            false,
            $dbError];
    }
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
}?><!doctype html><html lang="vi"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PrivateHire – System Status</title>
<style>body{font:16px system-ui,Segoe UI,sans-serif;background:#f5f7fa;color:#19212f;margin:0;padding:24px}.wrap{max-width:800px;margin:auto}h1{font-size:26px}.card{padding:16px 20px;margin:12px 0;background:#fff;border-radius:14px;box-shadow:0 2px 8px #0001}.ok{color:#126a35;font-weight:700}.bad{color:#b32929;font-weight:700}.demo{color:#9a5300;font-weight:700}.hint{color:#435066;margin:8px 0 0}a{color:#1359b4}</style><main class="wrap"><h1>Kiểm tra WAMP – PrivateHire System</h1><p>Trang kiểm tra chỉ đọc cấu hình, không xóa hay sửa dữ liệu. Chỉ mở được từ máy chủ localhost.</p>
<?php foreach ($checks as $row): ?><?php $mode = $row[3] ?? '';
$cl = $mode === 'demo' ? 'demo' : ($row[1] ? 'ok' : 'bad');
$tag = $mode === 'demo' ? '◈ MÔ PHỎNG' : ($row[1] ? '✓ ĐÃ CẤU HÌNH' : '✕ CHƯA CẤU HÌNH'); ?><div class="card"><div class="<?=e($cl)?>"><?=e($tag)?> — <?=e($row[0])?></div><p class="hint"><?=e($row[2])?></p></div><?php endforeach; ?>
<div class="card"><strong>Nếu database lỗi:</strong><p>1. Kiểm tra WAMP/MySQL đang chạy và cấu hình trong <code>config.php</code>.<br>2. Nếu thiếu bảng, sao lưu database bằng phpMyAdmin → Export → SQL, rồi kiểm tra bản migration dữ liệu đã import trước đó. Gói code này <strong>không cài lại database</strong>.<br>3. Không import file <code>init_database.sql</code> trên database đang có dữ liệu.</p></div>
<p><a href="index.html">Mở website PrivateHire Cars</a> · <a href="coursework_demo.php">Mở công cụ DEMO cho Staff (cần đăng nhập)</a></p></main></html>
