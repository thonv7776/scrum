<?php
declare(strict_types = 1);

/** Draft, quote input and vehicle conflict checks (US06, US07, US09, US10). */
function find_draft(PDO $db, int $owner): array {
    $id = (int)($_SESSION['draft_booking_id'] ?? 0);
    if (!$id) throw new DomainException('Select a service to start your booking.');
    $q = $db->prepare('SELECT * FROM bookings WHERE booking_id=? AND customer_id=? LIMIT 1');
    $q->execute([$id,
        $owner]);
    $r = $q->fetch();
    if (!$r) throw new DomainException('Your booking draft was not found. Start again.');
    return $r;
}

function quote_inputs(PDO $db, array $d): array {
    $id = filter_var($d['service_id'] ?? null, FILTER_VALIDATE_INT);
    $cat = filter_var($d['category_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || !$cat) throw new DomainException('Select one service and minicab size.');
    $q = $db->prepare('SELECT s.* FROM transport_services s INNER JOIN service_minicab_categories sc ON sc.service_id=s.service_id AND sc.category_id=? WHERE s.service_id=?');
    $q->execute([$cat,
        $id]);
    $s = $q->fetch();
    $q = $db->prepare('SELECT * FROM minicab_categories WHERE category_id=?');
    $q->execute([$cat]);
    $c = $q->fetch();
    if (!$s || !$c) throw new DomainException('The selected vehicle size is not supported by this service.');
    $p = filter_var($d['passenger_count'] ?? null, FILTER_VALIDATE_INT);
    if (!$p || $p < 1 || $p > 8) throw new DomainException('Passenger count must be between 1 and 8.');
    if ($p > (int)$c['max_passengers']) throw new DomainException('Vehicle seating capacity exceeded. Please choose a larger minicab.');
    $days = ($id == 4) ? filter_var($d['hire_days'] ?? null, FILTER_VALIDATE_INT) : 1;
    if ($id === 4 && (!$days || $days < 1 || $days > 30)) throw new DomainException('Hire days must be 1–30.');
    $flight = trim((string)($d['flight_number'] ?? ''));
    if ($id === 3 && ($flight === '' || strlen($flight) > 20)) throw new DomainException('Enter your flight number and terminal (up to 20 characters).');
    // Transport services 1-3 already provide a driver. The optional driver add-on
    // is only meaningful for service 4 (self-drive daily hire by default).
    $include = $id === 4 && filter_var($d['include_driver'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $handover = trim((string)($d['hire_handover_mode'] ?? ''));
    if ($id === 4 && $handover !== '') {
        // Online daily hire is a vehicle handover, not an A->B route. Preserve
        // the existing database columns and fare/availability implementation.
        if (!in_array($handover, ['collection',
            'delivery'], true)) throw new DomainException('Choose vehicle delivery or customer collection.');
        $a = $handover === 'delivery' ? 'hire_delivery' : 'hire_collection';
        $b = 'hire_no_route';
        $address = preg_replace('/\s+/u', ' ', strip_tags(trim((string)($d['hire_delivery_address'] ?? ''))));
        if ($handover === 'delivery' && (strlen($address) < 5 || strlen($address) > 200)) throw new DomainException('Enter a vehicle delivery address (5–200 characters).');
        $pickup = $handover === 'delivery' ? 'Vehicle delivery: ' . $address : 'Customer collection: 20 Cong Hoa, Bay Hien, Ho Chi Minh City';
        $destination = 'Vehicle hire - no fixed journey destination';
        $miles = 0.0;
        // Daily-hire pricing is per day; never charge journey mileage.
    } else {
        // Unchanged for transport journeys and legacy Staff telephone bookings.
        $a = trim((string)($d['pickup_key'] ?? ''));
        $b = trim((string)($d['destination_key'] ?? ''));
        $miles = route_distance($a, $b);
        $pickup = landmarks()[$a]['name'];
        $destination = landmarks()[$b]['name'];
    }
    $f = fare($s, $c, $miles, (int)$days, $include);
    return ['service' => $s,
        'category' => $c,
        'service_id' => (int)$id,
        'category_id' => (int)$cat,
        'pax' => $p,
        'pickup_key' => $a,
        'destination_key' => $b,
        'pickup' => $pickup,
        'destination' => $destination,
        'flight' => $id === 3 ? $flight : null,
        'days' => $id === 4 ? (int)$days : null,
        'include_driver' => $include,
        'fare' => $f];
}

function available_minicabs(PDO $db, int $category, string $start, string $end, int $excludeId = 0, bool $lock = false): array {
    // caller holding transaction must lock all category vehicles before conflict check when reserving
    if ($lock) {
        $lockq = $db->prepare('SELECT minicab_id FROM minicabs WHERE category_id=? AND is_active=1 ORDER BY minicab_id FOR UPDATE');
        $lockq->execute([$category]);
        $lockq->fetchAll();
    }
    $q = $db->prepare("SELECT m.* FROM minicabs m LEFT JOIN vehicle_dispatch_profiles vp ON vp.minicab_id=m.minicab_id WHERE m.category_id=? AND m.is_active=1 AND COALESCE(vp.operational_status,'Available')='Available' AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.minicab_id=m.minicab_id AND ((b.booking_status='Held' AND b.slot_hold_until>NOW()) OR b.booking_status IN ('Paid & Confirmed','Telephone Confirmed','Forwarded to Control Centre')) AND b.pickup_datetime < ? AND b.estimated_end_datetime > ? AND b.booking_id <> ?) ORDER BY m.minicab_id");
    $q->execute([$category,
        $end,
        $start,
        $excludeId]);
    return $q->fetchAll();
}

/** Optional driving-route provider compatible with OSRM JSON responses.
 *  PHC_ROUTING_URL: https://your-provider.example/route/v1/driving/{from};{to}?overview=false
 *  Coordinates are longitude,latitude pairs; absent provider retains legacy estimate.
 */
