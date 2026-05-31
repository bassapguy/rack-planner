<?php ?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Toolbox</title>
  <link rel="stylesheet" href="assets/css/base.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/base.css') ?: time(); ?>">
  <link rel="stylesheet" href="assets/css/buttons.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/buttons.css') ?: time(); ?>">
</head>
<body>
  <main class="page-shell">
    <header class="page-hero">
      <div>
        <h1>Toolbox</h1>
        <p>Start from here and open the tools you need. Rack Planner is now the first modular tool inside this app shell.</p>
      </div>
    </header>

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
