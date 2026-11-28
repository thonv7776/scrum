<?php

/** Database installer: safe on existing installs; only creates schema for a NEW/EMPTY DB. */
declare(strict_types = 1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
$config = require dirname(__DIR__, 2) . '/Models/Config/App.php';
$database = (string)$config['db_name'];
if (!preg_match('/^[A-Za-z0-9_]+$/D', $database)) {
    fwrite(STDERR, "Invalid database name.\n");
    exit(2);
}
try {
    $pdo = new PDO('mysql:host=' . $config['db_host'] . ';port=' . $config['db_port'] . ';charset=utf8mb4', $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . $database . '`');
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    $required = ['customers',
        'transport_services',
        'minicab_categories',
        'minicabs',
        'service_minicab_categories',
        'staff_accounts',
        'bookings',
        'verification_tokens',
        'payments',
        'vehicle_dispatch_profiles',
        'dispatch_jobs',
        'notification_jobs',
        'arrival_events'];
    if ($tables !== []) {
        $missing = array_diff($required, $tables);
        if ($missing) {
            throw new RuntimeException('Database already contains tables but is missing: ' . implode(', ', $missing) . '. Backup your data and use the upgrade script. No data was changed.');
        }
        $requiredColumns = ['booking_status',
            'include_driver',
            'pickup_key',
            'destination_key',
            'opt_in_email',
            'email_status',
            'forwarded_at',
            'created_by_staff_id'];
        $cols = $pdo->query('SHOW COLUMNS FROM `bookings`')->fetchAll(PDO::FETCH_COLUMN);
        $missingColumns = array_diff($requiredColumns, $cols);
        if ($missingColumns) {
            throw new RuntimeException('Existing bookings table requires a migration (missing ' . implode(', ', $missingColumns) . '). Backup before running upgrade.');
        }
        echo "Existing database found. Kept all tables and data unchanged.\n";
        exit(0);
    }
    // SQL seed has no semicolons inside quoted strings; every statement ends with ';'.
    $sql = file_get_contents(dirname(__DIR__, 2) . '/Models/Database/install.sql');
    if ($sql === false) throw new RuntimeException('Missing clean installer SQL file.');
    $sql = str_replace('`privatehire_cars_db`', '`' . $database . '`', $sql);
    $sql = preg_replace('/^\s*--[^\r\n]*/m', '', $sql);
    foreach (explode(';', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') $pdo->exec($statement);
    }
    echo "Fresh database installed with reference services, fleet, staff, bookings, payments and notification tables.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Database check/installer failed: ' . $e->getMessage() . "\n");
    exit(1);
}
