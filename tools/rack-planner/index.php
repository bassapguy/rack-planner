<?php ?><!DOCTYPE html>
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
    <header class="page-hero">
      <div>
        <h1>Rack Planner</h1>
        <p>Choose where you want to work inside the Rack Planner module.</p>
      </div>
      <div class="button-group">
        <a class="button secondary" href="../../index.php">Home</a>
      </div>
    </header>

    <section class="panel">
      <div class="panel-head">
        <h2>Rack Planner tools</h2>
        <p>Open the editor, template management, library workflow, or saved rack management from here.</p>
      </div>
      <div class="panel-body">
        <div class="tool-grid">
          <a class="tool-card" href="editor.php">
            <div class="tool-card-title">Rack editor</div>
            <div class="tool-card-copy">Build front and back rack layouts, manage comments, and export printable views.</div>
          </a>
          <a class="tool-card" href="saved_templates.php">
            <div class="tool-card-title">Rack export templates</div>
            <div class="tool-card-copy">Manage template branding, fields, and export defaults.</div>
          </a>
          <a class="tool-card" href="editor.php">
            <div class="tool-card-title">Library</div>
            <div class="tool-card-copy">Open the Rack Editor to manage SVG library items and categories.</div>
          </a>
          <a class="tool-card" href="saved_racks.php">
            <div class="tool-card-title">Saved Racks</div>
            <div class="tool-card-copy">Browse, duplicate, delete, and reopen saved rack documents.</div>
          </a>
        </div>
      </div>
    </section>
  </main>
</body>
</html>
