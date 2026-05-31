<?php
require_once __DIR__ . '/bootstrap.php';
$user = toolboxRequireLogin();
$flash = toolboxConsumeFlash();
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
        <p>Start here and open the tools you need.</p>
      </div>
    </header>

    <?php if ($flash): ?>
      <div class="flash <?php echo htmlspecialchars((string)$flash['type'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$flash['message'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <section class="panel">
      <div class="panel-head">
        <h2>Available tools</h2>
        <p>Open a tool home page to work within a dedicated module.</p>
      </div>
      <div class="panel-body">
        <div class="tool-grid">
          <a class="tool-card" href="tools/rack-planner/index.php">
            <div class="tool-card-title">Rack Planner</div>
            <div class="tool-card-copy">Open the rack planning module with editor, export templates, library management, and saved racks.</div>
          </a>
        </div>
      </div>
    </section>
  </main>
</body>
</html>
