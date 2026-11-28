<?php
declare(strict_types = 1);

/** Customer booking API: service selection, quotes, availability and reservation holds. */
require dirname(__DIR__) . '/Models/Bootstrap.php';
start_secure_session();
$action = (string)($_GET['action'] ?? 'bootstrap');
try {
    $pdo = db();
    if ($action === 'bootstrap') {
        $services = $pdo->query('SELECT service_id,service_name,short_tagline,scope_description,base_flag_fall_rate,airport_surcharge,daily_hire_rate FROM transport_services ORDER BY service_id')->fetchAll();
        $cats = $pdo->query('SELECT category_id,category_name,max_passengers,max_luggage,per_mile_rate FROM minicab_categories ORDER BY category_id')->fetchAll();
        $links = $pdo->query('SELECT service_id,category_id FROM service_minicab_categories')->fetchAll();
        $user = null;
        if (isset ($_SESSION['customer_id'])) {
            $q = $pdo->prepare('SELECT customer_id,full_name,email,account_status FROM customers WHERE customer_id=?');
            $q->execute([$_SESSION['customer_id']]);
            $user = $q->fetch() ? : null;
            if (!$user || $user['account_status'] !== 'Active') {
                unset ($_SESSION['customer_id']);
                $user = null;
            }
        }
        $flash = $_SESSION['flash'] ?? null;
        unset ($_SESSION['flash']);
        response(['ok' => true,
            'csrf' => $_SESSION['csrf'],
            'user' => $user,
            'services' => $services,
            'categories' => $cats,
            'service_categories' => $links,
            'flash' => $flash,
            'server_time' => date('c'),
            'driver_fee_per_trip' => (float)cfg()['driver_extra_per_trip_gbp'],
            'driver_fee_per_day' => (float)cfg()['driver_extra_per_day_gbp']]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') failure('POST required.', 405);
    validate_csrf();
    $d = body();
    require __DIR__ . '/Actions/Customer/Account.php';
    $owner = require_auth();
    require __DIR__ . '/Actions/Customer/Booking.php';
    failure('Unknown action.', 404);
} catch (DomainException $e) {
    failure($e->getMessage());
} catch (PDOException $e) {
    error_log('PHC database error: ' . $e->getMessage());
    failure('Database error. Check MySQL credentials and import setup in README.', 503);
} catch (Throwable $e) {
    error_log('PHC unexpected error: ' . $e->getMessage());
    failure('An unexpected server error occurred.', 500);
}
