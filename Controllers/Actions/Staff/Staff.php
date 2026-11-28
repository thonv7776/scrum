<?php

/** US56/57/59/60 - Create, manage, deactivate staff. */
if ($action === 'staff_create') {
    s2_require_staff_ready($pdo, ['Admin']);
    $id = strtoupper(trim((string)($d['id'] ?? '')));
    $name = trim((string)($d['name'] ?? ''));
    $email = strtolower(trim((string)($d['email'] ?? '')));
    $phone = trim((string)($d['phone'] ?? ''));
    $role = (string)($d['role'] ?? '');
    $pw = (string)($d['password'] ?? '');
    $errors = [];
    if (!preg_match('/^EMP-\d{4}$/D', $id)) $errors['crId'] = 'Employee ID must be EMP- followed by 4 digits.';
    if (strlen($name) < 2 || strlen($name) > 100) $errors['crName'] = 'Enter a full name (2–100 characters).';
    if (!valid_email($email)) $errors['crMail'] = 'Enter a valid work email.';
    if (!valid_phone($phone)) $errors['crPhone'] = 'Phone must be 10–11 digits.';
    if (!in_array($role, ['Call Centre Staff',
        'Manager'], true)) $errors['crRole'] = 'Choose Call Centre Staff or Manager.';
    if (strlen($pw) < 8) $errors['crPw'] = 'Temporary password needs at least 8 characters.';
    if ($errors) failure('Correct the staff account fields.', 422, $errors);
    try {
        $pdo->prepare("INSERT INTO staff_accounts(employee_id,full_name,work_email,phone,role,password_hash,status,must_change_password) VALUES (?,?,?,?,?,?,'Active',1)")->execute([$id,
            $name,
            $email,
            $phone,
            $role,
            password_hash($pw,
            PASSWORD_DEFAULT)]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) == 1062) failure('Employee ID or work email already exists.', 409);
        throw $e;
    }
    $mail = s2_transport('Email', $email, 'Welcome to PrivateHire Cars', 'Your staff account ' . $id . ' has been created. Please sign in and change your temporary password immediately. Ask your administrator for the temporary password through a secure channel.');
    response(['ok' => true,
        'staff_id' => (int)$pdo->lastInsertId(),
        'onboarding' => $mail['ok'] ? 'submitted' : 'failed',
        'sandbox' => ($mail['provider'] ?? '') === 'local-preview-not-sent']);
}
if ($action === 'staff_list') {
    s2_require_staff_ready($pdo, ['Admin']);
    $page = max(1, (int)($d['page'] ?? 1));
    $per = in_array((int)($d['per'] ?? 10), [10,
        20], true) ? (int)$d['per'] : 10;
    $total = (int)$pdo->query('SELECT COUNT(*) FROM staff_accounts')->fetchColumn();
    $page = min($page, max(1, (int)ceil($total / $per)));
    $q = $pdo->prepare('SELECT staff_id,employee_id,full_name,work_email,phone,role,status,must_change_password,created_at,last_login_at,deactivated_at FROM staff_accounts ORDER BY staff_id LIMIT ? OFFSET ?');
    $q->bindValue(1, $per, PDO::PARAM_INT);
    $q->bindValue(2, ($page - 1) * $per, PDO::PARAM_INT);
    $q->execute();
    response(['ok' => true,
        'staff' => array_map('s2_sanitize_staff',
        $q->fetchAll()),
        'page' => $page,
        'per' => $per,
        'total' => $total]);
}
if ($action === 'staff_profile') {
    s2_require_staff_ready($pdo, ['Admin']);
    $id = (string)($d['id'] ?? '');
    $q = $pdo->prepare('SELECT staff_id,employee_id,full_name,work_email,phone,role,status,must_change_password,created_at,last_login_at,deactivated_at FROM staff_accounts WHERE employee_id=?');
    $q->execute([$id]);
    $s = $q->fetch();
    if (!$s) failure('Staff account not found.', 404);
    response(['ok' => true,
        'staff' => s2_sanitize_staff($s)]);
}
if ($action === 'staff_edit') {
    s2_require_staff_ready($pdo, ['Admin']);
    $id = (string)($d['id'] ?? '');
    $name = trim((string)($d['name'] ?? ''));
    $mail = strtolower(trim((string)($d['email'] ?? '')));
    $phone = trim((string)($d['phone'] ?? ''));
    $errs = [];
    if (strlen($name) < 2 || strlen($name) > 100) $errs['edName'] = 'Full name required.';
    if (!valid_email($mail)) $errs['edMail'] = 'Work email format is invalid.';
    if (!valid_phone($phone)) $errs['edPhone'] = 'Phone must be 10–11 digits.';
    if ($errs) failure('Correct the staff details.', 422, $errs);
    try {
        $q = $pdo->prepare('UPDATE staff_accounts SET full_name=?,work_email=?,phone=? WHERE employee_id=?');
        $q->execute([$name,
            $mail,
            $phone,
            $id]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) == 1062) failure('Another staff member uses that work email.', 409, ['edMail' => 'Email already exists.']);
        throw $e;
    }
    if (!$q->rowCount()) {
        $q = $pdo->prepare('SELECT 1 FROM staff_accounts WHERE employee_id=?');
        $q->execute([$id]);
        if (!$q->fetchColumn()) failure('Staff account not found.', 404);
    }
    response(['ok' => true]);
}
if ($action === 'staff_deactivate') {
    $self = s2_require_staff_ready($pdo, ['Admin']);
    $id = (string)($d['id'] ?? '');
    if ($id === $self['employee_id']) failure('You cannot deactivate your own Admin account.', 403);
    $q = $pdo->prepare("UPDATE staff_accounts SET status='Deactivated',session_version=session_version+1,deactivated_at=NOW() WHERE employee_id=? AND status='Active'");
    $q->execute([$id]);
    if (!$q->rowCount()) failure('No active account found for that ID.', 404);
    response(['ok' => true,
        'message' => 'Staff account deactivated and sessions revoked.']);
}
