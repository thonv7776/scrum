<?php
declare(strict_types=1);
require dirname(__DIR__).'/sprint2_lib.php';
function check(bool $ok,string $label): void {if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}echo "PASS: $label\n";}
check(s2_luhn('4242424242424242'),'Luhn test Visa');
check(s2_luhn('4000000000000002'),'Luhn bank-decline test card');
check(!s2_luhn('4242424242424241'),'Invalid Luhn');
check(!s2_luhn('424242424242'),'Invalid card length');
check(s2_expiry_ok('12/29'),'Future expiry');
check(!s2_expiry_ok('01/20'),'Expired');
check(!s2_expiry_ok('13/29'),'Invalid month');
check(count(s2_card_errors(['card_name'=>'Person Sample','card_number'=>'4242424242424242','expiry'=>'12/29','cvv'=>'123']))===0,'Valid card form');
check(count(s2_card_errors(['card_name'=>'','card_number'=>'1234','expiry'=>'01/20','cvv'=>'12']))===4,'All invalid fields detected');
check(s2_ref(['booking_channel'=>'Online','booking_id'=>124])==='PHC-000124','Online ref');
check(s2_ref(['booking_channel'=>'Telephone','booking_id'=>124])==='PHC-T-000124','Phone ref');
check(s2_booking_id('PHC-T-000124')===124,'Reference parser');
check(s2_coords('heathrow')!==null&&s2_coords('savoy')!==null,'Dispatch location coordinates');
check(s2_coords('made-up')===null,'Reject unknown dispatch location');
check(str_contains(s2_conflict_sql(),"'Paid & Confirmed'")&&str_contains(s2_conflict_sql(),"'Telephone Confirmed'"),'Fleet conflict includes confirmed bookings');
echo "Sprint 2 pure-logic tests complete. (No database / provider integration implied.)\n";
