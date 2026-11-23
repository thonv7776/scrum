<?php
declare(strict_types=1);
require __DIR__.'/lib.php';
start_secure_session();
$action=(string)($_GET['action']??'bootstrap');
try {
    $pdo=db();
    if ($action==='bootstrap') {
        $services=$pdo->query('SELECT service_id,service_name,short_tagline,scope_description,base_flag_fall_rate,airport_surcharge,daily_hire_rate FROM transport_services ORDER BY service_id')->fetchAll();
        $cats=$pdo->query('SELECT category_id,category_name,max_passengers,max_luggage,per_mile_rate FROM minicab_categories ORDER BY category_id')->fetchAll();
        $links=$pdo->query('SELECT service_id,category_id FROM service_minicab_categories')->fetchAll();
        $user=null;
        if (isset($_SESSION['customer_id'])) {
           $q=$pdo->prepare('SELECT customer_id,full_name,email,account_status FROM customers WHERE customer_id=?');$q->execute([$_SESSION['customer_id']]);$user=$q->fetch()?:null;
           if (!$user || $user['account_status']!=='Active') {unset($_SESSION['customer_id']);$user=null;}
        }
        $flash=$_SESSION['flash']??null;unset($_SESSION['flash']);
        response(['ok'=>true,'csrf'=>$_SESSION['csrf'],'user'=>$user,'services'=>$services,'categories'=>$cats,'service_categories'=>$links,'flash'=>$flash,'server_time'=>date('c'),'driver_fee_per_trip'=>(float)cfg()['driver_extra_per_trip_gbp'],'driver_fee_per_day'=>(float)cfg()['driver_extra_per_day_gbp']]);
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') failure('POST required.',405);
    validate_csrf();$d=body();
    if ($action==='register') {
        $errs=registration_errors($d);
        $email=strtolower(trim((string)($d['email']??'')));
        if (!$errs) { $q=$pdo->prepare('SELECT 1 FROM customers WHERE email=?');$q->execute([$email]);if ($q->fetchColumn())$errs['email']='An account with this email address already exists.'; }
        if ($errs) failure('Please correct the highlighted registration fields.',422,$errs);
        try {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO customers (full_name,email,password_hash,residential_address,telephone_number,account_status) VALUES (?,?,?,?,?,\'Pending Verification\')')->execute([trim($d['full_name']),$email,password_hash((string)$d['password'],PASSWORD_BCRYPT),trim($d['residential_address']),trim($d['telephone_number'])]);
            $id=(int)$pdo->lastInsertId();$pdo->commit();
        } catch(PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (($e->errorInfo[1]??0)==1062) failure('Email is already registered.',422,['email'=>'An account with this email address already exists.']);
            throw $e;
        }
        $mail=send_verification_email($pdo,$id,$email);
        response(['ok'=>true,'message'=>'Account created as Pending Verification. Activate it with the email link.','mail'=>$mail,'email'=>$email]);
    }
    if ($action==='resend') {
        $email=strtolower(trim((string)($d['email']??'')));
        if (!valid_email($email)) failure('Enter your registered email address.',422,['email'=>'Invalid email address.']);
        $q=$pdo->prepare('SELECT customer_id,account_status FROM customers WHERE email=?');$q->execute([$email]);$customer=$q->fetch();
        if ($customer && $customer['account_status']==='Pending Verification') {
            // rate limit repeated requests; delay new verification tokens for 60 seconds
            $q=$pdo->prepare('SELECT created_at FROM verification_tokens WHERE customer_id=? ORDER BY token_id DESC LIMIT 1');$q->execute([$customer['customer_id']]);$last=$q->fetchColumn();
            if ($last && time()-strtotime($last)<60) failure('Please wait 60 seconds before requesting another link.',429);
            $mail=send_verification_email($pdo,(int)$customer['customer_id'],$email);
        } else $mail=['delivery'=>'not_requested'];
        response(['ok'=>true,'message'=>'If this account requires activation, a new link has been requested.','mail'=>$mail]);
    }
    if ($action==='login') {
        $email=strtolower(trim((string)($d['email']??'')));$pass=(string)($d['password']??'');
        $q=$pdo->prepare('SELECT customer_id,full_name,email,password_hash,account_status FROM customers WHERE email=? LIMIT 1');$q->execute([$email]);$user=$q->fetch();
        if (!$user || !password_verify($pass,$user['password_hash'])) failure('Incorrect email or password',401);
        if ($user['account_status']!=='Active') failure('Your account is Pending Verification. Please check your email inbox.',403);
        session_regenerate_id(true);
        $_SESSION['customer_id']=(int)$user['customer_id'];$_SESSION['csrf']=bin2hex(random_bytes(32));unset($_SESSION['draft_booking_id']);
        response(['ok'=>true,'csrf'=>$_SESSION['csrf'],'user'=>['customer_id'=>(int)$user['customer_id'],'full_name'=>$user['full_name'],'email'=>$user['email']]]);
    }
    if ($action==='logout') {
        $_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));
        response(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
    }
    $owner=require_auth();
    if ($action==='select_service') {
        $id=filter_var($d['service_id']??null,FILTER_VALIDATE_INT);
        $q=$pdo->prepare('SELECT service_id FROM transport_services WHERE service_id=?');$q->execute([$id]);if (!$id||!$q->fetch()) throw new DomainException('Select a valid service.');
        $flight=trim((string)($d['flight_number']??''));$days=filter_var($d['hire_days']??1,FILTER_VALIDATE_INT);
        if ($id===3 && ($flight==='' || strlen($flight)>20)) throw new DomainException('Flight number and terminal are required (maximum 20 characters).');
        if ($id===4 && (!$days || $days<1||$days>30)) throw new DomainException('Daily hire must be 1–30 days.');
        $draftId=(int)($_SESSION['draft_booking_id']??0);
        if ($draftId) {
            $exists=$pdo->prepare('SELECT booking_status FROM bookings WHERE booking_id=? AND customer_id=?');
            $exists->execute([$draftId,$owner]);
            if ($exists->fetchColumn() !== 'Draft') $draftId=0;
        }
        if ($draftId) {
            $q=$pdo->prepare("UPDATE bookings SET service_id=?,category_id=NULL,minicab_id=NULL,flight_number=?,hire_days=?,pickup_location=NULL,destination=NULL,pickup_key=NULL,destination_key=NULL,distance_miles=0,passenger_count=1,pickup_datetime=NULL,estimated_end_datetime=NULL,booking_status='Draft',slot_hold_until=NULL,base_total_fare=0,online_discount_15=0,final_payable_fare=0,include_driver=0,driver_fee=0 WHERE booking_id=? AND customer_id=?");
            $q->execute([$id,$id===3?$flight:null,$id===4?$days:null,$draftId,$owner]);
        }
        if (!$draftId) {
            $pdo->prepare("INSERT INTO bookings (customer_id,service_id,flight_number,hire_days,booking_channel,booking_status) VALUES (?,?,?,?, 'Online','Draft')")->execute([$owner,$id,$id===3?$flight:null,$id===4?$days:null]);
            $draftId=(int)$pdo->lastInsertId();
        }
        $_SESSION['draft_booking_id']=$draftId;
        response(['ok'=>true,'booking_id'=>$draftId]);
    }
    if ($action==='quote') {
        $x=quote_inputs($pdo,$d);response(['ok'=>true,'quote'=>$x['fare']]);
    }
    if ($action==='save_route') {
        $draft=find_draft($pdo,$owner);
        if ((int)($d['service_id']??0)!==(int)$draft['service_id']) throw new DomainException('The selected service has changed. Please continue from the Service step.');
        $x=quote_inputs($pdo,$d);$f=$x['fare'];
        $pdo->prepare("UPDATE bookings SET category_id=?,minicab_id=NULL,flight_number=?,hire_days=?,pickup_location=?,destination=?,pickup_key=?,destination_key=?,distance_miles=?,passenger_count=?,pickup_datetime=NULL,estimated_end_datetime=NULL,booking_status='Draft',slot_hold_until=NULL,base_total_fare=?,online_discount_15=?,final_payable_fare=?,include_driver=?,driver_fee=? WHERE booking_id=? AND customer_id=?")
            ->execute([$x['category_id'],$x['flight'],$x['days'],$x['pickup'],$x['destination'],$x['pickup_key'],$x['destination_key'],$f['distance_miles'],$x['pax'],$f['base_total'],$f['discount'],$f['final_total'],$x['include_driver']?1:0,$f['driver_fee'],$draft['booking_id'],$owner]);
        response(['ok'=>true,'quote'=>$f,'booking_id'=>$draft['booking_id']]);
    }
    if ($action==='availability') {
        $draft=find_draft($pdo,$owner);
        if (!$draft['category_id'] || !$draft['destination_key']) throw new DomainException('Enter your journey details before checking availability.');
        $start=pickup_datetime((string)($d['date']??''),(string)($d['time']??''));
        $duration=estimated_duration_minutes((float)$draft['distance_miles'],(int)$draft['service_id'],(int)($draft['hire_days']?:1));
        $end=$start->modify('+'.$duration.' minutes');
        $vehicles=available_minicabs($pdo,(int)$draft['category_id'],$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),(int)$draft['booking_id']);
        $pdo->prepare("UPDATE bookings SET pickup_datetime=?,estimated_end_datetime=?,minicab_id=NULL,booking_status='Draft',slot_hold_until=NULL WHERE booking_id=? AND customer_id=?")
            ->execute([$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),$draft['booking_id'],$owner]);
        $alternatives=[];
        if (!$vehicles) {
            foreach ([30,60,90,-30,-60] as $offset) {
               $st=$start->modify(($offset>=0?'+':'').$offset.' minutes');
               if ($st->getTimestamp()<time()+1800) continue;
               $en=$st->modify('+'.$duration.' minutes');
               if (available_minicabs($pdo,(int)$draft['category_id'],$st->format('Y-m-d H:i:s'),$en->format('Y-m-d H:i:s'),(int)$draft['booking_id'])) $alternatives[]=['date'=>$st->format('Y-m-d'),'time'=>$st->format('H:i')];
               if (count($alternatives)>=3)break;
            }
        }
        response(['ok'=>true,'available'=>(bool)$vehicles,'vehicle'=>$vehicles?$vehicles[0]:null,'duration_minutes'=>$duration,'start'=>$start->format('Y-m-d H:i'),'alternatives'=>$alternatives]);
    }
    if ($action==='hold') {
        if (empty($d['confirmed'])) throw new DomainException('Tick Confirm Schedule before proceeding.');
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        try {
            $draft=find_draft($pdo,$owner);
            if ($draft['booking_status']==='Held' && strtotime((string)$draft['slot_hold_until'])>time()) {
                $pdo->commit();response(['ok'=>true,'booking_id'=>(int)$draft['booking_id'],'hold_until'=>$draft['slot_hold_until'],'hold_until_iso'=>(new DateTimeImmutable($draft['slot_hold_until'],new DateTimeZone('Europe/London')))->format(DATE_ATOM),'final_total'=>(float)$draft['final_payable_fare']]);
            }
            if (!$draft['pickup_datetime'] || !$draft['estimated_end_datetime'] || !$draft['category_id']) throw new DomainException('Check availability before confirming your schedule.');
            if (strtotime($draft['pickup_datetime'])<time()+1800) throw new DomainException('Pickup must be at least 30 minutes from now.');
            $available=available_minicabs($pdo,(int)$draft['category_id'],$draft['pickup_datetime'],$draft['estimated_end_datetime'],(int)$draft['booking_id'],true);
            if (!$available) throw new DomainException('This slot has just been reserved. Please select another time.');
            $vehicle=$available[0];
            $pdo->prepare("UPDATE bookings SET minicab_id=?,booking_status='Held',slot_hold_until=DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE booking_id=? AND customer_id=?")
                ->execute([$vehicle['minicab_id'],$draft['booking_id'],$owner]);
            $pdo->commit();
            $q=$pdo->prepare('SELECT slot_hold_until,final_payable_fare FROM bookings WHERE booking_id=?');$q->execute([$draft['booking_id']]);$r=$q->fetch();
            response(['ok'=>true,'booking_id'=>(int)$draft['booking_id'],'vehicle'=>$vehicle,'hold_until'=>$r['slot_hold_until'],'hold_until_iso'=>(new DateTimeImmutable($r['slot_hold_until'],new DateTimeZone('Europe/London')))->format(DATE_ATOM),'final_total'=>(float)$r['final_payable_fare']]);
        } catch(Throwable $e) {if ($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    failure('Unknown action.',404);
} catch(DomainException $e) { failure($e->getMessage()); }
catch(PDOException $e) { error_log('PHC database error: '.$e->getMessage()); failure('Database error. Check MySQL credentials and import setup in README.',503); }
catch(Throwable $e) {error_log('PHC unexpected error: '.$e->getMessage());failure('An unexpected server error occurred.',500);}
