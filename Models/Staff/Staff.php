<?php
declare(strict_types = 1);

/** Staff identity, role and session checks (US56, US57, US59, US60). */
function s2_staff(PDO $pdo, array $roles = ['Admin',
    'Manager',
    'Call Centre Staff']): array {
    $id = (int)($_SESSION['s2_staff_id'] ?? 0);
    $v = (int)($_SESSION['s2_staff_version'] ?? 0);
    if (!$id) failure('Staff sign-in is required.', 401);
    $q = $pdo->prepare('SELECT staff_id,employee_id,full_name,work_email,phone,role,status,must_change_password,session_version,created_at,last_login_at,deactivated_at FROM staff_accounts WHERE staff_id=?');
    $q->execute([$id]);
    $staff = $q->fetch();
    if (!$staff || $staff['status'] !== 'Active' || (int)$staff['session_version'] !== $v) {
        unset ($_SESSION['s2_staff_id'], $_SESSION['s2_staff_version']);
        failure('Your staff session is no longer valid.', 401);
    }
    if (!in_array($staff['role'], $roles, true)) failure('You do not have permission to perform this action.', 403);
    return $staff;
}

function s2_require_staff_ready(PDO $pdo, array $roles = ['Admin',
    'Manager',
    'Call Centre Staff']): array {
    $s = s2_staff($pdo, $roles);
    if ((int)$s['must_change_password']) failure('Change your temporary password before using the staff portal.', 403);
    return $s;
}

function s2_sanitize_staff(array $s): array {
    return ['staff_id' => (int)$s['staff_id'],
        'id' => $s['employee_id'],
        'name' => $s['full_name'],
        'email' => $s['work_email'],
        'phone' => $s['phone'],
        'role' => $s['role'],
        'status' => $s['status'],
        'must_change_password' => (bool)$s['must_change_password'],
        'created' => $s['created_at'],
        'login' => $s['last_login_at'],
        'deactAt' => $s['deactivated_at'] ?? null,
        'self' => isset ($_SESSION['s2_staff_id']) && (int)$s['staff_id'] === (int)$_SESSION['s2_staff_id']];
}
