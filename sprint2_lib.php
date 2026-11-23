<?php
/** Sprint 2 additive library. No Sprint 1 PHP files are edited. */
declare(strict_types=1);
require_once __DIR__.'/lib.php';

function s2_ref(array $b): string {return ($b['booking_channel']==='Telephone'?'PHC-T-':'PHC-').str_pad((string)$b['booking_id'],6,'0',STR_PAD_LEFT);}
function s2_booking_id(string $ref): int {if (!preg_match('/^PHC-(?:T-)?(\d{1,10})$/D',$ref,$m)) throw new DomainException('Invalid booking reference.');return (int)$m[1];}
function s2_staff(PDO $pdo,array $roles=['Admin','Manager','Call Centre Staff']): array {
    $id=(int)($_SESSION['s2_staff_id']??0);$v=(int)($_SESSION['s2_staff_version']??0);
    if (!$id) failure('Staff sign-in is required.',401);
    $q=$pdo->prepare('SELECT staff_id,employee_id,full_name,work_email,phone,role,status,must_change_password,session_version,created_at,last_login_at,deactivated_at FROM staff_accounts WHERE staff_id=?');$q->execute([$id]);$staff=$q->fetch();
    if (!$staff || $staff['status']!=='Active' || (int)$staff['session_version']!==$v) {unset($_SESSION['s2_staff_id'],$_SESSION['s2_staff_version']);failure('Your staff session is no longer valid.',401);}
    if (!in_array($staff['role'],$roles,true)) failure('You do not have permission to perform this action.',403);
    return $staff;
}
function s2_require_staff_ready(PDO $pdo,array $roles=['Admin','Manager','Call Centre Staff']): array {
    $s=s2_staff($pdo,$roles);
    if ((int)$s['must_change_password']) failure('Change your temporary password before using the staff portal.',403);
    return $s;
}
function s2_sanitize_staff(array $s): array {return [
 'staff_id'=>(int)$s['staff_id'],'id'=>$s['employee_id'],'name'=>$s['full_name'],'email'=>$s['work_email'],'phone'=>$s['phone'],
 'role'=>$s['role'],'status'=>$s['status'],'must_change_password'=>(bool)$s['must_change_password'],
 'created'=>$s['created_at'],'login'=>$s['last_login_at'],'deactAt'=>$s['deactivated_at']??null,
 'self'=>isset($_SESSION['s2_staff_id']) && (int)$s['staff_id']===(int)$_SESSION['s2_staff_id']
];}
function s2_luhn(string $digits): bool {
 if (!preg_match('/^\d{16}$/D',$digits))return false;
 $sum=0;$double=false;
 for ($i=strlen($digits)-1;$i>=0;$i--){$n=(int)$digits[$i];if($double){$n*=2;if($n>9)$n-=9;}$sum+=$n;$double=!$double;}
 return $sum>0 && $sum%10===0;
}
function s2_expiry_ok(string $expiry): bool {
 if(!preg_match('/^(0[1-9]|1[0-2])\/(\d\d)$/D',$expiry,$m))return false;
 $end=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',sprintf('20%02d-%02d-01 00:00:00',(int)$m[2],(int)$m[1]),new DateTimeZone('Europe/London'));
 return $end !== false && $end->modify('first day of next month')->getTimestamp()>time();
}
function s2_card_errors(array $d): array {
 $e=[];
 if(strlen(trim((string)($d['card_name']??'')))<2)$e['cardName']='Enter the cardholder name.';
 if(!s2_luhn(preg_replace('/\s+/','',(string)($d['card_number']??''))))$e['cardNumber']='Enter a valid 16-digit card number (Luhn check).';
 if(!s2_expiry_ok((string)($d['expiry']??'')))$e['cardExp']='Enter a non-expired date MM/YY.';
 if(!preg_match('/^\d{3}$/D',(string)($d['cvv']??'')))$e['cardCvv']='CVV must contain exactly 3 digits.';
 return $e;
}
function s2_gateway_charge(string $card,string $expiry,string $cvv,string $name,float $amount,string $ref,string $challenge): array {
 $url=trim((string)(getenv('PHC_GATEWAY_URL')?:''));
 if($url===''){
  // COURSEWORK SANDBOX. This is NOT VISA Check or a real bank transaction.
  if($challenge!=='approved')return ['ok'=>false,'error'=>'Sandbox bank verification was not approved.'];
  if($card==='4000000000000002')return ['ok'=>false,'error'=>'Sandbox bank declined this test card.'];
  if(!in_array($card,['4242424242424242','5555555555554444'],true))return ['ok'=>false,'error'=>'Sandbox accepts only the documented 16-digit test card (4242 4242 4242 4242).'];
  return ['ok'=>true,'txn'=>'SBOX-'.strtoupper(bin2hex(random_bytes(8))),'mode'=>'Sandbox'];
 }
 if(!str_starts_with($url,'https://'))throw new DomainException('Live gateway URL must use HTTPS.');
 if(!extension_loaded('curl'))throw new DomainException('PHP cURL extension is required for live gateway.');
 $token=(string)getenv('PHC_GATEWAY_TOKEN');if($token==='')throw new DomainException('Live gateway credentials have not been configured.');
 // Generic adapter: must be aligned with actual PCI-compliant, tokenising processor before live deployment.
 // Never pass raw card data directly to an unverified/non-PCI gateway.
 throw new DomainException('Live gateway adapter requires the payment provider\'s official tokenisation integration. This coursework build supports SANDBOX only.');
}
function s2_query_booking(PDO $pdo,int $id,int $owner=0,bool $lock=false): array {
 $q=$pdo->prepare('SELECT b.*,c.full_name AS customer_name,c.email AS customer_email,c.telephone_number AS customer_phone,s.service_name,m.registration_no,m.make_and_model,m.vehicle_colour,k.category_name,k.max_passengers, v.driver_full_name,v.zone FROM bookings b JOIN customers c ON c.customer_id=b.customer_id JOIN transport_services s ON s.service_id=b.service_id LEFT JOIN minicabs m ON m.minicab_id=b.minicab_id LEFT JOIN minicab_categories k ON k.category_id=b.category_id LEFT JOIN vehicle_dispatch_profiles v ON v.minicab_id=b.minicab_id WHERE b.booking_id=?'.($owner?' AND b.customer_id=?':'').($lock?' FOR UPDATE':''));
 $q->execute($owner?[$id,$owner]:[$id]);$b=$q->fetch();if(!$b)failure('Booking not found or access denied.',404);return $b;
}
function s2_booking_public(array $b): array {return [
 'id'=>(int)$b['booking_id'],'ref'=>s2_ref($b),'customer'=>$b['customer_name'],'email'=>$b['customer_email'],
 'service'=>$b['service_name'],'pickup'=>$b['pickup_location'],'dest'=>$b['destination'],'date_time'=>$b['pickup_datetime'],'size'=>$b['category_name'],
 'pax'=>(int)$b['passenger_count'],'base'=>(float)$b['base_total_fare'],'discount'=>(float)$b['online_discount_15'],
 'total'=>(float)$b['final_payable_fare'],'channel'=>$b['booking_channel'],'status'=>$b['booking_status'],
 'hold_until'=>$b['slot_hold_until'],'hold_until_iso'=>$b['slot_hold_until']?(new DateTimeImmutable($b['slot_hold_until'],new DateTimeZone('Europe/London')))->format(DATE_ATOM):null,'vehicle'=>$b['registration_no']??null,'email_status'=>$b['email_status'], 'opt_in_email'=>(bool)$b['opt_in_email']
];}
function s2_receipt(PDO $pdo,int $id,int $customer): array {
 $b=s2_query_booking($pdo,$id,$customer);
 $q=$pdo->prepare("SELECT transaction_id,card_last4,payment_method,paid_at,amount_gbp,gateway_mode FROM payments WHERE booking_id=? AND payment_status='Approved'");$q->execute([$id]);$p=$q->fetch();
 if(!$p)failure('A paid receipt is not available for this booking.',404);
 return array_merge(s2_booking_public($b),['txn'=>$p['transaction_id'],'card_last4'=>$p['card_last4'],'method'=>$p['payment_method'],'paid_at'=>$p['paid_at'],'gateway_mode'=>$p['gateway_mode'],'paid'=>(float)$p['amount_gbp']]);
}
function s2_conflict_sql(): string {
 return "b.minicab_id=m.minicab_id AND b.booking_id<>? AND b.pickup_datetime<? AND b.estimated_end_datetime>? AND ((b.booking_status='Held' AND b.slot_hold_until>NOW()) OR b.booking_status IN ('Paid & Confirmed','Telephone Confirmed','Forwarded to Control Centre'))";
}
function s2_fleet(PDO $pdo,string $date,string $time,string $size='all',string $zone='all',int $exclude=0,int $duration=90): array {
 $start=pickup_datetime($date,$time);$end=$start->modify('+'.$duration.' minutes');
 $sql='SELECT m.minicab_id,m.registration_no,m.make_and_model,m.vehicle_colour,k.category_id,k.category_name,k.max_passengers,v.zone,v.operational_status FROM minicabs m JOIN minicab_categories k ON k.category_id=m.category_id LEFT JOIN vehicle_dispatch_profiles v ON v.minicab_id=m.minicab_id WHERE m.is_active=1 AND COALESCE(v.operational_status,\'Available\')=\'Available\' AND NOT EXISTS (SELECT 1 FROM bookings b WHERE '.s2_conflict_sql().')';
 $params=[$exclude,$end->format('Y-m-d H:i:s'),$start->format('Y-m-d H:i:s')];
 if($size!=='all'){$sql.=' AND k.category_name LIKE ?';$params[]='%'.$size.'%';}
 if($zone!=='all'){$sql.=' AND v.zone=?';$params[]=$zone;}
 $sql.=' ORDER BY m.minicab_id';$q=$pdo->prepare($sql);$q->execute($params);return $q->fetchAll();
}
function s2_coords(string $key): ?array {
 $m=['heathrow'=>[51.4700,-0.4543],'paddington'=>[51.5154,-0.1755],'baker'=>[51.5238,-0.1585],'westminster'=>[51.4994,-0.1273],'savoy'=>[51.5101,-0.1204],'kingscross'=>[51.5308,-0.1238],'canary'=>[51.5054,-0.0235],'cityairport'=>[51.5048,0.0495],'gatwick'=>[51.1537,-0.1821]];
 return $m[$key]??null;
}
function s2_dispatch_payload(array $b): array {
 $a=s2_coords((string)($b['pickup_key']??''));$z=s2_coords((string)($b['destination_key']??''));
 if(!$a || !$z || empty($b['registration_no']) || empty($b['customer_phone']) || empty($b['pickup_datetime']))throw new DomainException('Verified caller, vehicle, both location coordinates and schedule are required before dispatch.');
 return ['booking_reference'=>s2_ref($b),'customer'=>['full_name'=>$b['customer_name'],'phone'=>$b['customer_phone']], 'pickup'=>['name'=>$b['pickup_location'],'lat'=>$a[0],'lng'=>$a[1]],'destination'=>['name'=>$b['destination'],'lat'=>$z[0],'lng'=>$z[1]],'scheduled_at'=>$b['pickup_datetime'],'passenger_count'=>(int)$b['passenger_count'],'size'=>$b['category_name'],'vehicle_plate'=>$b['registration_no']];
}
function s2_enqueue_dispatch(PDO $pdo,int $id): void {
 $pdo->prepare("INSERT INTO dispatch_jobs(booking_id,status) VALUES (?, 'Pending') ON DUPLICATE KEY UPDATE booking_id=VALUES(booking_id)")->execute([$id]);
}
function s2_enqueue_confirmation(PDO $pdo,array $b): void {
 if(!(int)$b['opt_in_email']){$pdo->prepare("UPDATE bookings SET email_status='Not requested' WHERE booking_id=?")->execute([$b['booking_id']]);return;}
 $text="PrivateHire Cars booking confirmation\n\nReference: ".s2_ref($b)."\nService: ".$b['service_name']."\nPickup: ".$b['pickup_location']."\nDestination: ".$b['destination']."\nDate/time (London): ".$b['pickup_datetime']."\nMinicab size: ".$b['category_name']."\nTotal paid: GBP ".number_format((float)$b['final_payable_fare'],2)."\nCancellation/amendment: request changes at least 24 hours before scheduled pickup.\n";
 $pdo->prepare("INSERT INTO notification_jobs(booking_id,channel,event_key,recipient,subject,body_text,status) VALUES (?,'Email',?,?,?,?,'Queued') ON DUPLICATE KEY UPDATE notification_id=notification_id")
 ->execute([$b['booking_id'],'confirm-'.$b['booking_id'],$b['customer_email'],'PrivateHire Cars booking '.s2_ref($b),$text]);
 $pdo->prepare("UPDATE bookings SET email_status='Queued' WHERE booking_id=?")->execute([$b['booking_id']]);
}
function s2_transport(string $channel,string $to,string $subject,string $message): array {
 if($channel==='Email'){
  if(getenv('PHC_DEVELOPMENT')!=='0'){
   // Store a clearly labelled development message. No email delivered to an inbox.
   $path=__DIR__.'/storage/sprint2_outbox.log';file_put_contents($path,date('c')." [EMAIL SANDBOX] to=$to\n".$message."\n---\n",FILE_APPEND|LOCK_EX);
   return ['ok'=>true,'provider'=>'local-outbox-only'];
  }
  $from=preg_replace('/[^a-zA-Z0-9@._+\-]/','',(string)cfg()['mail_from']);
  $ok=@mail($to,$subject,$message,'From: '.$from."\r\nContent-Type: text/plain; charset=UTF-8");
  return ['ok'=>$ok,'provider'=>$ok?'php-mail-submitted':'','error'=>$ok?'':'Mail transport rejected message'];
 }
 $url=(string)getenv('PHC_SMS_URL');$token=(string)getenv('PHC_SMS_TOKEN');
 if(!$url||!$token)return ['ok'=>false,'error'=>'SMS gateway not configured; no SMS was sent.'];
 if(!str_starts_with($url,'https://'))return ['ok'=>false,'error'=>'SMS endpoint must use HTTPS.'];
 if(!extension_loaded('curl'))return ['ok'=>false,'error'=>'cURL not available.'];
 $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['to'=>$to,'message'=>$message]),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true]);
 $out=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
 return ['ok'=>$out!==false&&$code>=200&&$code<300,'provider'=>'sms-http-'.$code,'error'=>$err?:('SMS provider returned HTTP '.$code)];
}
function s2_process_notifications(PDO $pdo,int $limit=10): array {
 $limit=max(1,min($limit,100));$done=[];
 for($i=0;$i<$limit;$i++){
  $pdo->beginTransaction();
  $q=$pdo->query("SELECT * FROM notification_jobs WHERE status IN ('Queued','Failed') AND attempts<3 AND due_at<=NOW() ORDER BY due_at,notification_id LIMIT 1 FOR UPDATE");$job=$q->fetch();
  if(!$job){$pdo->commit();break;}
  $pdo->prepare("UPDATE notification_jobs SET status='Queued',attempts=attempts+1,due_at=DATE_ADD(NOW(), INTERVAL 60 SECOND) WHERE notification_id=?")->execute([$job['notification_id']]);$pdo->commit();
  $t=s2_transport($job['channel'],$job['recipient'],(string)$job['subject'],$job['body_text']);
  $ok=$t['ok'];$status=$ok?'Sent':'Failed';
  $pdo->prepare('UPDATE notification_jobs SET status=?,sent_at=IF(?=\'Sent\',NOW(),NULL),provider_reference=?,last_error=? WHERE notification_id=?')
    ->execute([$status,$status,$t['provider']??null,$ok?null:substr((string)($t['error']??'Delivery failed'),0,400),$job['notification_id']]);
  if($job['channel']==='Email')$pdo->prepare('UPDATE bookings SET email_status=?,email_retry_count=? WHERE booking_id=?')->execute([$status,(int)$job['attempts']+1,$job['booking_id']]);
  $done[]=['id'=>(int)$job['notification_id'],'status'=>$status,'mode'=>$t['provider']??null];
 }
 return $done;
}

