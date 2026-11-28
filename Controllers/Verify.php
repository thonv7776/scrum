<?php
declare(strict_types = 1);

/** Account verification endpoint: validates one-time customer email links. */
require dirname(__DIR__) . '/Models/Bootstrap.php';
start_secure_session();
$token = (string)($_GET['token'] ?? '');
try {
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new DomainException('Invalid verification link.');
    $pdo = db();
    $pdo->beginTransaction();
    $q = $pdo->prepare('SELECT token_id,customer_id,expires_at,is_used FROM verification_tokens WHERE token_hash=? FOR UPDATE');
    $q->execute([hash('sha256',
        $token)]);
    $record = $q->fetch();
    if (!$record || (int)$record['is_used'] || strtotime($record['expires_at']) < time()) throw new DomainException('Verification link expired, invalid or already used.');
    $pdo->prepare("UPDATE customers SET account_status='Active' WHERE customer_id=? AND account_status='Pending Verification'")->execute([$record['customer_id']]);
    $pdo->prepare('UPDATE verification_tokens SET is_used=1 WHERE customer_id=?')->execute([$record['customer_id']]);
    $pdo->commit();
    $_SESSION['flash'] = ['type' => 'verified',
        'message' => 'Your account is now active. Sign in to book.'];
} catch (Throwable $e) {
    if (isset ($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash'] = ['type' => 'verify_error',
        'message' => 'Verification link expired, invalid or already used. Request another link below.'];
}
header('Location: index.html', true, 302);
exit;
