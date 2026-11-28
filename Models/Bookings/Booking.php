<?php
declare(strict_types = 1);

/** Booking data and customer receipt (US17). */
function s2_ref(array $b): string {
    return ($b['booking_channel'] === 'Telephone' ? 'PHC-T-' : 'PHC-') . str_pad((string)$b['booking_id'], 6, '0', STR_PAD_LEFT);
}

function s2_booking_id(string $ref): int {
    if (!preg_match('/^PHC-(?:T-)?(\d{1,10})$/D', $ref, $m)) throw new DomainException('Invalid booking reference.');
    return (int)$m[1];
}

function s2_query_booking(PDO $pdo, int $id, int $owner = 0, bool $lock = false): array {
    $q = $pdo->prepare('SELECT b.*,c.full_name AS customer_name,c.email AS customer_email,c.telephone_number AS customer_phone,s.service_name,m.registration_no,m.make_and_model,m.vehicle_colour,k.category_name,k.max_passengers, v.driver_full_name,v.zone FROM bookings b JOIN customers c ON c.customer_id=b.customer_id JOIN transport_services s ON s.service_id=b.service_id LEFT JOIN minicabs m ON m.minicab_id=b.minicab_id LEFT JOIN minicab_categories k ON k.category_id=b.category_id LEFT JOIN vehicle_dispatch_profiles v ON v.minicab_id=b.minicab_id WHERE b.booking_id=?' . ($owner ? ' AND b.customer_id=?' : '') . ($lock ? ' FOR UPDATE' : ''));
    $q->execute($owner ? [$id,
        $owner] : [$id]);
    $b = $q->fetch();
    if (!$b) failure('Booking not found or access denied.', 404);
    return $b;
}

function s2_booking_public(array $b): array {
    return ['id' => (int)$b['booking_id'],
        'ref' => s2_ref($b),
        'customer' => $b['customer_name'],
        'email' => $b['customer_email'],
        'service' => $b['service_name'],
        'pickup' => $b['pickup_location'],
        'dest' => $b['destination'],
        'date_time' => $b['pickup_datetime'],
        'size' => $b['category_name'],
        'pax' => (int)$b['passenger_count'],
        'base' => (float)$b['base_total_fare'],
        'discount' => (float)$b['online_discount_15'],
        'total' => (float)$b['final_payable_fare'],
        'channel' => $b['booking_channel'],
        'status' => $b['booking_status'],
        'hold_until' => $b['slot_hold_until'],
        'hold_until_iso' => $b['slot_hold_until'] ? (new DateTimeImmutable($b['slot_hold_until'],
        new DateTimeZone('Europe/London')))->format(DATE_ATOM) : null,
        'vehicle' => $b['registration_no'] ?? null,
        'email_status' => $b['email_status'],
        'opt_in_email' => (bool)$b['opt_in_email']];
}

function s2_receipt(PDO $pdo, int $id, int $customer): array {
    $b = s2_query_booking($pdo, $id, $customer);
    $q = $pdo->prepare("SELECT transaction_id,card_last4,payment_method,paid_at,amount_gbp,gateway_mode FROM payments WHERE booking_id=? AND payment_status='Approved'");
    $q->execute([$id]);
    $p = $q->fetch();
    if (!$p) failure('A paid receipt is not available for this booking.', 404);
    return array_merge(s2_booking_public($b), ['txn' => $p['transaction_id'],
        'card_last4' => $p['card_last4'],
        'method' => $p['payment_method'],
        'paid_at' => $p['paid_at'],
        'gateway_mode' => $p['gateway_mode'],
        'paid' => (float)$p['amount_gbp']]);
}
