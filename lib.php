<?php
declare(strict_types=1);

date_default_timezone_set('Europe/London');

function cfg(): array { static $c; return $c ??= require __DIR__ . '/config.php'; }
function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('phc_session');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off','httponly'=>true,'samesite'=>'Lax']);
    session_start();
    if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function db(): PDO {
    static $db = null;
    if ($db instanceof PDO) return $db;
    $c = cfg();
    $db = new PDO('mysql:host='.$c['db_host'].';port='.$c['db_port'].';dbname='.$c['db_name'].';charset=utf8mb4', $c['db_user'], $c['db_password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    $offset=date('P'); $db->exec("SET time_zone = '".$offset."'");
    return $db;
}
function response(array $value, int $status=200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function failure(string $message, int $status=422, array $fields=[]): never { response(['ok'=>false,'error'=>$message,'fields'=>$fields],$status); }
function require_auth(): int { if (empty($_SESSION['customer_id'])) failure('Please sign in to continue booking.',401); return (int)$_SESSION['customer_id']; }
function validate_csrf(): void { if (!hash_equals((string)($_SESSION['csrf']??''), (string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) failure('Your session expired. Reload the page and try again.',419); }
function body(): array { $raw = file_get_contents('php://input'); $j = json_decode($raw?:'{}',true); if (!is_array($j)) failure('Invalid JSON payload.'); return $j; }
function money(float $n): float { return round($n,2,PHP_ROUND_HALF_UP); }
function valid_email(string $email): bool { return (bool)filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email)<=150; }
function valid_phone(string $phone): bool { return (bool)preg_match('/^[0-9]{10,11}$/D',$phone); }
function registration_errors(array $d): array {
    $r=[];
    if (strlen(trim((string)($d['full_name']??'')))<2 || strlen(trim((string)($d['full_name']??'')))>100) $r['full_name']='Enter your full name (2–100 characters).';
    if (!valid_email(trim((string)($d['email']??'')))) $r['email']='Enter a valid email address.';
    if (trim((string)($d['residential_address']??''))==='' || strlen(trim((string)($d['residential_address']??'')))>255) $r['residential_address']='Residential address is required (up to 255 characters).';
    if (!valid_phone(trim((string)($d['telephone_number']??'')))) $r['telephone_number']='Enter 10–11 digits (numbers only).';
    if (strlen((string)($d['password']??''))<8) $r['password']='Password must have at least 8 characters.';
    return $r;
}
function landmarks(): array { return [
 'heathrow'=>['name'=>'Heathrow Airport, Terminal 5','x'=>95,'y'=>168],
 'paddington'=>['name'=>'Paddington Station','x'=>280,'y'=>125],
 'baker'=>['name'=>'221B Baker Street, Marylebone','x'=>332,'y'=>108],
 'westminster'=>['name'=>'Westminster Abbey, Central London','x'=>365,'y'=>168],
 'savoy'=>['name'=>'The Savoy Hotel, Strand','x'=>405,'y'=>142],
 'kingscross'=>['name'=>"King's Cross St. Pancras",'x'=>398,'y'=>86],
 'canary'=>['name'=>'Canary Wharf Financial District','x'=>518,'y'=>156],
 'cityairport'=>['name'=>'London City Airport (LCY)','x'=>605,'y'=>152],
 'gatwick'=>['name'=>'Gatwick Airport, South Terminal','x'=>395,'y'=>278],
 ]; }
function route_distance(string $a,string $b): float {
    $m=landmarks();
    if (!isset($m[$a],$m[$b])) throw new DomainException('Please choose a supported pickup and destination from the location lists.');
    if ($a===$b) throw new DomainException('Pickup and destination must be different.');
    if (($a==='heathrow' && $b==='savoy') || ($b==='heathrow' && $a==='savoy')) return 18.50;
    return money(max(1.8, hypot($m[$a]['x']-$m[$b]['x'], $m[$a]['y']-$m[$b]['y'])*0.0595));
}
// Prototype approximate mileage based on the supplied interactive map. NOT a routing-provider result.
function fare(array $s,array $c,float $miles,int $days,bool $includeDriver,string $channel='Online'): array {
    $id=(int)$s['service_id'];
    $flag=money((float)$s['base_flag_fall_rate']);
    $distance=$id===4?0.0:money($miles*(float)$c['per_mile_rate']);
    $daily=$id===4?money($days*(float)$s['daily_hire_rate']):0.0;
    $surcharge=$id===4?0.0:money((float)$s['airport_surcharge']);
    $driver=$includeDriver?money($id===4?$days*cfg()['driver_extra_per_day_gbp']:cfg()['driver_extra_per_trip_gbp']):0.0;
    $base=money($flag+$distance+$daily+$surcharge+$driver);
    $discount=$channel==='Online'?money($base*cfg()['online_discount_rate']):0.0;
    return ['flag_fall'=>$flag,'distance_fare'=>$distance,'daily_fare'=>$daily,'airport_surcharge'=>$surcharge,'driver_fee'=>$driver,'base_total'=>$base,'discount'=>$discount,'final_total'=>money($base-$discount),'distance_miles'=>$miles,'per_mile_rate'=>(float)$c['per_mile_rate'],'daily_rate'=>(float)($s['daily_hire_rate']??0)];
}
function estimated_duration_minutes(float $miles,int $service,int $days): int { return $service===4? max(60,$days*10*60) : max(30,(int)ceil($miles*2.95)+15); }
function pickup_datetime(string $date,string $time): DateTimeImmutable {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || !preg_match('/^\d{2}:\d{2}$/',$time)) throw new DomainException('Select a valid pickup date and time.');
    $v=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$date.' '.$time,new DateTimeZone('Europe/London'));
    if (!$v || $v->format('Y-m-d H:i')!==$date.' '.$time) throw new DomainException('Select a valid pickup date and time.');
    if ($v->getTimestamp() < time()+30*60) throw new DomainException('Pickup must be at least 30 minutes from now (London time).');
    return $v;
}
function current_base_url(): string {
    if (cfg()['base_url']) return rtrim(cfg()['base_url'],'/');
    $secure=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';
    $host=$_SERVER['HTTP_HOST']??'localhost';
    if (!preg_match('/^[a-zA-Z0-9.\-:\[\]]+$/',$host)) $host='localhost';
    $path=str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/'));
    return ($secure?'https':'http').'://'.$host.($path==='/'?'':$path);
}
function send_verification_email(PDO $db,int $id,string $email): array {
    $token=bin2hex(random_bytes(32)); $hash=hash('sha256',$token);
    $db->prepare('UPDATE verification_tokens SET is_used=1 WHERE customer_id=? AND is_used=0')->execute([$id]);
    $db->prepare('INSERT INTO verification_tokens (customer_id,token_hash,expires_at,is_used) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR),0)')->execute([$id,$hash]);
    $url=current_base_url().'/verify.php?token='.urlencode($token);
    $subject='Activate your PrivateHire Cars account';
    $message="Welcome to PrivateHire Cars!\n\nVerify your account within 24 hours:\n$url\n\nThis link works once.\n";
    $from=preg_replace('/[^A-Za-z0-9@._+\-]/','',cfg()['mail_from']);
    $sent=@mail($email,$subject,$message,'From: '.$from."\r\nContent-Type: text/plain; charset=UTF-8");
    $out=['delivery'=>$sent?'submitted_to_mail_server':'not_configured'];
    if (cfg()['development_mode']) {
       if (!is_dir(__DIR__.'/storage')) mkdir(__DIR__.'/storage',0700,true);
       file_put_contents(sys_get_temp_dir().'/phc_sprint1_mail.log',date('c').' '.$email.' '.$url."\n",FILE_APPEND|LOCK_EX);
       $out['development_link']=$url;
    }
    return $out;
}
function find_draft(PDO $db,int $owner): array {
    $id=(int)($_SESSION['draft_booking_id']??0);
    if (!$id) throw new DomainException('Select a service to start your booking.');
    $q=$db->prepare('SELECT * FROM bookings WHERE booking_id=? AND customer_id=? LIMIT 1'); $q->execute([$id,$owner]);
    $r=$q->fetch(); if (!$r) throw new DomainException('Your booking draft was not found. Start again.');
    return $r;
}
function quote_inputs(PDO $db,array $d): array {
    $id=filter_var($d['service_id']??null,FILTER_VALIDATE_INT); $cat=filter_var($d['category_id']??null,FILTER_VALIDATE_INT);
    if (!$id || !$cat) throw new DomainException('Select one service and minicab size.');
    $q=$db->prepare('SELECT s.* FROM transport_services s INNER JOIN service_minicab_categories sc ON sc.service_id=s.service_id AND sc.category_id=? WHERE s.service_id=?');$q->execute([$cat,$id]);$s=$q->fetch();
    $q=$db->prepare('SELECT * FROM minicab_categories WHERE category_id=?');$q->execute([$cat]);$c=$q->fetch();
    if (!$s || !$c) throw new DomainException('The selected vehicle size is not supported by this service.');
    $p=filter_var($d['passenger_count']??null,FILTER_VALIDATE_INT);
    if (!$p || $p<1 || $p>8) throw new DomainException('Passenger count must be between 1 and 8.');
    if ($p>(int)$c['max_passengers']) throw new DomainException('Vehicle seating capacity exceeded. Please choose a larger minicab.');
    $a=trim((string)($d['pickup_key']??'')); $b=trim((string)($d['destination_key']??'')); $miles=route_distance($a,$b);
    $days=($id==4)?filter_var($d['hire_days']??null,FILTER_VALIDATE_INT):1;
    if ($id===4 && (!$days || $days<1 || $days>30)) throw new DomainException('Hire days must be 1–30.');
    $flight=trim((string)($d['flight_number']??''));
    if ($id===3 && ($flight==='' || strlen($flight)>20)) throw new DomainException('Enter your flight number and terminal (up to 20 characters).');
    $include=filter_var($d['include_driver']??false,FILTER_VALIDATE_BOOLEAN);
    $f=fare($s,$c,$miles,(int)$days,$include);
    return ['service'=>$s,'category'=>$c,'service_id'=>(int)$id,'category_id'=>(int)$cat,'pax'=>$p,'pickup_key'=>$a,'destination_key'=>$b,'pickup'=>$a ? landmarks()[$a]['name']:'','destination'=>$b ? landmarks()[$b]['name']:'','flight'=>$id===3?$flight:null,'days'=>$id===4?(int)$days:null,'include_driver'=>$include,'fare'=>$f];
}
function available_minicabs(PDO $db,int $category,string $start,string $end,int $excludeId=0,bool $lock=false): array {
    // caller holding transaction must lock all category vehicles before conflict check when reserving
    if ($lock) {
        $lockq=$db->prepare('SELECT minicab_id FROM minicabs WHERE category_id=? AND is_active=1 ORDER BY minicab_id FOR UPDATE');
        $lockq->execute([$category]);$lockq->fetchAll();
    }
    $q=$db->prepare("SELECT m.* FROM minicabs m WHERE m.category_id=? AND m.is_active=1 AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.minicab_id=m.minicab_id AND b.booking_status='Held' AND b.slot_hold_until>NOW() AND b.pickup_datetime < ? AND b.estimated_end_datetime > ? AND b.booking_id <> ?) ORDER BY m.minicab_id");
    $q->execute([$category,$end,$start,$excludeId]);return $q->fetchAll();
}
