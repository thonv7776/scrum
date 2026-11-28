<?php

/** US01/03/04 - Register, resend verification, sign in/out. */
if ($action === 'register') {
    $errs = registration_errors($d);
    $email = strtolower(trim((string)($d['email'] ?? '')));
    if ($errs) failure('Please correct the highlighted registration fields.', 422, $errs);
    // Lock the normalized email across concurrent registration requests. This also
    // protects older WAMP databases that may have been imported without a UNIQUE key.
    $lockName = 'phc-register-' . substr(hash('sha256', $email), 0, 48);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) failure('Registration is busy. Please try again.', 503);
    $duplicate = false;
    $id = null;
    try {
        // LOWER(TRIM(...)) is intentional: old databases may use a case-sensitive
        // collation or contain whitespace in previously imported email addresses.
        $q = $pdo->prepare('SELECT customer_id FROM customers WHERE LOWER(TRIM(email))=? LIMIT 1');
        $q->execute([$email]);
        if ($q->fetchColumn() !== false) {
            $duplicate = true;
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO customers (full_name,email,password_hash,residential_address,telephone_number,account_status) VALUES (?,?,?,?,?,'Pending Verification')")->execute([trim((string)$d['full_name']),
                    $email,
                    password_hash((string)$d['password'],
                    PASSWORD_BCRYPT),
                    trim((string)$d['residential_address']),
                    trim((string)$d['telephone_number'])]);
                $id = (int)$pdo->lastInsertId();
                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ((int)($e->errorInfo[1] ?? 0) === 1062) $duplicate = true;
                else throw $e;
            }
        }
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $unlock = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $unlock->execute([$lockName]);
    }
    if ($duplicate) failure('An account with this email address already exists. Please sign in instead.', 409, ['email' => 'An account with this email address already exists. Please sign in instead.']);
    if (!$id) failure('Could not create your account. Please try again.', 500);
    $mail = send_verification_email($pdo, $id, $email);
    response(['ok' => true,
        'message' => 'Account created as Pending Verification. Activate it with the email link.',
        'mail' => $mail,
        'email' => $email]);
}
if ($action === 'resend') {
    $email = strtolower(trim((string)($d['email'] ?? '')));
    if (!valid_email($email)) failure('Enter your registered email address.', 422, ['email' => 'Invalid email address.']);
    $q = $pdo->prepare('SELECT customer_id,account_status FROM customers WHERE email=?');
    $q->execute([$email]);
    $customer = $q->fetch();
    if ($customer && $customer['account_status'] === 'Pending Verification') {
        // rate limit repeated requests; delay new verification tokens for 60 seconds
        $q = $pdo->prepare('SELECT created_at FROM verification_tokens WHERE customer_id=? ORDER BY token_id DESC LIMIT 1');
        $q->execute([$customer['customer_id']]);
        $last = $q->fetchColumn();
        if ($last && time() - strtotime($last) < 60) failure('Please wait 60 seconds before requesting another link.', 429);
        $mail = send_verification_email($pdo, (int)$customer['customer_id'], $email);
    } else $mail = ['delivery' => 'not_requested'];
    response(['ok' => true,
        'message' => 'If this account requires activation, a new link has been requested.',
        'mail' => $mail]);
}
if ($action === 'login') {
    $email = strtolower(trim((string)($d['email'] ?? '')));
    $pass = (string)($d['password'] ?? '');
    $q = $pdo->prepare('SELECT customer_id,full_name,email,password_hash,account_status FROM customers WHERE email=? LIMIT 1');
    $q->execute([$email]);
    $user = $q->fetch();
    if (!$user || !password_verify($pass, $user['password_hash'])) failure('Incorrect email or password', 401);
    if ($user['account_status'] !== 'Active') failure('Your account is Pending Verification. Please check your email inbox.', 403);
    session_regenerate_id(true);
    $_SESSION['customer_id'] = (int)$user['customer_id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    unset ($_SESSION['draft_booking_id']);
    response(['ok' => true,
        'csrf' => $_SESSION['csrf'],
        'user' => ['customer_id' => (int)$user['customer_id'],
        'full_name' => $user['full_name'],
        'email' => $user['email']]]);
}
if ($action === 'logout') {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    response(['ok' => true,
        'csrf' => $_SESSION['csrf']]);
}
