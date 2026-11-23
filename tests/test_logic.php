<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib.php';
$tested=0;
function test($ok,string $label):void{global $tested;$tested++;if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}echo "PASS: $label\n";}
function throws(callable $fn):bool{try{$fn();return false;}catch(DomainException $e){return true;}}
$s=[
 ['service_id'=>1,'base_flag_fall_rate'=>'5.00','airport_surcharge'=>'0.00','daily_hire_rate'=>null],
 ['service_id'=>2,'base_flag_fall_rate'=>'8.00','airport_surcharge'=>'5.00','daily_hire_rate'=>null],
 ['service_id'=>3,'base_flag_fall_rate'=>'10.00','airport_surcharge'=>'12.00','daily_hire_rate'=>null],
 ['service_id'=>4,'base_flag_fall_rate'=>'15.00','airport_surcharge'=>'0.00','daily_hire_rate'=>'140.00'],
];
$c=['per_mile_rate'=>'2.75'];
$f=fare($s[0],$c,18.5,1,true);
test($f['distance_fare']===50.88,'Half-penny mileage rounded to two decimals');
test($f['driver_fee']===20.0 && $f['base_total']===75.88 && $f['discount']===11.38 && $f['final_total']===64.5,'Local trip includes driver and 15% discount');
$f=fare($s[1],$c,18.5,1,false);test($f['base_total']===63.88 && $f['final_total']===54.30,'Airport hotel surcharge priced');
$f=fare($s[2],$c,18.5,1,false);test($f['base_total']===72.88 && $f['final_total']===61.95,'Meet and greet priced');
$f=fare($s[3],$c,18.5,2,true);test($f['base_total']===335.0 && $f['driver_fee']===40.0 && $f['final_total']===284.75,'Daily hire addon charged for each day');
$f=fare($s[2],$c,18.5,1,true,'Telephone');test($f['discount']===0.0 && $f['final_total']===$f['base_total'],'Telephone fare not eligible for discount');
test(route_distance('heathrow','savoy')===18.50,'Sample map route estimate matches existing demo');
test(route_distance('savoy','heathrow')===18.50,'Reverse route same mileage');
test(throws(fn()=>route_distance('heathrow','heathrow')),'Same address rejected');
test(throws(fn()=>route_distance('unknown','savoy')),'Unsupported map location rejected');
test(estimated_duration_minutes(18.5,1,1)>30,'Journey duration has scheduling buffer');
test(estimated_duration_minutes(18.5,4,2)===1200,'Daily hire blocks both 10-hour days');
test(valid_email('test@example.com')&&!valid_email('bad-email'),'Email syntax validation');
test(valid_phone('07700900123') && !valid_phone('077-00900123') && !valid_phone('12345'),'Telephone 10–11 numeric validation');
$err=registration_errors(['full_name'=>'','email'=>'bad','residential_address'=>'','telephone_number'=>'abc','password'=>'123']);test(count($err)===5,'Required registration fields validated');
$t=new DateTimeImmutable('+70 minutes',new DateTimeZone('Europe/London'));
test(pickup_datetime($t->format('Y-m-d'),$t->format('H:i')) instanceof DateTimeImmutable,'Future booking passes lead time');
$t=new DateTimeImmutable('+5 minutes',new DateTimeZone('Europe/London'));
test(throws(fn()=>pickup_datetime($t->format('Y-m-d'),$t->format('H:i'))),'Lead time below 30 min rejected');
test(throws(fn()=>pickup_datetime('2026-02-31','12:30')),'Invalid calendar date rejected');
echo "ALL $tested UNIT TESTS PASSED\n";
