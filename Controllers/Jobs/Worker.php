<?php

/** Background job PROCESSOR (called by the three-second notification worker loop). */
declare(strict_types = 1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require dirname(__DIR__, 2) . '/Models/Bootstrap.php';
try {
    $pdo = db();
    // Isolate the stages: failed textbee polling must not block GPS forwarding,
    // and a GPS provider failure must not stop queued email/SMS delivery.
    $errors = [];
    $runStage = static function (string $name, callable $operation) use ($pdo, &$errors): array {
        try {
            return $operation();
        } catch (Throwable $e) {
            // Never continue to another stage while the connection holds an
            // unfinished transaction or row lock from the failed operation.
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $name;
            error_log('PHC worker ' . $name . ': ' . $e->getMessage());
            return [];
        }
    };

    $results = $runStage('notifications', static fn(): array => s2_process_notifications($pdo, 20));
    $sms = $runStage('textbee_status', static fn(): array => s2_process_textbee_pending($pdo, 20));
    $gps = $runStage('dispatch', static fn(): array => s2_process_dispatch_queue($pdo, 10));
    $demoArrivals = $runStage('demo_arrivals', static fn(): array => phc_demo_process_arrivals($pdo, 10));

    echo json_encode([
        'processed' => $results,
        'textbee_status' => $sms,
        'dispatch' => $gps,
        'demo_arrivals' => $demoArrivals,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if ($errors !== []) exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Worker failure: ' . $e->getMessage() . "\n");
    exit(1);
}
