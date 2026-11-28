<?php

/** Run ONCE from PHP CLI after migration. Password must come from environment. */
declare(strict_types = 1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require dirname(__DIR__, 2) . '/Models/Bootstrap.php';
$password = (string)getenv('PHC_ADMIN_PASSWORD');
$email = strtolower(trim((string)(getenv('PHC_ADMIN_EMAIL') ? : 'admin@privatehirecars.co.uk')));
$name = trim((string)(getenv('PHC_ADMIN_NAME') ? : 'Coursework Admin'));
$phone = (string)(getenv('PHC_ADMIN_PHONE') ? : '07700900101');
if (!valid_email($email) || !valid_phone($phone) || strlen($name) < 2) {
    fwrite(STDERR, "Provide valid PHC_ADMIN_EMAIL, PHC_ADMIN_NAME and PHC_ADMIN_PHONE.\n");
    exit(2);
}
if (strlen($password) < 12 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
    fwrite(STDERR, "Set PHC_ADMIN_PASSWORD to 12+ characters with uppercase and digit, then run php Controllers/Setup/Admin.php\n");
    exit(2);
}
try {
    $db = db();
    $q = $db->prepare('SELECT employee_id FROM staff_accounts WHERE employee_id=? OR work_email=?');
    $q->execute(['EMP-0001',
        $email]);
    if ($q->fetch()) exit("Admin already exists; original record untouched.\n");
    $db->prepare("INSERT INTO staff_accounts(employee_id,full_name,work_email,phone,role,password_hash,status,must_change_password) VALUES ('EMP-0001',?,?,?,'Admin',?,'Active',0)")->execute([$name,
        $email,
        $phone,
        password_hash($password,
        PASSWORD_DEFAULT)]);
    echo "Created EMP-0001 Admin ($email). Password is NOT logged.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}
