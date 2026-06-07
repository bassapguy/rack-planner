<?php
require_once __DIR__ . '/bootstrap.php';
$currentUser = toolboxRequirePermission('users.manage');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    toolboxRedirect('admin_users.php');
}
if (!toolboxVerifyCsrfToken($_POST['_csrf'] ?? null)) {
    toolboxFlash('error', 'Your session expired. Please try again.');
    toolboxRedirect('admin_users.php');
}

$action = (string)($_POST['action'] ?? '');
$roleDefinitions = toolboxRoleDefinitions($pdo);
$isSuperAdmin = ((string)($currentUser['role'] ?? '') === 'super_admin');

function toolboxLoadManagedUser(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM toolbox_users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

try {
    switch ($action) {
        case 'create_user':
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
            $password = (string)($_POST['password'] ?? '');
            $role = (string)($_POST['role'] ?? 'viewer');
            $isActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

            if ($fullName === '' || $email === '' || $password === '') {
                throw new RuntimeException('Fill in all required user fields.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid email address.');
            }
            if (!isset($roleDefinitions[$role])) {
                throw new RuntimeException('Choose a valid role.');
            }
            if (!$isSuperAdmin && $role === 'super_admin') {
                throw new RuntimeException('Only a super admin can create another super admin.');
            }
            if (strlen($password) < 12) {
                throw new RuntimeException('Use a password with at least 12 characters.');
            }

            $userId = toolboxCreateUser($pdo, [
                'email' => $email,
                'full_name' => $fullName,
                'password_hash' => toolboxPasswordHash($password),
                'role' => $role,
            ]);
            $stmt = $pdo->prepare('UPDATE toolbox_users SET is_active = :is_active WHERE id = :id');
            $stmt->execute([':is_active' => $isActive, ':id' => $userId]);
            toolboxAudit($pdo, (int)$currentUser['id'], 'user_created', ['target_user_id' => $userId, 'email' => $email, 'role' => $role]);
            toolboxFlash('success', 'User created successfully.');
            break;

        case 'update_user':
            $userId = (int)($_POST['user_id'] ?? 0);
            $target = toolboxLoadManagedUser($pdo, $userId);
            if (!$target) {
                throw new RuntimeException('User not found.');
            }
            if (!$isSuperAdmin && (string)$target['role'] === 'super_admin') {
                throw new RuntimeException('Only a super admin can edit another super admin.');
            }
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
            $password = (string)($_POST['password'] ?? '');
            $role = (string)($_POST['role'] ?? 'viewer');
            $isActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;
            if ($fullName === '' || $email === '') {
                throw new RuntimeException('Fill in all required user fields.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid email address.');
            }
            if (!isset($roleDefinitions[$role])) {
                throw new RuntimeException('Choose a valid role.');
            }
            if (!$isSuperAdmin && $role === 'super_admin') {
                throw new RuntimeException('Only a super admin can assign the super admin role.');
            }
            if ((int)$currentUser['id'] === $userId && $isActive !== 1) {
                throw new RuntimeException('You cannot deactivate your own account.');
            }
            if ($password !== '' && strlen($password) < 12) {
                throw new RuntimeException('Use a password with at least 12 characters.');
            }
            $sql = 'UPDATE toolbox_users SET email = :email, full_name = :full_name, role = :role, is_active = :is_active, updated_at = CURRENT_TIMESTAMP';
            $params = [
                ':email' => $email,
                ':full_name' => $fullName,
                ':role' => $role,
                ':is_active' => $isActive,
                ':id' => $userId,
            ];
            if ($password !== '') {
                $sql .= ', password_hash = :password_hash';
                $params[':password_hash'] = toolboxPasswordHash($password);
            }
            $sql .= ' WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            toolboxAudit($pdo, (int)$currentUser['id'], 'user_updated', ['target_user_id' => $userId, 'email' => $email, 'role' => $role]);
            toolboxFlash('success', 'User updated successfully.');
            break;

        case 'toggle_active':
            $userId = (int)($_POST['user_id'] ?? 0);
            $target = toolboxLoadManagedUser($pdo, $userId);
            if (!$target) {
                throw new RuntimeException('User not found.');
            }
            if ((int)$currentUser['id'] === $userId) {
                throw new RuntimeException('You cannot change your own active state here.');
            }
            if (!$isSuperAdmin && (string)$target['role'] === 'super_admin') {
                throw new RuntimeException('Only a super admin can change another super admin.');
            }
            $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE toolbox_users SET is_active = :is_active, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute([':is_active' => $isActive, ':id' => $userId]);
            toolboxAudit($pdo, (int)$currentUser['id'], $isActive ? 'user_activated' : 'user_deactivated', ['target_user_id' => $userId]);
            toolboxFlash('success', $isActive ? 'User activated.' : 'User deactivated.');
            break;

        case 'reset_mfa':
            $userId = (int)($_POST['user_id'] ?? 0);
            $target = toolboxLoadManagedUser($pdo, $userId);
            if (!$target) {
                throw new RuntimeException('User not found.');
            }
            if (!$isSuperAdmin && (string)$target['role'] === 'super_admin') {
                throw new RuntimeException('Only a super admin can reset MFA for another super admin.');
            }
            $stmt = $pdo->prepare('UPDATE toolbox_users SET mfa_secret = NULL, mfa_enabled = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute([':id' => $userId]);
            toolboxAudit($pdo, (int)$currentUser['id'], 'user_mfa_reset', ['target_user_id' => $userId]);
            toolboxFlash('success', 'MFA was reset for that user.');
            break;

        default:
            throw new RuntimeException('Unknown user action.');
    }
} catch (Throwable $e) {
    toolboxFlash('error', $e->getMessage());
}

toolboxRedirect('admin_users.php');
