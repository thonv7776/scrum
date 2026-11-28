<?php

/** Standalone Sprint 2 API, using Sprint 1 sessions/database without editing Sprint 1. */
declare(strict_types = 1);
require dirname(__DIR__) . '/Models/Bootstrap.php';
start_secure_session();
$action = (string)($_GET['action'] ?? 'bootstrap');
try {
    $pdo = db();
    if ($action === 'bootstrap') {
        $u = null;
        $staff = null;
        if (!empty ($_SESSION['customer_id'])) {
            $q = $pdo->prepare("SELECT customer_id,full_name,email FROM customers WHERE customer_id=? AND account_status='Active'");
            $q->execute([$_SESSION['customer_id']]);
            $u = $q->fetch() ? : null;
        }
        if (!empty ($_SESSION['s2_staff_id'])) {
            $q = $pdo->prepare("SELECT staff_id,employee_id,full_name,work_email,phone,role,status,must_change_password,session_version,created_at,last_login_at,deactivated_at FROM staff_accounts WHERE staff_id=?");
            $q->execute([$_SESSION['s2_staff_id']]);
            $s = $q->fetch();
            if ($s && $s['status'] === 'Active' && (int)$s['session_version'] === (int)($_SESSION['s2_staff_version'] ?? 0)) $staff = s2_sanitize_staff($s);
            else unset ($_SESSION['s2_staff_id'], $_SESSION['s2_staff_version']);
        }
        response(['ok' => true,
            'csrf' => $_SESSION['csrf'],
            'customer' => $u,
            'staff' => $staff,
            'sandbox' => !getenv('PHC_GATEWAY_URL'),
            'gps_configured' => (bool)getenv('PHC_GPS_URL')]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') failure('POST required', 405);
    validate_csrf();
    $d = body();
    // Authentication actions: all identity and authorization checks happen server-side.
    require __DIR__ . '/Actions/Staff/Login.php';
    require __DIR__ . '/Actions/Customer/Payment.php';
    require __DIR__ . '/Actions/Staff/Staff.php';
    require __DIR__ . '/Actions/Staff/Fleet.php';
    require __DIR__ . '/Actions/Staff/Dispatch.php';
    failure('Unknown payment or staff action.', 404);
} catch (DomainException $e) {
    failure($e->getMessage(), 422);
} catch (PDOException $e) {
    error_log('Sprint2 DB: ' . $e->getMessage());
    failure('Database operation failed. Check migration and duplicate data.', 503);
} catch (Throwable $e) {
    error_log('Sprint2 error: ' . $e->getMessage());
    failure('The payment or staff service encountered an error.', 500);
}
