<?php
require_once __DIR__ . '/bootstrap.php';
$user = toolboxRequirePermission('roles.manage');
$pdo = db();
$flash = toolboxConsumeFlash();
$roleDefinitions = toolboxRoleDefinitions($pdo);
$permissionDefinitions = toolboxPermissionDefinitions($pdo);
$rolePermissionMap = toolboxRolePermissionMap($pdo);
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Role management · <?php echo h(toolboxAppName()); ?></title>
  <link rel="stylesheet" href="assets/css/base.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/base.css') ?: time(); ?>">
  <link rel="stylesheet" href="assets/css/buttons.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/buttons.css') ?: time(); ?>">
  <link rel="stylesheet" href="assets/css/forms.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/forms.css') ?: time(); ?>">
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
        <h1>Roles and permissions</h1>
        <p>Manage which permissions each role receives. Super Admin always keeps full access.</p>
      </div>
    </header>

    <?php if ($flash): ?>
      <div class="flash <?php echo h((string)$flash['type']); ?>"><?php echo h((string)$flash['message']); ?></div>
    <?php endif; ?>

    <?php foreach ($roleDefinitions as $roleKey => $roleDefinition): ?>
      <?php $assigned = $rolePermissionMap[$roleKey] ?? []; ?>
      <section class="panel" style="margin-bottom:20px;">
        <div class="panel-head">
          <h2><?php echo h($roleDefinition['label']); ?></h2>
          <p><?php echo h($roleDefinition['description']); ?></p>
        </div>
        <div class="panel-body">
          <form method="post" action="role_actions.php" class="form-stack">
            <input type="hidden" name="_csrf" value="<?php echo h(toolboxCsrfToken()); ?>">
            <input type="hidden" name="action" value="save_permissions">
            <input type="hidden" name="role_key" value="<?php echo h($roleKey); ?>">
            <?php if ($roleKey === 'super_admin'): ?>
              <div class="flash success" style="display:block; margin-bottom:0;">Super Admin always has all permissions and cannot be reduced.</div>
            <?php endif; ?>
            <?php foreach ($permissionDefinitions as $permissionKey => $permissionDefinition): ?>
              <label class="field" style="padding:10px 12px; border:1px solid var(--border); border-radius:12px; gap:4px;">
                <span style="display:flex; gap:10px; align-items:flex-start;">
                  <input type="checkbox" name="permissions[]" value="<?php echo h($permissionKey); ?>" <?php echo in_array($permissionKey, $assigned, true) || $roleKey === 'super_admin' ? 'checked' : ''; ?> <?php echo $roleKey === 'super_admin' ? 'disabled' : ''; ?>>
                  <span>
                    <strong><?php echo h($permissionDefinition['label']); ?></strong><br>
                    <small class="help"><?php echo h($permissionDefinition['description']); ?></small>
                  </span>
                </span>
              </label>
            <?php endforeach; ?>
            <?php if ($roleKey !== 'super_admin'): ?>
              <div class="button-group">
                <button class="button primary" type="submit">Save permissions</button>
              </div>
            <?php endif; ?>
          </form>
        </div>
      </section>
    <?php endforeach; ?>
  </main>
</body>
</html>
