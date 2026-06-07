<?php
require_once __DIR__ . '/bootstrap.php';
$user = toolboxRequirePermission('toolbox.access');
$flash = toolboxConsumeFlash();
$pdo = db();
$visibleTools = toolboxVisibleToolsForUser($user, $pdo);
$allTools = toolboxLoadAllTools($pdo);
$statusDefinitions = toolboxToolStatusDefinitions();
$toolCounts = [
    'visible' => count($visibleTools),
    'published' => 0,
    'on_hold' => 0,
    'archived' => 0,
    'deleted' => 0,
];
foreach ($allTools as $tool) {
    $status = (string)($tool['status'] ?? 'draft');
    if (isset($toolCounts[$status])) {
        $toolCounts[$status]++;
    }
}
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
        <p>Launch published tools, keep the platform organised, and manage tool lifecycle from one place.</p>
      </div>
    </header>

    <?php if ($flash): ?>
      <div class="flash <?php echo htmlspecialchars((string)$flash['type'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$flash['message'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <section class="stats-grid" style="margin-bottom:20px;">
      <article class="stat-card">
        <div class="stat-label">Visible tools</div>
        <div class="stat-value"><?php echo (int)$toolCounts['visible']; ?></div>
        <div class="stat-copy">Tools currently available in your launcher.</div>
      </article>
      <article class="stat-card">
        <div class="stat-label">Published</div>
        <div class="stat-value"><?php echo (int)$toolCounts['published']; ?></div>
        <div class="stat-copy">Live for permitted users.</div>
      </article>
      <article class="stat-card">
        <div class="stat-label">On hold / archived</div>
        <div class="stat-value"><?php echo (int)$toolCounts['on_hold'] + (int)$toolCounts['archived']; ?></div>
        <div class="stat-copy">Temporarily paused or kept for reference.</div>
      </article>
      <article class="stat-card">
        <div class="stat-label">Deleted</div>
        <div class="stat-value"><?php echo (int)$toolCounts['deleted']; ?></div>
        <div class="stat-copy">Soft deleted and hidden from normal users.</div>
      </article>
    </section>

    <section class="panel" style="margin-bottom:20px;">
      <div class="panel-head">
        <h2>Published tools</h2>
        <p>Only published tools that match your permissions appear here.</p>
      </div>
      <div class="panel-body">
        <?php if (!$visibleTools): ?>
          <div class="flash" style="display:block;">No published tools are currently available for your account.</div>
        <?php else: ?>
          <div class="tool-grid">
            <?php foreach ($visibleTools as $tool): ?>
              <a class="tool-card tool-card-rich" href="<?php echo htmlspecialchars((string)$tool['home_path'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="tool-card-top">
                  <div class="tool-card-icon"><?php echo htmlspecialchars((string)($tool['tool_icon'] ?: '🧰'), ENT_QUOTES, 'UTF-8'); ?></div>
                  <div class="tool-card-top-meta">
                    <div class="tool-card-title"><?php echo htmlspecialchars((string)$tool['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="tool-card-subtitle"><?php echo htmlspecialchars((string)$tool['tool_key'], ENT_QUOTES, 'UTF-8'); ?></div>
                  </div>
                </div>
                <div class="tool-card-copy"><?php echo htmlspecialchars((string)$tool['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="tool-card-meta-row">
                  <?php if (!empty($tool['version_label'])): ?>
                    <span class="badge"><?php echo htmlspecialchars((string)$tool['version_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                  <?php endif; ?>
                  <span class="badge status-<?php echo htmlspecialchars(toolboxToolStatusTone((string)$tool['status']), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(toolboxToolStatusLabel((string)$tool['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <?php if (toolboxUserCan('users.manage', $user) || toolboxUserCan('roles.manage', $user) || toolboxUserCan('tools.manage', $user)): ?>
      <section class="panel">
        <div class="panel-head">
          <h2>Platform administration</h2>
          <p>Keep users, permissions, and tool lifecycle under control.</p>
        </div>
        <div class="panel-body">
          <div class="tool-grid">
            <?php if (toolboxUserCan('users.manage', $user)): ?>
              <a class="tool-card tool-card-rich" href="admin_users.php">
                <div class="tool-card-top">
                  <div class="tool-card-icon">👤</div>
                  <div class="tool-card-top-meta">
                    <div class="tool-card-title">User management</div>
                    <div class="tool-card-subtitle">Accounts</div>
                  </div>
                </div>
                <div class="tool-card-copy">Create users, change roles, deactivate access, and reset MFA.</div>
              </a>
            <?php endif; ?>
            <?php if (toolboxUserCan('roles.manage', $user)): ?>
              <a class="tool-card tool-card-rich" href="admin_roles.php">
                <div class="tool-card-top">
                  <div class="tool-card-icon">🛡️</div>
                  <div class="tool-card-top-meta">
                    <div class="tool-card-title">Roles and permissions</div>
                    <div class="tool-card-subtitle">Access model</div>
                  </div>
                </div>
                <div class="tool-card-copy">Control which permissions each role receives across the toolbox.</div>
              </a>
            <?php endif; ?>
            <?php if (toolboxUserCan('tools.manage', $user)): ?>
              <a class="tool-card tool-card-rich" href="admin_tools.php">
                <div class="tool-card-top">
                  <div class="tool-card-icon">🧰</div>
                  <div class="tool-card-top-meta">
                    <div class="tool-card-title">Tool registry</div>
                    <div class="tool-card-subtitle">Lifecycle</div>
                  </div>
                </div>
                <div class="tool-card-copy">Register tools, set statuses, and decide what shows up in the toolbox launcher.</div>
              </a>
            <?php endif; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
