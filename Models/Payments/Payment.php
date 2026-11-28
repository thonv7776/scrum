<?php
declare(strict_types = 1);

/** Sandbox payment card validation; no live bank charges (US15). */
function s2_luhn(string $digits): bool {
    if (!preg_match('/^\d{16}$/D', $digits)) return false;
    $sum = 0;
    $double = false;
    for ($i = strlen($digits) - 1; $i >= 0; $i--) {
        $n = (int)$digits[$i];
        if ($double) {
            $n *= 2;
            if ($n > 9) $n -= 9;
        }
        $sum += $n;
        $double = !$double;
    }
    return $sum > 0 && $sum % 10 === 0;
}

function s2_expiry_ok(string $expiry): bool {
    if (!preg_match('/^(0[1-9]|1[0-2])\/(\d\d)$/D', $expiry, $m)) return false;
    $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', sprintf('20%02d-%02d-01 00:00:00', (int)$m[2], (int)$m[1]), new DateTimeZone('Europe/London'));
    return $end !== false && $end->modify('first day of next month')->getTimestamp() > time();
}

function s2_card_errors(array $d): array {
    $e = [];
    if (strlen(trim((string)($d['card_name'] ?? ''))) < 2) $e['cardName'] = 'Enter the cardholder name.';
    if (!s2_luhn(preg_replace('/\s+/', '', (string)($d['card_number'] ?? '')))) $e['cardNumber'] = 'Enter a valid 16-digit card number (Luhn check).';
    if (!s2_expiry_ok((string)($d['expiry'] ?? ''))) $e['cardExp'] = 'Enter a non-expired date MM/YY.';
    if (!preg_match('/^\d{3}$/D', (string)($d['cvv'] ?? ''))) $e['cardCvv'] = 'CVV must contain exactly 3 digits.';
    return $e;
}

function s2_gateway_charge(string $card, string $expiry, string $cvv, string $name, float $amount, string $ref, string $challenge): array {
    $url = trim((string)(getenv('PHC_GATEWAY_URL') ? : ''));
    if ($url === '') {
        // COURSEWORK SANDBOX. This is NOT VISA Check or a real bank transaction.
        if ($challenge !== 'approved') return ['ok' => false,
            'error' => 'Sandbox bank verification was not approved.'];
        if ($card === '4000000000000002') return ['ok' => false,
            'error' => 'Sandbox bank declined this test card.'];
        if (!in_array($card, ['4242424242424242',
            '5555555555554444'], true)) return ['ok' => false,
            'error' => 'Sandbox accepts only the documented 16-digit test card (4242 4242 4242 4242).'];
        return ['ok' => true,
            'txn' => 'SBOX-' . strtoupper(bin2hex(random_bytes(8))),
            'mode' => 'Sandbox'];
    }
    if (!str_starts_with($url, 'https://')) throw new DomainException('Live gateway URL must use HTTPS.');
    if (!extension_loaded('curl')) throw new DomainException('PHP cURL extension is required for live gateway.');
    $token = (string)getenv('PHC_GATEWAY_TOKEN');
    if ($token === '') throw new DomainException('Live gateway credentials have not been configured.');
    // Generic adapter: must be aligned with actual PCI-compliant, tokenising processor before live deployment.
    // Never pass raw card data directly to an unverified/non-PCI gateway.
    throw new DomainException('Live gateway adapter requires the payment provider\'s official tokenisation integration. This coursework build supports SANDBOX only.');
}