/** Send one confirmed booking to the real GPS endpoint. Never report 'Forwarded' without HTTP acceptance. */
function s2_forward_job(PDO $pdo,int $id): array {
 $pdo->beginTransaction();
 try {
  $b=s2_query_booking($pdo,$id,0,true);
  if(!in_array($b['booking_status'],['Paid & Confirmed','Telephone Confirmed','Forwarded to Control Centre'],true))throw new DomainException('Only confirmed bookings can be dispatched.');
  $payload=s2_dispatch_payload($b);
  $pdo->prepare("INSERT IGNORE INTO dispatch_jobs(booking_id,status) VALUES (?,'Pending')")->execute([$id]);
  $q=$pdo->prepare('SELECT * FROM dispatch_jobs WHERE booking_id=? FOR UPDATE');$q->execute([$id]);$job=$q->fetch();
  if(in_array($job['status'],['Forwarded','Synced'],true)){$pdo->commit();return ['status'=>$job['status'],'already_forwarded'=>true];}
  $url=trim((string)getenv('PHC_GPS_URL'));$token=(string)getenv('PHC_GPS_TOKEN');
  if($url===''){
   $pdo->prepare("UPDATE dispatch_jobs SET status='Pending',payload_json=?,last_error='GPS endpoint not configured; retained in queue' WHERE booking_id=?")->execute([json_encode($payload),$id]);
   $pdo->commit();return ['status'=>'Pending','message'=>'Queued for GPS dispatch; configure PHC_GPS_URL to forward.'];
  }
  if(!str_starts_with($url,'https://')||$token===''||!extension_loaded('curl'))throw new DomainException('GPS endpoint HTTPS/token/cURL is not fully configured.');
  $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token,'Idempotency-Key: dispatch-'.$id],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true]);
  $reply=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
  if($reply===false||$code<200||$code>=300){
   $msg=substr($error?:'GPS provider HTTP '.$code,0,300);
   $pdo->prepare("UPDATE dispatch_jobs SET status='Failed',attempts=attempts+1,payload_json=?,last_error=? WHERE booking_id=?")->execute([json_encode($payload),$msg,$id]);
   $pdo->commit();return ['status'=>'Failed','error'=>$msg];
  }
  $parsed=json_decode((string)$reply,true);$ref=substr((string)(is_array($parsed)?($parsed['reference']??$parsed['id']??''):'') ,0,120);
  $pdo->prepare("UPDATE dispatch_jobs SET status='Forwarded',forwarded_at=NOW(),attempts=attempts+1,gps_reference=?,payload_json=?,last_error=NULL WHERE booking_id=?")->execute([$ref,json_encode($payload),$id]);
  $pdo->prepare("UPDATE bookings SET booking_status='Forwarded to Control Centre',forwarded_at=NOW() WHERE booking_id=?")->execute([$id]);
  $pdo->commit();return ['status'=>'Forwarded','gps_reference'=>$ref];
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function s2_process_dispatch_queue(PDO $pdo,int $limit=10): array {
 if(!getenv('PHC_GPS_URL'))return []; // No provider is configured: leave jobs Pending.
 $limit=min(30,max(1,$limit));$q=$pdo->query("SELECT booking_id FROM dispatch_jobs WHERE status IN ('Pending','Failed') AND attempts<3 ORDER BY dispatch_id LIMIT $limit");
 $items=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){try{$items[]=['id'=>$id]+s2_forward_job($pdo,(int)$id);}catch(Throwable $e){$items[]=['id'=>$id,'status'=>'Failed','error'=>$e->getMessage()];}}
 return $items;
}
