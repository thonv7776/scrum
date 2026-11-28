<?php
declare(strict_types = 1);

/** PDO MySQL connection and database timezone. */
function db(): PDO {
    static $db = null;
    if ($db instanceof PDO) return $db;
    $c = cfg();
    $db = new PDO('mysql:host=' . $c['db_host'] . ';port=' . $c['db_port'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4', $c['db_user'], $c['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false]);
    $offset = date('P');
    $db->exec("SET time_zone = '" . $offset . "'");
    return $db;
}
