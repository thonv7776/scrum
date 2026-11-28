<?php

/** US57/59/60 - Staff login, logout, password changes. */
if ($action === 'staff_login') {
    $q = $pdo->prepare('SELECT * FROM staff_accounts WHERE work_email=?');
    $q->execute([strtolower(trim((string)($d['email'] ?? '')))]);
    $s = $q->fetch();
    if (!$s || !password_verify((string)($d['password'] ?? ''), $s['password_hash']) || $s['status'] !== 'Active') failure('Incorrect credentials or account deactivated.', 401);
    session_regenerate_id(true);
    $_SESSION['s2_staff_id'] = (int)$s['staff_id'];
    $_SESSION['s2_staff_version'] = (int)$s['session_version'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE staff_accounts SET last_login_at=NOW() WHERE staff_id=?')->execute([$s['staff_id']]);
    response(['ok' => true,
        'csrf' => $_SESSION['csrf'],
        'staff' => s2_sanitize_staff($s)]);
}
if ($action === 'staff_logout') {
    unset ($_SESSION['s2_staff_id'], $_SESSION['s2_staff_version']);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    response(['ok' => true,
        'csrf' => $_SESSION['csrf']]);
}
if ($action === 'staff_change_password') {
    $s = s2_staff($pdo);
    $old = (string)($d['old_password'] ?? '');
    $new = (string)($d['new_password'] ?? '');
    $q = $pdo->prepare('SELECT password_hash FROM staff_accounts WHERE staff_id=?');
    $q->execute([$s['staff_id']]);
    if (!password_verify($old, (string)$q->fetchColumn())) failure('Incorrect temporary password.');
    if (strlen($new) < 10 || !preg_match('/[A-Z]/', $new) || !preg_match('/[0-9]/', $new)) failure('New password needs 10+ characters, an uppercase letter and a digit.');
    $pdo->prepare('UPDATE staff_accounts SET password_hash=?,must_change_password=0,session_version=session_version+1 WHERE staff_id=?')->execute([password_hash($new,
        PASSWORD_DEFAULT),
        $s['staff_id']]);
    $_SESSION['s2_staff_version']++;
    response(['ok' => true]);
}
// Customer endpoints. The same phc_session and customer_id as Sprint 1 are reused.
