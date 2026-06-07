<?php
require_once __DIR__ . '/bootstrap.php';
$currentUser = toolboxRequirePermission('roles.manage');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    toolboxRedirect('admin_roles.php');
}
if (!toolboxVerifyCsrfToken($_POST['_csrf'] ?? null)) {
    toolboxFlash('error', 'Your session expired. Please try again.');
    toolboxRedirect('admin_roles.php');
}

$action = (string)($_POST['action'] ?? '');
$roleKey = (string)($_POST['role_key'] ?? '');
$roleDefinitions = toolboxRoleDefinitions($pdo);
$permissionDefinitions = toolboxPermissionDefinitions($pdo);

try {
    if ($action !== 'save_permissions') {
        throw new RuntimeException('Unknown role action.');
    }
    if (!isset($roleDefinitions[$roleKey])) {
        throw new RuntimeException('Unknown role.');
    }
    if ($roleKey === 'super_admin') {
        throw new RuntimeException('Super Admin permissions are fixed.');
    }

    $permissions = array_values(array_unique(array_filter(array_map('strval', $_POST['permissions'] ?? []))));
    foreach ($permissions as $permissionKey) {
        if (!isset($permissionDefinitions[$permissionKey])) {
            throw new RuntimeException('Unknown permission selected.');
        }
    }

    $pdo->beginTransaction();
    $delete = $pdo->prepare('DELETE FROM toolbox_role_permissions WHERE role_key = :role_key');
    $delete->execute([':role_key' => $roleKey]);
    $insert = $pdo->prepare('INSERT INTO toolbox_role_permissions (role_key, permission_key) VALUES (:role_key, :permission_key)');
    foreach ($permissions as $permissionKey) {
        $insert->execute([':role_key' => $roleKey, ':permission_key' => $permissionKey]);
    }
    $pdo->commit();
    toolboxAudit($pdo, (int)$currentUser['id'], 'role_permissions_updated', ['role_key' => $roleKey, 'permissions' => $permissions]);
    toolboxFlash('success', 'Role permissions updated.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    toolboxFlash('error', $e->getMessage());
}

toolboxRedirect('admin_roles.php');
