<?php
declare(strict_types = 1);

/** Customer registration validation (US01). */
function valid_email(string $email): bool {
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 150;
}

function valid_phone(string $phone): bool {
    return (bool)preg_match('/^[0-9]{10,11}$/D', $phone);
}

function registration_errors(array $d): array {
    $r = [];
    if (strlen(trim((string)($d['full_name'] ?? ''))) < 2 || strlen(trim((string)($d['full_name'] ?? ''))) > 100) $r['full_name'] = 'Enter your full name (2–100 characters).';
    if (!valid_email(trim((string)($d['email'] ?? '')))) $r['email'] = 'Enter a valid email address.';
    if (trim((string)($d['residential_address'] ?? '')) === '' || strlen(trim((string)($d['residential_address'] ?? ''))) > 255) $r['residential_address'] = 'Residential address is required (up to 255 characters).';
    if (!valid_phone(trim((string)($d['telephone_number'] ?? '')))) $r['telephone_number'] = 'Enter 10–11 digits (numbers only).';
    if (strlen((string)($d['password'] ?? '')) < 8) $r['password'] = 'Password must have at least 8 characters.';
    return $r;
}
