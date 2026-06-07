<?php
require_once __DIR__ . '/../../bootstrap.php';
$user = toolboxRequireToolAccess('rack-planner', 'rack_planner.access');
$flash = toolboxConsumeFlash();
$cards = [];
if (toolboxUserCan('rack_planner.edit', $user)) {
    $cards[] = [
        'title' => 'Rack editor',
        'subtitle' => 'Workspace',
        'icon' => '🧩',
        'href' => 'editor.php',
        'copy' => 'Build front and back rack layouts, manage note colours, and export printable views.',
    ];
    $cards[] = [
        'title' => 'Library',
        'subtitle' => 'Assets',
        'icon' => '📚',
        'href' => 'editor.php',
        'copy' => 'Open the Rack Editor to manage SVG library items, categories, and reusable hardware blocks.',
    ];
}
if (toolboxUserCan('rack_planner.templates.manage', $user)) {
    $cards[] = [
        'title' => 'Rack export templates',
        'subtitle' => 'Output',
        'icon' => '🖨️',
        'href' => 'saved_templates.php',
        'copy' => 'Manage branding, fields, and export defaults for your Rack Planner documents.',
    ];
}
if (toolboxUserCan('rack_planner.racks.manage', $user) || toolboxUserCan('rack_planner.edit', $user)) {
    $cards[] = [
        'title' => 'Saved racks',
        'subtitle' => 'Documents',
        'icon' => '🗂️',
        'href' => 'saved_racks.php',
        'copy' => 'Browse, duplicate, delete, and reopen saved rack documents.',
    ];
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Rack Planner</title>
  <link rel="stylesheet" href="../../assets/css/base.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/base.css') ?: time(); ?>">
  <link rel="stylesheet" href="../../assets/css/buttons.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/buttons.css') ?: time(); ?>">
</head>
<body>
  <main class="page-shell">
    <div class="topbar">
      <div class="topbar-meta">
        <span class="badge"><?php echo htmlspecialchars(toolboxUserDisplayName($user), ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="badge"><?php echo htmlspecialchars(toolboxUserRoleLabel((string)$user['role']), ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
      <div class="button-group">
        <a class="button secondary" href="../../index.php">Home</a>
        <a class="button secondary" href="../../logout.php">Logout</a>
      </div>
    </div>

    <?php if ($flash): ?>
      <div class="flash <?php echo htmlspecialchars((string)$flash['type'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$flash['message'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <header class="page-hero">
      <div>
        <h1>Rack Planner</h1>
        <p>Choose where you want to work inside the Rack Planner module.</p>
      </div>
    </header>

    <section class="panel">
      <div class="panel-head">
        <h2>Rack Planner tools</h2>
        <p>Open the editor, templates, library workflow, or saved rack management from here.</p>
      </div>
      <div class="panel-body">
        <div class="tool-grid">
          <?php foreach ($cards as $card): ?>
            <a class="tool-card tool-card-rich" href="<?php echo htmlspecialchars($card['href'], ENT_QUOTES, 'UTF-8'); ?>">
              <div class="tool-card-top">
                <div class="tool-card-icon"><?php echo htmlspecialchars($card['icon'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="tool-card-top-meta">
                  <div class="tool-card-title"><?php echo htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                  <div class="tool-card-subtitle"><?php echo htmlspecialchars($card['subtitle'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
              </div>
              <div class="tool-card-copy"><?php echo htmlspecialchars($card['copy'], ENT_QUOTES, 'UTF-8'); ?></div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  </main>
</body>
</html>
