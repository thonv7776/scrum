<?php
/** Standalone Sprint 2 API, using Sprint 1 sessions/database without editing Sprint 1. */
declare(strict_types=1);
require __DIR__.'/sprint2_lib.php';
start_secure_session();
$action=(string)($_GET['action']??'bootstrap');
try {
 $pdo=db();
 if($action==='bootstrap'){
  $u=null;$staff=null;
  if(!empty($_SESSION['customer_id'])){
   $q=$pdo->prepare("SELECT customer_id,full_name,email FROM customers WHERE customer_id=? AND account_status='Active'");$q->execute([$_SESSION['customer_id']]);$u=$q->fetch()?:null;
  }
  if(!empty($_SESSION['s2_staff_id'])){
   $q=$pdo->prepare("SELECT staff_id,employee_id,full_name,work_email,phone,role,status,must_change_password,session_version,created_at,last_login_at,deactivated_at FROM staff_accounts WHERE staff_id=?");$q->execute([$_SESSION['s2_staff_id']]);$s=$q->fetch();
   if($s && $s['status']==='Active' && (int)$s['session_version']===(int)($_SESSION['s2_staff_version']??0))$staff=s2_sanitize_staff($s);
   else unset($_SESSION['s2_staff_id'],$_SESSION['s2_staff_version']);
  }
  response(['ok'=>true,'csrf'=>$_SESSION['csrf'],'customer'=>$u,'staff'=>$staff,'sandbox'=>!getenv('PHC_GATEWAY_URL')]);
 }
 if($_SERVER['REQUEST_METHOD']!=='POST')failure('POST required',405);
 validate_csrf();$d=body();
 // Authentication actions: all identity and authorization checks happen server-side.
 if($action==='staff_login'){
  $q=$pdo->prepare('SELECT * FROM staff_accounts WHERE work_email=?');$q->execute([strtolower(trim((string)($d['email']??'')))]);$s=$q->fetch();
  if(!$s || !password_verify((string)($d['password']??''),$s['password_hash']) || $s['status']!=='Active') failure('Incorrect credentials or account deactivated.',401);
  session_regenerate_id(true);$_SESSION['s2_staff_id']=(int)$s['staff_id'];$_SESSION['s2_staff_version']=(int)$s['session_version'];$_SESSION['csrf']=bin2hex(random_bytes(32));
  $pdo->prepare('UPDATE staff_accounts SET last_login_at=NOW() WHERE staff_id=?')->execute([$s['staff_id']]);
  response(['ok'=>true,'csrf'=>$_SESSION['csrf'],'staff'=>s2_sanitize_staff($s)]);
 }
 if($action==='staff_logout'){
  unset($_SESSION['s2_staff_id'],$_SESSION['s2_staff_version']);$_SESSION['csrf']=bin2hex(random_bytes(32));response(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
 }
 if($action==='staff_change_password'){
  $s=s2_staff($pdo);$old=(string)($d['old_password']??'');$new=(string)($d['new_password']??'');
  $q=$pdo->prepare('SELECT password_hash FROM staff_accounts WHERE staff_id=?');$q->execute([$s['staff_id']]);
  if(!password_verify($old,(string)$q->fetchColumn()))failure('Incorrect temporary password.');
  if(strlen($new)<10 || !preg_match('/[A-Z]/',$new) || !preg_match('/[0-9]/',$new))failure('New password needs 10+ characters, an uppercase letter and a digit.');
  $pdo->prepare('UPDATE staff_accounts SET password_hash=?,must_change_password=0,session_version=session_version+1 WHERE staff_id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$s['staff_id']]);
  $_SESSION['s2_staff_version']++;response(['ok'=>true]);
 }
 // Customer endpoints. The same phc_session and customer_id as Sprint 1 are reused.
 if(in_array($action,['my_bookings','payment_context','pay','receipt','arrival_log'],true)){
  $owner=require_auth();
  if($action==='my_bookings'){
   $q=$pdo->prepare('SELECT booking_id FROM bookings WHERE customer_id=? ORDER BY booking_id DESC LIMIT 100');$q->execute([$owner]);$rows=[];
   foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)$rows[]=s2_booking_public(s2_query_booking($pdo,(int)$id,$owner));
   response(['ok'=>true,'bookings'=>$rows]);
  }
  if($action==='payment_context'){
   $id=(int)($d['booking_id']??($_SESSION['draft_booking_id']??0));
   if(!$id){$q=$pdo->prepare("SELECT booking_id FROM bookings WHERE customer_id=? AND booking_status='Held' AND slot_hold_until>NOW() ORDER BY booking_id DESC LIMIT 1");$q->execute([$owner]);$id=(int)$q->fetchColumn();}
   if(!$id)failure('No active Sprint 1 held booking. Return to Sprint 1 and confirm a trip.',404);
   $b=s2_query_booking($pdo,$id,$owner);
   if(!in_array($b['booking_status'],['Held','Paid & Confirmed','Forwarded to Control Centre'],true))failure('This booking has not reached checkout.',422);
   response(['ok'=>true,'booking'=>s2_booking_public($b)]);
  }
  if($action==='pay'){
   $errs=s2_card_errors($d);if($errs)failure('Please correct the card fields.',422,$errs);
   $card=preg_replace('/\s+/','',(string)$d['card_number']);
   $id=(int)($d['booking_id']??($_SESSION['draft_booking_id']??0));if(!$id)failure('Please create and hold a Sprint 1 booking first.');
   $pdo->beginTransaction();
   try{
    $b=s2_query_booking($pdo,$id,$owner,true);
    if($b['booking_status']!=='Held' || !$b['slot_hold_until'] || strtotime($b['slot_hold_until'])<=time())throw new DomainException('The booking slot has expired. Return to Sprint 1 to reserve a fresh time.');
    if(!$b['minicab_id'] || !$b['pickup_datetime'] || (float)$b['final_payable_fare']<=0)throw new DomainException('Booking details are incomplete.');
    // Serialize checkout with telephone reservations using the minicab row lock.
    $carLock=$pdo->prepare('SELECT minicab_id FROM minicabs WHERE minicab_id=? FOR UPDATE');$carLock->execute([$b['minicab_id']]);
    if(!$carLock->fetchColumn())throw new DomainException('The selected minicab is no longer available.');
    // Recheck against all confirmed bookings at commit, including telephone bookings.
    $q=$pdo->prepare("SELECT COUNT(*) FROM bookings WHERE booking_id<>? AND minicab_id=? AND pickup_datetime<? AND estimated_end_datetime>? AND booking_status IN ('Paid & Confirmed','Telephone Confirmed','Forwarded to Control Centre')");
    $q->execute([$id,$b['minicab_id'],$b['estimated_end_datetime'],$b['pickup_datetime']]);
    if((int)$q->fetchColumn()>0)throw new DomainException('This vehicle already has a confirmed journey at that time.');
    $g=s2_gateway_charge($card,(string)$d['expiry'],(string)$d['cvv'],(string)$d['card_name'],(float)$b['final_payable_fare'],s2_ref($b),(string)($d['verification']??''));
    if(!$g['ok'])throw new DomainException($g['error']);
    $pdo->prepare("INSERT INTO payments(booking_id,transaction_id,card_last4,amount_gbp,payment_status,gateway_mode) VALUES (?,?,?,?, 'Approved',?)")
       ->execute([$id,$g['txn'],substr($card,-4),$b['final_payable_fare'],$g['mode']]);
    $opt=!empty($d['opt_in_email'])?1:0;
    $pdo->prepare("UPDATE bookings SET booking_status='Paid & Confirmed',slot_hold_until=NULL,opt_in_email=? WHERE booking_id=?") ->execute([$opt,$id]);
    $b=s2_query_booking($pdo,$id,$owner);s2_enqueue_confirmation($pdo,$b);s2_enqueue_dispatch($pdo,$id);
    $pdo->commit();
    // Queue to worker (not network calls during checkout); receipt returned immediately.
    response(['ok'=>true,'receipt'=>s2_receipt($pdo,$id,$owner),'notification'=>'queued']);
   }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  }
  if($action==='receipt'){
   $id=isset($d['ref'])?s2_booking_id((string)$d['ref']):(int)($d['booking_id']??0);
   response(['ok'=>true,'receipt'=>s2_receipt($pdo,$id,$owner)]);
  }
  if($action==='arrival_log'){
   $id=(int)($d['booking_id']??0);$b=s2_query_booking($pdo,$id,$owner);
   $q=$pdo->prepare("SELECT n.status,n.trigger_at,n.sent_at,n.provider_reference,n.body_text,n.last_error,a.eta_minutes FROM notification_jobs n LEFT JOIN arrival_events a ON a.notification_id=n.notification_id WHERE n.booking_id=? AND n.channel='SMS' ORDER BY n.notification_id DESC LIMIT 10");$q->execute([$id]);
   response(['ok'=>true,'booking'=>s2_booking_public($b),'vehicle'=>['plate'=>$b['registration_no'],'model'=>$b['make_and_model'],'colour'=>$b['vehicle_colour'],'driver'=>$b['driver_full_name']], 'mobile'=>$b['customer_phone'], 'log'=>$q->fetchAll()]);
  }
 }
 // Staff-only operations (role scoped per endpoint).
 if($action==='staff_create'){
  s2_require_staff_ready($pdo,['Admin']);
  $id=strtoupper(trim((string)($d['id']??'')));$name=trim((string)($d['name']??''));$email=strtolower(trim((string)($d['email']??'')));
  $phone=trim((string)($d['phone']??''));$role=(string)($d['role']??'');$pw=(string)($d['password']??'');$errors=[];
  if(!preg_match('/^EMP-\d{4}$/D',$id))$errors['crId']='Employee ID must be EMP- followed by 4 digits.';
  if(strlen($name)<2 || strlen($name)>100)$errors['crName']='Enter a full name (2–100 characters).';
  if(!valid_email($email))$errors['crMail']='Enter a valid work email.';
  if(!valid_phone($phone))$errors['crPhone']='Phone must be 10–11 digits.';
  if(!in_array($role,['Call Centre Staff','Manager'],true))$errors['crRole']='Choose Call Centre Staff or Manager.';
  if(strlen($pw)<8)$errors['crPw']='Temporary password needs at least 8 characters.';
  if($errors)failure('Correct the staff account fields.',422,$errors);
  try{$pdo->prepare("INSERT INTO staff_accounts(employee_id,full_name,work_email,phone,role,password_hash,status,must_change_password) VALUES (?,?,?,?,?,?,'Active',1)")->execute([$id,$name,$email,$phone,$role,password_hash($pw,PASSWORD_DEFAULT)]);}
  catch(PDOException $e){if(($e->errorInfo[1]??0)==1062)failure('Employee ID or work email already exists.',409);throw $e;}
  $mail=s2_transport('Email',$email,'Welcome to PrivateHire Cars','Your staff account '.$id.' has been created. Please sign in and change your temporary password immediately. Ask your administrator for the temporary password through a secure channel.');
  response(['ok'=>true,'staff_id'=>(int)$pdo->lastInsertId(),'onboarding'=>$mail['ok']?'submitted':'failed','sandbox'=>($mail['provider']??'')==='local-outbox-only']);
 }
 if($action==='staff_list'){
  s2_require_staff_ready($pdo,['Admin']);
  $page=max(1,(int)($d['page']??1));$per=in_array((int)($d['per']??10),[10,20],true)?(int)$d['per']:10;
  $total=(int)$pdo->query('SELECT COUNT(*) FROM staff_accounts')->fetchColumn();$page=min($page,max(1,(int)ceil($total/$per)));
  $q=$pdo->prepare('SELECT staff_id,employee_id,full_name,work_email,phone,role,status,must_change_password,created_at,last_login_at,deactivated_at FROM staff_accounts ORDER BY staff_id LIMIT ? OFFSET ?');$q->bindValue(1,$per,PDO::PARAM_INT);$q->bindValue(2,($page-1)*$per,PDO::PARAM_INT);$q->execute();
  response(['ok'=>true,'staff'=>array_map('s2_sanitize_staff',$q->fetchAll()),'page'=>$page,'per'=>$per,'total'=>$total]);
 }
 if($action==='staff_profile'){
  s2_require_staff_ready($pdo,['Admin']);$id=(string)($d['id']??'');
  $q=$pdo->prepare('SELECT staff_id,employee_id,full_name,work_email,phone,role,status,must_change_password,created_at,last_login_at,deactivated_at FROM staff_accounts WHERE employee_id=?');$q->execute([$id]);$s=$q->fetch();if(!$s)failure('Staff account not found.',404);
  response(['ok'=>true,'staff'=>s2_sanitize_staff($s)]);
 }
 if($action==='staff_edit'){
  s2_require_staff_ready($pdo,['Admin']);$id=(string)($d['id']??'');$name=trim((string)($d['name']??''));$mail=strtolower(trim((string)($d['email']??'')));$phone=trim((string)($d['phone']??''));$errs=[];
  if(strlen($name)<2 || strlen($name)>100)$errs['edName']='Full name required.';
  if(!valid_email($mail))$errs['edMail']='Work email format is invalid.';
  if(!valid_phone($phone))$errs['edPhone']='Phone must be 10–11 digits.';
  if($errs)failure('Correct the staff details.',422,$errs);
  try{$q=$pdo->prepare('UPDATE staff_accounts SET full_name=?,work_email=?,phone=? WHERE employee_id=?');$q->execute([$name,$mail,$phone,$id]);}
  catch(PDOException $e){if(($e->errorInfo[1]??0)==1062)failure('Another staff member uses that work email.',409,['edMail'=>'Email already exists.']);throw $e;}
  if(!$q->rowCount()){$q=$pdo->prepare('SELECT 1 FROM staff_accounts WHERE employee_id=?');$q->execute([$id]);if(!$q->fetchColumn())failure('Staff account not found.',404);}
  response(['ok'=>true]);
 }
 if($action==='staff_deactivate'){
  $self=s2_require_staff_ready($pdo,['Admin']);$id=(string)($d['id']??'');
  if($id===$self['employee_id'])failure('You cannot deactivate your own Admin account.',403);
  $q=$pdo->prepare("UPDATE staff_accounts SET status='Deactivated',session_version=session_version+1,deactivated_at=NOW() WHERE employee_id=? AND status='Active'");$q->execute([$id]);if(!$q->rowCount())failure('No active account found for that ID.',404);
  response(['ok'=>true,'message'=>'Staff account deactivated and sessions revoked.']);
 }
 if($action==='customer_search'){
  s2_require_staff_ready($pdo,['Call Centre Staff','Manager','Admin']);$query=trim((string)($d['q']??''));
  if(strlen($query)<2)response(['ok'=>true,'customers'=>[]]);
  $q=$pdo->prepare('SELECT customer_id,full_name,email,residential_address,telephone_number FROM customers WHERE full_name LIKE ? OR email LIKE ? OR telephone_number LIKE ? ORDER BY customer_id LIMIT 20');$t='%'.$query.'%';$q->execute([$t,$t,$t]);response(['ok'=>true,'customers'=>$q->fetchAll()]);
 }
 if($action==='fleet'){
  s2_require_staff_ready($pdo,['Call Centre Staff','Manager','Admin']);
  $date=(string)($d['date']??'');$time=(string)($d['time']??'');
  $rows=s2_fleet($pdo,$date,$time,(string)($d['size']??'all'),(string)($d['zone']??'all'),(int)($d['exclude']??0),(int)($d['duration']??90));
  $total=(int)$pdo->query('SELECT COUNT(*) FROM minicabs WHERE is_active=1')->fetchColumn();
  response(['ok'=>true,'vehicles'=>$rows,'total'=>$total]);
 }
 if($action==='phone_quote'){
  s2_require_staff_ready($pdo,['Call Centre Staff','Manager','Admin']);
  $x=quote_inputs($pdo,['service_id'=>$d['service_id']??null,'category_id'=>$d['category_id']??null,'pickup_key'=>$d['pickup_key']??null,'destination_key'=>$d['destination_key']??null,'passenger_count'=>$d['passenger_count']??null,'hire_days'=>$d['hire_days']??1,'flight_number'=>$d['flight_number']??'','include_driver'=>$d['include_driver']??false]);
  $s=$x['service'];$c=$x['category'];$m=$x['fare']['distance_miles'];$f=fare($s,$c,$m,(int)($x['days']?:1),$x['include_driver'],'Telephone');response(['ok'=>true,'quote'=>$f]);
 }
 if($action==='phone_create'){
  $staff=s2_require_staff_ready($pdo,['Call Centre Staff','Manager','Admin']);
  if(empty($d['verified']) || !is_array($d['verified']) || count(array_filter($d['verified']))<3)failure('Complete the three caller identity verification checks.');
  $mode=(string)($d['caller_mode']??'existing');
  $x=quote_inputs($pdo,['service_id'=>$d['service_id']??null,'category_id'=>$d['category_id']??null,'pickup_key'=>$d['pickup_key']??null,'destination_key'=>$d['destination_key']??null,'passenger_count'=>$d['passenger_count']??null,'hire_days'=>$d['hire_days']??1,'flight_number'=>$d['flight_number']??'','include_driver'=>$d['include_driver']??false]);
  $start=pickup_datetime((string)($d['date']??''),(string)($d['time']??''));$end=$start->modify('+'.estimated_duration_minutes((float)$x['fare']['distance_miles'],(int)$x['service_id'],(int)($x['days']?:1)).' minutes');
  $qfare=fare($x['service'],$x['category'],(float)$x['fare']['distance_miles'],(int)($x['days']?:1),$x['include_driver'],'Telephone');
  $vehicle=(int)($d['minicab_id']??0);if(!$vehicle)failure('Select an available vehicle.');
  $pdo->beginTransaction();
  try{
   $pdo->prepare('SELECT minicab_id FROM minicabs WHERE category_id=? AND is_active=1 ORDER BY minicab_id FOR UPDATE')->execute([$x['category_id']]);
   $available=s2_fleet($pdo,$start->format('Y-m-d'),$start->format('H:i'),'all','all',0,estimated_duration_minutes((float)$x['fare']['distance_miles'],(int)$x['service_id'],(int)($x['days']?:1)));
   if(!array_filter($available,fn($v)=>(int)$v['minicab_id']===$vehicle && (int)$v['category_id']===$x['category_id']))throw new DomainException('Vehicle has been reserved. Select another vehicle.');
   if($mode==='existing'){
    $owner=(int)($d['customer_id']??0);$q=$pdo->prepare('SELECT customer_id,telephone_number FROM customers WHERE customer_id=?');$q->execute([$owner]);$u=$q->fetch();if(!$u)throw new DomainException('Select an existing customer.');
    if(!hash_equals((string)$u['telephone_number'],(string)($d['verified_phone']??'')))throw new DomainException('Caller phone verification does not match the customer record.');
   }elseif($mode==='new'){
    $c=$d['customer']??[];if(!is_array($c))throw new DomainException('Complete the new caller profile.');
    $name=trim((string)($c['name']??''));$phone=trim((string)($c['phone']??''));$addr=trim((string)($c['address']??''));$email=strtolower(trim((string)($c['email']??'')));
    if(strlen($name)<2||!valid_phone($phone)||strlen($addr)<5||!valid_email($email))throw new DomainException('New customer requires name, email, address and 10–11 digit phone.');
    $pdo->prepare("INSERT INTO customers(full_name,email,password_hash,residential_address,telephone_number,account_status) VALUES (?,?,?,?,?,'Pending Verification')")
        ->execute([$name,$email,password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT),$addr,$phone]);$owner=(int)$pdo->lastInsertId();
   }else throw new DomainException('Select an existing or first-time caller.');
   $q=$pdo->prepare("INSERT INTO bookings(customer_id,service_id,category_id,minicab_id,flight_number,hire_days,pickup_location,destination,pickup_key,destination_key,distance_miles,passenger_count,pickup_datetime,estimated_end_datetime,booking_channel,base_total_fare,online_discount_15,final_payable_fare,booking_status,include_driver,driver_fee,created_by_staff_id,opt_in_email) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Telephone',?,0,?,'Telephone Confirmed',?,?,?,0)");
   $q->execute([$owner,$x['service_id'],$x['category_id'],$vehicle,$x['flight'],$x['days'],$x['pickup'],$x['destination'],$x['pickup_key'],$x['destination_key'],$x['fare']['distance_miles'],$x['pax'],$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),$qfare['base_total'],$qfare['final_total'],$x['include_driver']?1:0,$qfare['driver_fee'],$staff['staff_id']]);
   $id=(int)$pdo->lastInsertId();s2_enqueue_dispatch($pdo,$id);$pdo->commit();response(['ok'=>true,'ref'=>s2_ref(['booking_id'=>$id,'booking_channel'=>'Telephone']),'booking_id'=>$id,'fare'=>$qfare,'status'=>'Telephone Confirmed','dispatch_status'=>'Pending']);
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 }
 if($action==='dispatch_list'){
  s2_require_staff_ready($pdo,['Call Centre Staff','Manager','Admin']);
  $q=$pdo->query("SELECT j.dispatch_id,j.booking_id,j.status AS dispatch_status,j.forwarded_at,j.gps_reference,j.last_error,b.booking_channel FROM dispatch_jobs j JOIN bookings b ON b.booking_id=j.booking_id ORDER BY j.dispatch_id DESC LIMIT 100");
  $rows=[];foreach($q->fetchAll() as $r){$b=s2_query_booking($pdo,(int)$r['booking_id']);$payload=null;$bad=null;try{$payload=s2_dispatch_payload($b);}catch(DomainException $e){$bad=$e->getMessage();}$rows[]=array_merge(s2_booking_public($b),['dispatch_status'=>$r['dispatch_status'],'forwarded_at'=>$r['forwarded_at'],'gps_reference'=>$r['gps_reference'],'dispatch_error'=>$r['last_error'],'dispatch_bad'=>$bad,'payload'=>$payload]);}
  response(['ok'=>true,'jobs'=>$rows]);
 }
 if($action==='dispatch_forward'){
  s2_require_staff_ready($pdo,['Call Centre Staff','Manager','Admin']);
  $out=s2_forward_job($pdo,(int)($d['booking_id']??0));
  if($out['status']==='Failed')failure('GPS provider failed: '.$out['error'],503);
  response(['ok'=>true]+$out);
 }
 if($action==='arrival_event'){
  // Staff/manual demo events require staff auth; production GPS webhook should use its own secret via dedicated endpoint.
  s2_require_staff_ready($pdo,['Call Centre Staff','Manager','Admin']);$id=(int)($d['booking_id']??0);$eta=(int)($d['eta_minutes']??-1);$event=trim((string)($d['event_id']??''));
  if($event===''||strlen($event)>120)failure('An event ID is required.');
  if($eta!==0 && ($eta<10||$eta>15))failure('SMS triggers only at ETA 10–15 minutes or arrival (0).');
  $b=s2_query_booking($pdo,$id);if(!in_array($b['booking_status'],['Paid & Confirmed','Telephone Confirmed','Forwarded to Control Centre'],true))failure('Booking is not confirmed.');
  if(!$b['registration_no']||!$b['driver_full_name']||!valid_phone((string)$b['customer_phone']))failure('SMS requires verified mobile, vehicle and driver details.');
  $text='PrivateHire Cars: Your minicab is arriving. Plate '.$b['registration_no'].', '.$b['vehicle_colour'].' '.$b['make_and_model'].', driver '.$b['driver_full_name'].', ETA '.$eta.' min. Pickup: '.$b['pickup_location'].'.';
  $pdo->beginTransaction();try{
   $q=$pdo->prepare('INSERT IGNORE INTO arrival_events(booking_id,provider_event_id,eta_minutes) VALUES (?,?,?)');$q->execute([$id,$event,$eta]);
   if($q->rowCount()===0){$pdo->commit();response(['ok'=>true,'duplicate'=>true]);}
   $pdo->prepare("INSERT INTO notification_jobs(booking_id,channel,event_key,recipient,body_text,status,trigger_at) VALUES (?,'SMS',?,?,?,'Queued',NOW())")
    ->execute([$id,'sms-'.$event,$b['customer_phone'],$text]);$notif=(int)$pdo->lastInsertId();
   $pdo->prepare('UPDATE arrival_events SET notification_id=? WHERE provider_event_id=?')->execute([$notif,$event]);$pdo->commit();response(['ok'=>true,'queued'=>true,'notification_id'=>$notif]);
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 }
 failure('Unknown Sprint 2 action.',404);
}catch(DomainException $e){failure($e->getMessage(),422);}
catch(PDOException $e){error_log('Sprint2 DB: '.$e->getMessage());failure('Database operation failed. Check migration and duplicate data.',503);}
catch(Throwable $e){error_log('Sprint2 error: '.$e->getMessage());failure('Sprint 2 server error.',500);}
