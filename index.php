<?php
require_once __DIR__ . '/bootstrap.php';
$user = toolboxRequirePermission('toolbox.access');
$flash = toolboxConsumeFlash();
$pdo = db();
$visibleTools = toolboxVisibleToolsForUser($user, $pdo);
$allTools = toolboxLoadAllTools($pdo);
$adminToolCount = count(array_filter($allTools, static function (array $tool): bool {
    return (string)($tool['status'] ?? '') !== 'deleted';
}));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars(toolboxAppName(), ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="stylesheet" href="assets/css/base.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/base.css') ?: time(); ?>">
  <link rel="stylesheet" href="assets/css/buttons.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/buttons.css') ?: time(); ?>">
</head>
<body>
  <main class="page-shell">
    <div class="topbar">
      <div class="topbar-meta">
        <span class="badge"><?php echo htmlspecialchars(toolboxUserDisplayName($user), ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="badge"><?php echo htmlspecialchars(toolboxUserRoleLabel((string)$user['role']), ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
      <div class="button-group">
        <a class="button secondary" href="logout.php">Logout</a>
      </div>
    </div>

    <header class="page-hero">
      <div>
        <h1>Toolbox</h1>
        <p>Open published tools or jump into administration to manage users, roles, and tool lifecycle.</p>
      </div>
    </header>

    <?php if ($flash): ?>
      <div class="flash <?php echo htmlspecialchars((string)$flash['type'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$flash['message'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <section class="panel" style="margin-bottom:20px;">
      <div class="panel-head">
        <h2>Available tools</h2>
        <p>Only published tools that match your permissions are shown here.</p>
      </div>
      <div class="panel-body">
        <?php if (!$visibleTools): ?>
          <div class="flash" style="display:block;">No published tools are currently available for your account.</div>
        <?php else: ?>
          <div class="tool-grid">
            <?php foreach ($visibleTools as $tool): ?>
              <a class="tool-card" href="<?php echo htmlspecialchars((string)$tool['home_path'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="tool-card-title"><?php echo htmlspecialchars((string)$tool['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="tool-card-copy"><?php echo htmlspecialchars((string)$tool['description'], ENT_QUOTES, 'UTF-8'); ?></div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <?php if (toolboxUserCan('users.manage', $user) || toolboxUserCan('roles.manage', $user) || toolboxUserCan('tools.manage', $user)): ?>
      <section class="panel">
        <div class="panel-head">
          <h2>Administration</h2>
          <p>Manage access and tool lifecycle for the platform.</p>
        </div>
        <div class="panel-body">
          <div class="tool-grid">
            <?php if (toolboxUserCan('users.manage', $user)): ?>
              <a class="tool-card" href="admin_users.php">
                <div class="tool-card-title">User management</div>
                <div class="tool-card-copy">Create users, change roles, deactivate access, and reset MFA.</div>
              </a>
            <?php endif; ?>
            <?php if (toolboxUserCan('roles.manage', $user)): ?>
              <a class="tool-card" href="admin_roles.php">
                <div class="tool-card-title">Roles and permissions</div>
                <div class="tool-card-copy">Control which permissions each role receives across the toolbox.</div>
              </a>
            <?php endif; ?>
            <?php if (toolboxUserCan('tools.manage', $user)): ?>
              <a class="tool-card" href="admin_tools.php">
                <div class="tool-card-title">Tool registry</div>
                <div class="tool-card-copy">Manage Draft, Published, On hold, Archived, and Deleted states for tools. Active tools: <?php echo (int)$adminToolCount; ?>.</div>
              </a>
            <?php endif; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
