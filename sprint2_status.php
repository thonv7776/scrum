<?php
/** Read-only localhost diagnostic for WAMP setup. No credentials or personal data printed. */
declare(strict_types=1);
$remote=(string)($_SERVER['REMOTE_ADDR']??'');
if (!in_array($remote,['127.0.0.1','::1'],true)) {
 http_response_code(403);exit('Localhost only.');
}
require __DIR__.'/lib.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$checks=[];
$checks[]=['PHP '.PHP_VERSION,version_compare(PHP_VERSION,'8.1','>='),'PHP 8.1+'];
$checks[]=['PDO MySQL',extension_loaded('pdo_mysql'),'Enable pdo_mysql in WAMP PHP extensions'];
$tableNames=['customers','bookings','minicabs','minicab_categories','transport_services','staff_accounts','payments','vehicle_dispatch_profiles','dispatch_jobs','notification_jobs','arrival_events'];
$engine='Unknown';$dbError='';$missing=[];
if(extension_loaded('pdo_mysql')) {
 try{
  $pdo=db();$eng=$pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings'")->fetchColumn();$engine=(string)($eng?:'Missing');
  $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
  $missing=array_values(array_diff($tableNames,$tables));
  $checks[]=['Database connection',true,'Connected to privatehire_cars_db'];
  $checks[]=['Sprint 1 + 2 tables',count($missing)===0,count($missing)?'Missing: '.implode(', ',$missing):'All 11 expected tables exist'];
  $checks[]=['Bookings transaction engine',strcasecmp($engine,'InnoDB')===0,'Current: '.$engine];
  if(!count($missing)) {
   $cols=$pdo->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_COLUMN);
   $must=['created_by_staff_id','opt_in_email','email_status','email_retry_count','forwarded_at'];
   $diff=array_values(array_diff($must,$cols));
   $checks[]=['Sprint 2 booking columns',count($diff)===0,count($diff)?'Missing: '.implode(', ',$diff):'Required columns found'];
  }
 }catch(Throwable $e){
  $dbError='Could not connect to MySQL or read database. Verify that the WAMP icon is green, MySQL is running on port 3306, and database credentials match Sprint 1 config.php.';
  $checks[]=['Database connection',false,$dbError];
 }
}
function e(string $s): string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="vi"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PrivateHire Sprint 2 – WAMP Status</title>
<style>body{font:16px system-ui,Segoe UI,sans-serif;background:#f5f7fa;color:#19212f;margin:0;padding:24px}.wrap{max-width:800px;margin:auto}h1{font-size:26px}.card{padding:16px 20px;margin:12px 0;background:#fff;border-radius:14px;box-shadow:0 2px 8px #0001}.ok{color:#126a35;font-weight:700}.bad{color:#b32929;font-weight:700}.hint{color:#435066;margin:8px 0 0}a{color:#1359b4}</style><main class="wrap"><h1>Kiểm tra WAMP – PrivateHire Sprint 2</h1><p>Trang kiểm tra chỉ đọc cấu hình, không xóa hay sửa dữ liệu. Chỉ mở được từ máy chủ localhost.</p>
<?php foreach($checks as $row):?><div class="card"><div class="<?= $row[1]?'ok':'bad' ?>"><?= $row[1]?'✓ ĐẠT':'✕ CHƯA ĐẠT' ?> — <?=e($row[0])?></div><p class="hint"><?=e($row[2])?></p></div><?php endforeach;?>
<div class="card"><strong>Nếu database lỗi:</strong><p>1. phpMyAdmin → chọn <code>privatehire_cars_db</code> → Export → SQL để sao lưu.<br>2. Import file <code>sprint2_migration.sql</code> trong gói mới (có thể chạy lại, không dùng file cũ).<br>3. Reload trang này, kiểm tra tất cả dòng đều đạt.</p></div>
<p><a href="index.html">Mở Sprint 1</a> · <a href="sprint2.html">Mở Sprint 2</a></p></main></html>
