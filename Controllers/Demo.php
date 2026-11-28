<?php

/** Separate, localhost-only coursework control panel. NEVER changes Sprint 1/2 UI. */
declare(strict_types = 1);
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($ip, ['127.0.0.1',
    '::1'], true)) {
    http_response_code(403);
    exit('This coursework panel is restricted to localhost.');
}
require dirname(__DIR__) . '/Models/Bootstrap.php';
start_secure_session();
header('Cache-Control: no-store');

function demo_e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
}

function demo_mask_phone(string $p): string {
    return str_repeat('•', max(4, strlen($p) - 4)) . substr($p, - 4);
}
try {
    $pdo = db();
    $staff = s2_require_staff_ready($pdo, ['Admin',
        'Manager',
        'Call Centre Staff']);
} catch (Throwable $err) {
    http_response_code(403); ?><!doctype html><html lang="vi"><meta charset="utf-8"><title>Staff login required – demo</title>
    <body style="font:16px system-ui;max-width:640px;margin:4em auto">
    <h1>Cần đăng nhập Staff</h1><p>Đăng nhập Staff Portal trong cùng trình duyệt và đổi mật khẩu tạm trước khi mở công cụ demo này.</p>
    <a href="index.html">Mở PrivateHire Cars</a></body></html><?php exit;
}
$demo = phc_coursework_demo_enabled();
$items = $pdo->query("SELECT b.booking_id,b.booking_channel,b.booking_status,b.pickup_datetime, c.full_name,c.telephone_number,
                            m.registration_no,v.driver_full_name
    FROM bookings b JOIN customers c ON c.customer_id=b.customer_id
    LEFT JOIN minicabs m ON m.minicab_id=b.minicab_id
    LEFT JOIN vehicle_dispatch_profiles v ON v.minicab_id=b.minicab_id
    WHERE b.booking_status IN ('Paid & Confirmed','Telephone Confirmed','Forwarded to Control Centre')
    ORDER BY b.booking_id DESC LIMIT 50")->fetchAll();
$selected = (int)($_GET['booking'] ?? ($items[0]['booking_id'] ?? 0));
$allowed = array_column($items, 'booking_id');
if (!in_array($selected, array_map('intval', $allowed), true)) $selected = (int)($items[0]['booking_id'] ?? 0);
$selectedInfo = null;
foreach ($items as $item) if ((int)$item['booking_id'] === $selected) {
    $selectedInfo = $item;
    break;
}
$jobs = [];
$dispatch = null;
if ($selected > 0) {
    $q = $pdo->prepare("SELECT notification_id,status,provider_reference,last_error,trigger_at,sent_at,body_text
       FROM notification_jobs WHERE booking_id=? AND channel='SMS' ORDER BY notification_id DESC LIMIT 8");
    $q->execute([$selected]);
    $jobs = $q->fetchAll();
    $q = $pdo->prepare('SELECT status,gps_reference,forwarded_at,last_error FROM dispatch_jobs WHERE booking_id=?');
    $q->execute([$selected]);
    $dispatch = $q->fetch() ? : null;
}
$places = landmarks();
$from = (string)($_GET['from'] ?? 'heathrow');
$to = (string)($_GET['to'] ?? 'savoy');
if (!isset ($places[$from])) $from = 'heathrow';
if (!isset ($places[$to]) || $from === $to) $to = $from === 'savoy' ? 'heathrow' : 'savoy';
$distance = route_distance($from, $to);
$csrf = (string)($_SESSION['csrf'] ?? ''); ?><!doctype html>
<html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Coursework Demo — PrivateHire Cars</title>
<style>
:root{font:16px system-ui,Segoe UI,sans-serif;color:#14243e;background:#f4f6fb}*{box-sizing:border-box}
body{max-width:1050px;margin:0 auto;padding:24px}h1{font-size:28px;margin:0 0 8px}h2{font-size:20px;margin:0 0 12px}
p{line-height:1.5}.top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.card{background:white;border:1px solid #e1e7f1;border-radius:16px;padding:22px;margin:16px 0;box-shadow:0 2px 10px #14213d0a}
.tag{display:inline-block;border-radius:18px;padding:6px 10px;font-size:13px;font-weight:700;background:#fdf0cf;color:#8a4b06}
.real{background:#ddf5e8;color:#136136}.muted{color:#546179;font-size:14px}
button,.btn{border:0;border-radius:12px;padding:11px 17px;background:#0e59ca;color:white;font-weight:650;cursor:pointer;font:inherit;text-decoration:none;display:inline-block}
button:disabled{opacity:.5;cursor:wait}.secondary{background:#e8eef8;color:#114681}.danger{color:#9d2d29}
select,input{padding:11px;border:1px solid #aeb9cb;border-radius:10px;min-width:220px;max-width:100%;font:inherit}
.row{display:flex;align-items:end;gap:12px;flex-wrap:wrap;margin:10px 0}label{display:block;font-size:14px;font-weight:600;margin:0 0 5px}
.output{white-space:pre-wrap;overflow-wrap:anywhere;padding:14px;border-radius:12px;background:#f1f4f9;min-height:50px;color:#233451}
.result{padding:12px;background:#e8f7e8;border-radius:12px}.warning{padding:12px;background:#fff3dd;border-radius:12px}
table{width:100%;border-collapse:collapse;font-size:14px}th,td{padding:11px 9px;border-bottom:1px solid #e5eaf3;text-align:left;vertical-align:top;overflow-wrap:anywhere}
@media(max-width:700px){body{padding:12px}.card{padding:16px}th,td{font-size:12px}}
</style></head><body>
<div class="top"><div><h1>PrivateHire Cars — Coursework Demo</h1><p class="muted">Trang kiểm thử riêng; không thay đổi giao diện đặt xe hoặc thanh toán. Staff: <?=demo_e($staff['employee_id'])?></p></div><div><a class="btn secondary" href="index.html">← Website chính</a> <a class="btn secondary" href="sprint2_status.php">Trạng thái hệ thống</a></div></div>
<div class="card"><h2>1. Road routing (mô phỏng)</h2><p><span class="tag">DEMO: 9 địa điểm cố định</span> Khoảng cách ước tính từ bản đồ hiện có, không phải dữ liệu đường đi thực tế. Không thay đổi công thức giá đặt xe.</p>
<form method="get" class="row"><input type="hidden" name="booking" value="<?=$selected?>"><div><label for="from">Pickup</label><select id="from" name="from">
<?php foreach ($places as $key => $place): ?><option value="<?=demo_e($key)?>" <?=$key === $from ? 'selected' : ''?>><?=demo_e($place['name'])?></option><?php endforeach; ?></select></div>
<div><label for="to">Destination</label><select id="to" name="to"><?php foreach ($places as $key => $place): ?><option value="<?=demo_e($key)?>" <?=$key === $to ? 'selected' : ''?>><?=demo_e($place['name'])?></option><?php endforeach; ?></select></div><button>Kiểm tra tuyến demo</button></form>
<p class="result">Khoảng cách mô phỏng: <strong><?=number_format($distance, 2)?> miles</strong></p></div>
<div class="card"><h2>2. Card processor (mô phỏng)</h2><p><span class="tag">SANDBOX: không trừ tiền</span> Website chính đã hỗ trợ kiểm tra thẻ test, mã xác minh sandbox và biên lai SBOX. Không nhập thông tin thẻ ngân hàng thật.</p><p>Test card: <strong>4242 4242 4242 4242</strong> · Expiry: <strong>12/30</strong> · CVV: <strong>123</strong> · Test verification: <strong>123456</strong>.</p><p><a class="btn secondary" href="index.html">Mở website để thanh toán sandbox</a></p></div>
<div class="card"><h2>3. GPS dispatch (mô phỏng) + SMS textbee (gửi thật)</h2>
<?php if ($demo): ?><p class="warning"><span class="tag">GPS SIMULATED</span> Chế độ demo chỉ lưu mã <code>DEMO-GPS-...</code> vào MySQL; không có GPS thực. <span class="tag real">SMS: REAL via textbee</span> khi chạy worker và ứng dụng trên điện thoại.</p>
<?php else: ?><p class="warning danger">Chưa bật demo GPS. Cần đặt file <code>COURSEWORK_DEMO_ON.flag</code> trong thư mục dự án. Không làm thay đổi GPS thật.</p><?php endif; ?>
<?php if ($items): ?>
<form method="get" class="row"><input type="hidden" name="from" value="<?=demo_e($from)?>"><input type="hidden" name="to" value="<?=demo_e($to)?>"><div><label for="booking">Chọn booking đã xác nhận</label><select id="booking" name="booking">
<?php foreach ($items as $item): ?><option value="<?=(int)$item['booking_id']?>" <?=(int)$item['booking_id'] === $selected ? 'selected' : ''?>><?=demo_e(s2_ref($item))?> — <?=demo_e($item['booking_status'])?></option><?php endforeach; ?></select></div><button class="secondary">Xem booking</button></form>
<p>Booking: <strong><?=demo_e(s2_ref($selectedInfo))?></strong> · Khách: <?=demo_e($selectedInfo['full_name'])?> · SĐT (ẩn): <?=demo_e(demo_mask_phone((string)$selectedInfo['telephone_number']))?> · Xe: <?=demo_e((string)($selectedInfo['registration_no'] ?? 'chưa có'))?> · Tài xế: <?=demo_e((string)($selectedInfo['driver_full_name'] ?? 'chưa có'))?></p>
<p>Điều phối: <strong><?=demo_e((string)($dispatch['status'] ?? 'Pending'))?></strong> · Mã GPS: <code><?=demo_e((string)($dispatch['gps_reference'] ?? '—'))?></code></p>
<div class="row"><button type="button" onclick="demoSend('dispatch_forward')" <?=$demo ? '' : 'disabled'?>>Mô phỏng Forward → Control Centre</button>
<button type="button" onclick="demoSend('arrival_event')" <?=$demo ? '' : 'disabled'?>>Mô phỏng ETA 12 phút → GỬI SMS THẬT</button>
<a class="btn secondary" href="?booking=<?=$selected?>&amp;from=<?=demo_e($from)?>&amp;to=<?=demo_e($to)?>">Làm mới trạng thái</a></div>
<p class="muted">Trước khi nhấn SMS, đảm bảo số điện thoại của booking là số ông kiểm soát và Android textbee đang online. Bấm nhiều lần trên cùng booking sẽ không tạo tin SMS trùng từ sự kiện thử nghiệm này.</p>
<div class="output" role="status" id="result">Nhấn một nút để bắt đầu kiểm thử. Không có yêu cầu nào tự gửi SMS khi chỉ mở trang.</div>
<h3>Nhật ký SMS của booking</h3>
<?php if ($jobs): ?><table><thead><tr><th>ID</th><th>Trạng thái</th><th>Mã textbee</th><th>Thời điểm</th><th>Thông báo lỗi</th></tr></thead><tbody>
<?php foreach ($jobs as $job): ?><tr><td><?=(int)$job['notification_id']?></td><td><?=demo_e($job['status'])?></td><td><?=demo_e((string)($job['provider_reference'] ?? 'Chưa gửi'))?></td><td><?=demo_e((string)($job['sent_at'] ?? $job['trigger_at']))?></td><td><?=demo_e((string)($job['last_error'] ?? '—'))?></td></tr><?php endforeach; ?></tbody></table>
<?php else: ?><p class="muted">Chưa có SMS của booking này. Khi mô phỏng ETA, sẽ tạo một job `Queued` và worker gửi thật tới textbee.</p><?php endif; ?>
<?php else: ?><p>Chưa có booking xác nhận. Hãy đặt xe qua website hoặc Telephone Booking trước.</p><?php endif; ?>
</div>
<div class="card"><h2>4. Worker và kiểm tra kết quả</h2><p>Giữ <code>RUN_BACKGROUND_WORKER_WAMP.bat</code> hoạt động khi test. Worker chuyển SMS từ MySQL tới textbee, sau đó cập nhật trạng thái khi điện thoại gửi.</p><p><b>Queued</b> = đang chờ; <b>Sent</b> = textbee báo điện thoại đã gửi; <b>Failed</b> = lỗi; kiểm tra tin nhắn nhận thực tế để chứng minh giao hàng thành công.</p><p class="muted">Không kết luận VISA Check hay GPS thật đã đạt Acceptance Criteria chỉ dựa trên mô phỏng này.</p></div>
<?php if ($items): ?>
<script>
const bookId=<?=json_encode($selected)?>;
const csrf=<?=json_encode($csrf, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
async function demoSend(action){
  if(!bookId)return;
  if(action==='arrival_event' && !confirm('SMS THẬT có thể phát sinh cước trên SIM. Bạn xác nhận số nhận của booking này là của bạn / đã đồng ý nhận SMS test?'))return;
  const display=document.getElementById('result');
  const buttons=document.querySelectorAll('button[onclick]');buttons.forEach(b=>b.disabled=true);
  display.textContent='Đang gửi yêu cầu tới hệ thống điều phối...';
  const data=action==='arrival_event'
    ?{booking_id:bookId,eta_minutes:12,event_id:'coursework-demo-eta12-'+bookId}
    :{booking_id:bookId};
  try {
    const r=await fetch('sprint2_api.php?action='+encodeURIComponent(action), {
      method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data)
    });
    const json=await r.json();
    if(!r.ok||json.ok===false){display.textContent='LỖI: '+(json.error||'HTTP '+r.status);return;}
    if(action==='arrival_event')display.textContent=json.duplicate
      ?'Sự kiện ETA12 của booking này đã tồn tại; không gửi trùng. Hãy dùng booking mới để test lần tiếp theo.'
      :'ĐÃ XẾP SMS VÀO HÀNG ĐỢI (ID '+json.notification_id+'). Hãy bật RUN_BACKGROUND_WORKER_WAMP.bat và kiểm tra điện thoại, sau đó bấm Làm mới trạng thái.';
    else display.textContent=json.simulated
      ?'FORWARDED DEMO THÀNH CÔNG: '+json.gps_reference+'. Không có GPS thật.'
      :'Trạng thái chuyển tiếp: '+json.status+'. '+(json.message||'');
  } catch(e){display.textContent='Lỗi khi gọi API: '+e.message;}
  finally{buttons.forEach(b=>b.disabled=false);}
}
</script>
<?php endif; ?>
</body></html>
