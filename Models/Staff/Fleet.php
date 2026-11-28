<?php
declare(strict_types = 1);

/** Filtered available fleet for staff and telephone bookings (US28). */
function s2_conflict_sql(): string {
    return "b.minicab_id=m.minicab_id AND b.booking_id<>? AND b.pickup_datetime<? AND b.estimated_end_datetime>? AND ((b.booking_status='Held' AND b.slot_hold_until>NOW()) OR b.booking_status IN ('Paid & Confirmed','Telephone Confirmed','Forwarded to Control Centre'))";
}

function s2_fleet(PDO $pdo, string $date, string $time, string $size = 'all', string $zone = 'all', int $exclude = 0, int $duration = 90): array {
    $start = pickup_datetime($date, $time);
    $end = $start->modify('+' . $duration . ' minutes');
    $sql = 'SELECT m.minicab_id,m.registration_no,m.make_and_model,m.vehicle_colour,k.category_id,k.category_name,k.max_passengers,v.zone,v.operational_status FROM minicabs m JOIN minicab_categories k ON k.category_id=m.category_id LEFT JOIN vehicle_dispatch_profiles v ON v.minicab_id=m.minicab_id WHERE m.is_active=1 AND COALESCE(v.operational_status,\'Available\')=\'Available\' AND NOT EXISTS (SELECT 1 FROM bookings b WHERE ' . s2_conflict_sql() . ')';
    $params = [$exclude,
        $end->format('Y-m-d H:i:s'),
        $start->format('Y-m-d H:i:s')];
    if ($size !== 'all') {
        $sql .= ' AND k.category_name LIKE ?';
        $params[] = '%' . $size . '%';
    }
    if ($zone !== 'all') {
        $sql .= ' AND v.zone=?';
        $params[] = $zone;
    }
    $sql .= ' ORDER BY m.minicab_id';
    $q = $pdo->prepare($sql);
    $q->execute($params);
    return $q->fetchAll();
}
