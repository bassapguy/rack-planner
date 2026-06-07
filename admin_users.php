<?php
require_once __DIR__ . '/bootstrap.php';
$user = toolboxRequirePermission('users.manage');
$pdo = db();
$flash = toolboxConsumeFlash();
$databaseError = null;
$search = trim((string)($_GET['q'] ?? ''));
$editId = (int)($_GET['edit'] ?? 0);
$editUser = null;
$roleDefinitions = toolboxRoleDefinitions($pdo);

try {
    $sql = 'SELECT id, email, full_name, role, is_active, mfa_enabled, last_login_at, created_at, updated_at FROM toolbox_users';
    $params = [];
    if ($search !== '') {
        $sql .= ' WHERE email LIKE :term OR full_name LIKE :term OR role LIKE :term';
        $params[':term'] = '%' . $search . '%';
    }
    $sql .= ' ORDER BY FIELD(role, "super_admin","admin","editor","viewer"), full_name ASC, email ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
    if ($editId > 0) {
        $stmt = $pdo->prepare('SELECT id, email, full_name, role, is_active FROM toolbox_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $editId]);
        $editUser = $stmt->fetch() ?: null;
    }
} catch (Throwable $e) {
    $databaseError = $e->getMessage();
    $users = [];
}

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$currentRole = (string)($user['role'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>User management · <?php echo h(toolboxAppName()); ?></title>
  <link rel="stylesheet" href="assets/css/base.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/base.css') ?: time(); ?>">
  <link rel="stylesheet" href="assets/css/buttons.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/buttons.css') ?: time(); ?>">
  <link rel="stylesheet" href="assets/css/forms.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/forms.css') ?: time(); ?>">
  <link rel="stylesheet" href="assets/css/tables.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/tables.css') ?: time(); ?>">
</head>
<body>
  <main class="page-shell">
    <div class="topbar">
      <div class="topbar-meta">
        <span class="badge"><?php echo h(toolboxUserDisplayName($user)); ?></span>
        <span class="badge"><?php echo h(toolboxUserRoleLabel((string)$user['role'])); ?></span>
      </div>
      <div class="button-group">
        <a class="button secondary" href="index.php">Home</a>
        <a class="button secondary" href="logout.php">Logout</a>
      </div>
    </div>

    <header class="page-hero">
      <div>
        <h1>User management</h1>
        <p>Create users, change roles, deactivate access, and reset MFA.</p>
      </div>
    </header>

    <?php if ($flash): ?>
      <div class="flash <?php echo h((string)$flash['type']); ?>"><?php echo h((string)$flash['message']); ?></div>
    <?php endif; ?>
    <?php if ($databaseError !== null): ?>
      <div class="flash error">Database error: <?php echo h($databaseError); ?></div>
    <?php endif; ?>

    <section class="panel" style="margin-bottom:20px;">
      <div class="panel-head">
        <h2><?php echo $editUser ? 'Edit user' : 'Create user'; ?></h2>
        <p><?php echo $editUser ? 'Update user details, role, and active state.' : 'Create a new user account. MFA remains required on first login.'; ?></p>
      </div>
      <div class="panel-body">
        <form method="post" action="user_actions.php">
          <input type="hidden" name="_csrf" value="<?php echo h(toolboxCsrfToken()); ?>">
          <input type="hidden" name="action" value="<?php echo $editUser ? 'update_user' : 'create_user'; ?>">
          <?php if ($editUser): ?>
            <input type="hidden" name="user_id" value="<?php echo (int)$editUser['id']; ?>">
          <?php endif; ?>
          <div class="field">
            <label for="full_name">Full name</label>
            <input type="text" id="full_name" name="full_name" required value="<?php echo h($editUser['full_name'] ?? ''); ?>">
          </div>
          <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required value="<?php echo h($editUser['email'] ?? ''); ?>">
          </div>
          <div class="field">
            <label for="role">Role</label>
            <select id="role" name="role" required>
              <?php foreach ($roleDefinitions as $roleKey => $definition): ?>
                <?php if ($currentRole !== 'super_admin' && $roleKey === 'super_admin') { continue; } ?>
                <option value="<?php echo h($roleKey); ?>" <?php echo (($editUser['role'] ?? 'viewer') === $roleKey) ? 'selected' : ''; ?>><?php echo h($definition['label']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="password"><?php echo $editUser ? 'New password (optional)' : 'Password'; ?></label>
            <input type="password" id="password" name="password" <?php echo $editUser ? '' : 'required'; ?>>
            <small class="help">Use at least 12 characters.</small>
          </div>
          <div class="field">
            <label for="is_active">Status</label>
            <select id="is_active" name="is_active">
              <option value="1" <?php echo ((int)($editUser['is_active'] ?? 1) === 1) ? 'selected' : ''; ?>>Active</option>
              <option value="0" <?php echo ((int)($editUser['is_active'] ?? 1) === 0) ? 'selected' : ''; ?>>Inactive</option>
            </select>
          </div>
          <div class="button-group">
            <button class="button primary" type="submit"><?php echo $editUser ? 'Save user' : 'Create user'; ?></button>
            <?php if ($editUser): ?>
              <a class="button secondary" href="admin_users.php">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head" style="display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap;">
        <div>
          <h2>All users</h2>
          <p>Search and manage existing accounts.</p>
        </div>
        <form class="button-group" method="get" action="admin_users.php">
          <input type="search" name="q" placeholder="Search users" value="<?php echo h($search); ?>">
          <button class="button secondary" type="submit">Filter</button>
        </form>
      </div>
      <div class="panel-body">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>User</th>
                <th>Role</th>
                <th>Status</th>
                <th>MFA</th>
                <th>Last login</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $row): ?>
                <?php $isSelf = (int)$row['id'] === (int)$user['id']; ?>
                <tr>
                  <td>
                    <strong><?php echo h($row['full_name'] ?: $row['email']); ?></strong><br>
                    <span class="help"><?php echo h($row['email']); ?></span>
                  </td>
                  <td><?php echo h(toolboxUserRoleLabel((string)$row['role'])); ?></td>
                  <td><?php echo ((int)$row['is_active'] === 1) ? 'Active' : 'Inactive'; ?></td>
                  <td><?php echo ((int)$row['mfa_enabled'] === 1) ? 'Enabled' : 'Not set'; ?></td>
                  <td><?php echo h($row['last_login_at'] ?: '—'); ?></td>
                  <td>
                    <div class="button-group">
                      <a class="button secondary small" href="admin_users.php?edit=<?php echo (int)$row['id']; ?>">Edit</a>
                      <?php if (!$isSelf): ?>
                        <form class="inline-form" method="post" action="user_actions.php">
                          <input type="hidden" name="_csrf" value="<?php echo h(toolboxCsrfToken()); ?>">
                          <input type="hidden" name="action" value="toggle_active">
                          <input type="hidden" name="user_id" value="<?php echo (int)$row['id']; ?>">
                          <input type="hidden" name="is_active" value="<?php echo ((int)$row['is_active'] === 1) ? '0' : '1'; ?>">
                          <button class="button secondary small" type="submit"><?php echo ((int)$row['is_active'] === 1) ? 'Deactivate' : 'Activate'; ?></button>
                        </form>
                      <?php endif; ?>
                      <form class="inline-form" method="post" action="user_actions.php">
                        <input type="hidden" name="_csrf" value="<?php echo h(toolboxCsrfToken()); ?>">
                        <input type="hidden" name="action" value="reset_mfa">
                        <input type="hidden" name="user_id" value="<?php echo (int)$row['id']; ?>">
                        <button class="button secondary small" type="submit">Reset MFA</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$users): ?>
                <tr><td colspan="6">No users found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </main>
</body>
</html>
