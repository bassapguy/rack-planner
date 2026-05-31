<?php
require_once __DIR__ . '/bootstrap.php';

$pdo = null;
try {
    $pdo = db();
} catch (Throwable $e) {
    $pdo = null;
}

$user = $pdo ? toolboxCurrentUser($pdo) : null;
if ($pdo && $user) {
    toolboxAudit($pdo, (int)$user['id'], 'logout');
}

toolboxLogout();
toolboxFlash('success', 'You have been signed out.');
toolboxRedirect('login.php');
