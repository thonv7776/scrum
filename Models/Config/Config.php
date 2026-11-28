<?php
declare(strict_types = 1);

/** Global configuration (all user stories). */
function cfg(): array {
    static $c;
    return $c ??= require __DIR__ . '/App.php';
}
