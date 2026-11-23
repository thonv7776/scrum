<?php
/** Background job PROCESSOR (called explicitly or by Windows Task Scheduler every minute). */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only');}
require __DIR__.'/sprint2_lib.php';
try {
 $pdo=db();$results=s2_process_notifications($pdo,20);$gps=s2_process_dispatch_queue($pdo,10);
 echo json_encode(['processed'=>$results,'dispatch'=>$gps],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).'\n';
}catch(Throwable $e){fwrite(STDERR,'Worker failure: '.$e->getMessage()."\n");exit(1);}
