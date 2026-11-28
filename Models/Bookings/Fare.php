<?php
declare(strict_types = 1);

/** Tariffs, 15% online discount and time validation (US09, US11, US12). */
function money(float $n): float {
    return round($n, 2, PHP_ROUND_HALF_UP);
}

function fare(array $s, array $c, float $miles, int $days, bool $includeDriver, string $channel = 'Online'): array {
    $id = (int)$s['service_id'];
    $flag = money((float)$s['base_flag_fall_rate']);
    $distance = $id === 4 ? 0.0 : money($miles * (float)$c['per_mile_rate']);
    $daily = $id === 4 ? money($days * (float)$s['daily_hire_rate']) : 0.0;
    $surcharge = $id === 4 ? 0.0 : money((float)$s['airport_surcharge']);
    $driver = $includeDriver ? money($id === 4 ? $days * cfg()['driver_extra_per_day_gbp'] : cfg()['driver_extra_per_trip_gbp']) : 0.0;
    $base = money($flag + $distance + $daily + $surcharge + $driver);
    $discount = $channel === 'Online' ? money($base * cfg()['online_discount_rate']) : 0.0;
    return ['flag_fall' => $flag,
        'distance_fare' => $distance,
        'daily_fare' => $daily,
        'airport_surcharge' => $surcharge,
        'driver_fee' => $driver,
        'base_total' => $base,
        'discount' => $discount,
        'final_total' => money($base - $discount),
        'distance_miles' => $miles,
        'per_mile_rate' => (float)$c['per_mile_rate'],
        'daily_rate' => (float)($s['daily_hire_rate'] ?? 0)];
}

function estimated_duration_minutes(float $miles, int $service, int $days): int {
    return $service === 4 ? max(60, $days * 10 * 60) : max(30, (int)ceil($miles * 2.95) + 15);
}

function pickup_datetime(string $date, string $time): DateTimeImmutable {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) throw new DomainException('Select a valid pickup date and time.');
    $v = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, new DateTimeZone('Europe/London'));
    if (!$v || $v->format('Y-m-d H:i') !== $date . ' ' . $time) throw new DomainException('Select a valid pickup date and time.');
    if ($v->getTimestamp() < time() + 30 * 60) throw new DomainException('Pickup must be at least 30 minutes from now (London time).');
    return $v;
}
