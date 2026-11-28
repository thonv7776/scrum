<?php

/** US28/31 - Telephone bookings, caller checks and fleet availability. */
if ($action === 'customer_search') {
    s2_require_staff_ready($pdo, ['Call Centre Staff',
        'Manager',
        'Admin']);
    $query = trim((string)($d['q'] ?? ''));
    if (strlen($query) < 2) response(['ok' => true,
        'customers' => []]);
    $q = $pdo->prepare('SELECT customer_id,full_name,email,residential_address,telephone_number FROM customers WHERE full_name LIKE ? OR email LIKE ? OR telephone_number LIKE ? ORDER BY customer_id LIMIT 20');
    $t = '%' . $query . '%';
    $q->execute([$t,
        $t,
        $t]);
    response(['ok' => true,
        'customers' => $q->fetchAll()]);
}
if ($action === 'fleet') {
    s2_require_staff_ready($pdo, ['Call Centre Staff',
        'Manager',
        'Admin']);
    $date = (string)($d['date'] ?? '');
    $time = (string)($d['time'] ?? '');
    $rows = s2_fleet($pdo, $date, $time, (string)($d['size'] ?? 'all'), (string)($d['zone'] ?? 'all'), (int)($d['exclude'] ?? 0), (int)($d['duration'] ?? 90));
    $total = (int)$pdo->query('SELECT COUNT(*) FROM minicabs WHERE is_active=1')->fetchColumn();
    response(['ok' => true,
        'vehicles' => $rows,
        'total' => $total]);
}
if ($action === 'phone_quote') {
    s2_require_staff_ready($pdo, ['Call Centre Staff',
        'Manager',
        'Admin']);
    $x = quote_inputs($pdo, ['service_id' => $d['service_id'] ?? null,
        'category_id' => $d['category_id'] ?? null,
        'pickup_key' => $d['pickup_key'] ?? null,
        'destination_key' => $d['destination_key'] ?? null,
        'passenger_count' => $d['passenger_count'] ?? null,
        'hire_days' => $d['hire_days'] ?? 1,
        'flight_number' => $d['flight_number'] ?? '',
        'include_driver' => $d['include_driver'] ?? false]);
    $s = $x['service'];
    $c = $x['category'];
    $m = $x['fare']['distance_miles'];
    $f = fare($s, $c, $m, (int)($x['days'] ? : 1), $x['include_driver'], 'Telephone');
    response(['ok' => true,
        'quote' => $f]);
}
if ($action === 'phone_create') {
    $staff = s2_require_staff_ready($pdo, ['Call Centre Staff',
        'Manager',
        'Admin']);
    if (!isset ($d['verified']) || !is_array($d['verified']) || count($d['verified']) !== 3 || count(array_filter($d['verified'], static fn($flag) => $flag === true)) !== 3) failure('Complete all three caller identity verification checks.');
    $mode = (string)($d['caller_mode'] ?? 'existing');
    $x = quote_inputs($pdo, ['service_id' => $d['service_id'] ?? null,
        'category_id' => $d['category_id'] ?? null,
        'pickup_key' => $d['pickup_key'] ?? null,
        'destination_key' => $d['destination_key'] ?? null,
        'passenger_count' => $d['passenger_count'] ?? null,
        'hire_days' => $d['hire_days'] ?? 1,
        'flight_number' => $d['flight_number'] ?? '',
        'include_driver' => $d['include_driver'] ?? false]);
    $start = pickup_datetime((string)($d['date'] ?? ''), (string)($d['time'] ?? ''));
    $end = $start->modify('+' . estimated_duration_minutes((float)$x['fare']['distance_miles'], (int)$x['service_id'], (int)($x['days'] ? : 1)) . ' minutes');
    $qfare = fare($x['service'], $x['category'], (float)$x['fare']['distance_miles'], (int)($x['days'] ? : 1), $x['include_driver'], 'Telephone');
    $vehicle = (int)($d['minicab_id'] ?? 0);
    if (!$vehicle) failure('Select an available vehicle.');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT minicab_id FROM minicabs WHERE category_id=? AND is_active=1 ORDER BY minicab_id FOR UPDATE')->execute([$x['category_id']]);
        $available = s2_fleet($pdo, $start->format('Y-m-d'), $start->format('H:i'), 'all', 'all', 0, estimated_duration_minutes((float)$x['fare']['distance_miles'], (int)$x['service_id'], (int)($x['days'] ? : 1)));
        if (!array_filter($available, fn($v) => (int)$v['minicab_id'] === $vehicle && (int)$v['category_id'] === $x['category_id'])) throw new DomainException('Vehicle has been reserved. Select another vehicle.');
        if ($mode === 'existing') {
            $owner = (int)($d['customer_id'] ?? 0);
            $q = $pdo->prepare('SELECT customer_id,full_name,residential_address,telephone_number FROM customers WHERE customer_id=?');
            $q->execute([$owner]);
            $u = $q->fetch();
            if (!$u) throw new DomainException('Select an existing customer.');
            if (!s2_verify_caller_claims($u, is_array($d['caller_claims'] ?? null) ? $d['caller_claims'] : [])) throw new DomainException('Caller name, residential address or phone did not match the registered profile. Ask for the details again.');
        } elseif ($mode === 'new') {
            $c = $d['customer'] ?? [];
            if (!is_array($c)) throw new DomainException('Complete the new caller profile.');
            $name = trim((string)($c['name'] ?? ''));
            $phone = trim((string)($c['phone'] ?? ''));
            $addr = trim((string)($c['address'] ?? ''));
            $email = strtolower(trim((string)($c['email'] ?? '')));
            if (strlen($name) < 2 || !valid_phone($phone) || strlen($addr) < 5 || !valid_email($email)) throw new DomainException('New customer requires name, email, address and 10–11 digit phone.');
            $pdo->prepare("INSERT INTO customers(full_name,email,password_hash,residential_address,telephone_number,account_status) VALUES (?,?,?,?,?,'Pending Verification')")->execute([$name,
                $email,
                password_hash(bin2hex(random_bytes(24)),
                PASSWORD_DEFAULT),
                $addr,
                $phone]);
            $owner = (int)$pdo->lastInsertId();
        } else throw new DomainException('Select an existing or first-time caller.');
        $q = $pdo->prepare("INSERT INTO bookings(customer_id,service_id,category_id,minicab_id,flight_number,hire_days,pickup_location,destination,pickup_key,destination_key,distance_miles,passenger_count,pickup_datetime,estimated_end_datetime,booking_channel,base_total_fare,online_discount_15,final_payable_fare,booking_status,include_driver,driver_fee,created_by_staff_id,opt_in_email) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Telephone',?,0,?,'Telephone Confirmed',?,?,?,0)");
        $q->execute([$owner,
            $x['service_id'],
            $x['category_id'],
            $vehicle,
            $x['flight'],
            $x['days'],
            $x['pickup'],
            $x['destination'],
            $x['pickup_key'],
            $x['destination_key'],
            $x['fare']['distance_miles'],
            $x['pax'],
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
            $qfare['base_total'],
            $qfare['final_total'],
            $x['include_driver'] ? 1 : 0,
            $qfare['driver_fee'],
            $staff['staff_id']]);
        $id = (int)$pdo->lastInsertId();
        s2_enqueue_dispatch($pdo, $id);
        // Telephone booking also receives an automatic demo dispatch/ETA event.
        phc_demo_schedule_eta($pdo, $id, (int)$x['service_id'], (bool)$x['include_driver']);
        $pdo->commit();
        response(['ok' => true,
            'ref' => s2_ref(['booking_id' => $id,
            'booking_channel' => 'Telephone']),
            'booking_id' => $id,
            'fare' => $qfare,
            'status' => 'Telephone Confirmed',
            'dispatch_status' => 'Pending']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
