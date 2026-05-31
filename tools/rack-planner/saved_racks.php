<?php
require_once __DIR__ . '/../../bootstrap.php';
$user = toolboxRequireLogin();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rack_repository.php';

$search = trim((string)($_GET['q'] ?? ''));
$databaseError = null;
$racks = [];

try {
    $pdo = db();
    $racks = rackPlannerLoadRackManagementRows($pdo, $search);
} catch (Throwable $e) {
    $databaseError = $e->getMessage();
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Saved racks beheren</title>
  <style>
    :root {
      --bg: #eef3f8;
      --panel: #ffffff;
      --panel-soft: #f7f9fc;
      --text: #172033;
      --muted: #667085;
      --border: #d7deea;
      --accent: #2d5bff;
      --danger: #d92d20;
      --success: #0f9d58;
      --shadow: 0 18px 44px rgba(15, 23, 42, 0.10);
      font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: linear-gradient(180deg, #f6f8fb 0%, var(--bg) 100%);
      color: var(--text);
    }
    .page {
      max-width: 1480px;
      margin: 0 auto;
      padding: 28px;
      display: grid;
      gap: 20px;
    }
    .hero {
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
      gap: 16px;
      flex-wrap: wrap;
    }
    .hero h1 {
      margin: 0;
      font-size: 32px;
      line-height: 1.05;
      letter-spacing: -0.03em;
    }
    .hero p {
      margin: 8px 0 0;
      color: var(--muted);
      max-width: 760px;
    }
    .hero-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }
    .button,
    button {
      border: 0;
      border-radius: 14px;
      padding: 12px 16px;
      font: inherit;
      font-weight: 700;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }
    .button.primary { background: var(--accent); color: #fff; box-shadow: 0 14px 32px rgba(45,91,255,.18); }
    .button.secondary { background: #fff; color: var(--text); border: 1px solid var(--border); }
    .button.danger { background: #fff; color: var(--danger); border: 1px solid rgba(217,45,32,.24); }
    .panel {
      background: var(--panel);
      border: 1px solid rgba(215, 222, 234, .9);
      border-radius: 24px;
      box-shadow: var(--shadow);
      overflow: hidden;
    }
    .panel-head {
      padding: 22px 24px 0;
    }
    .panel-head h2 {
      margin: 0;
      font-size: 20px;
      letter-spacing: -0.02em;
    }
    .panel-head p {
      margin: 8px 0 0;
      color: var(--muted);
    }
    .panel-body {
      padding: 24px;
    }
    .flash {
      padding: 14px 16px;
      border-radius: 16px;
      font-weight: 600;
      margin-bottom: 18px;
    }
    .flash.success { background: rgba(15,157,88,.10); color: #0d7b47; border: 1px solid rgba(15,157,88,.18); }
    .flash.error { background: rgba(217,45,32,.08); color: #a1261c; border: 1px solid rgba(217,45,32,.18); }
    .filters {
      display: grid;
      grid-template-columns: minmax(280px, 1.4fr) auto auto;
      gap: 12px;
      align-items: end;
    }
    .field { display: grid; gap: 8px; }
    .field label { font-weight: 700; font-size: 14px; }
    .field input {
      width: 100%;
      min-width: 0;
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 12px 14px;
      font: inherit;
      background: #fff;
    }
    .table-wrap {
      overflow: auto;
      border-radius: 18px;
      border: 1px solid var(--border);
      background: #fff;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 980px;
    }
    th, td {
      text-align: left;
      padding: 14px 16px;
      vertical-align: top;
      border-bottom: 1px solid #e9edf5;
    }
    th {
      background: var(--panel-soft);
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: var(--muted);
    }
    tbody tr:hover { background: #fafcff; }
    .rack-title {
      font-weight: 800;
      letter-spacing: -.02em;
      display: block;
      margin-bottom: 4px;
    }
    .rack-meta,
    .muted { color: var(--muted); }
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      border-radius: 999px;
      padding: 6px 10px;
      background: #f3f5fb;
      border: 1px solid var(--border);
      font-size: 12px;
      font-weight: 700;
      white-space: nowrap;
    }
    .row-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }
    .inline-form { margin: 0; }
    .empty-state {
      padding: 42px 24px;
      text-align: center;
      color: var(--muted);
    }
    .empty-state strong {
      display: block;
      color: var(--text);
      margin-bottom: 6px;
      font-size: 18px;
    }
    @media (max-width: 920px) {
      .page { padding: 18px; }
      .filters { grid-template-columns: 1fr; }
      .hero { align-items: stretch; }
      .hero-actions { width: 100%; }
      .hero-actions .button { flex: 1 1 auto; }
    }
  </style>
</head>
<body>
  <div class="page">
    <div class="hero">
      <div>
        <h1>Saved racks</h1>
        <p>Manage your saved rack documents here. You can open racks in the editor, duplicate them as a starting point for a variation, delete them and quickly filter by name, location, project or template.</p>
      </div>
      <div class="hero-actions">
        <a class="button secondary" href="../../index.php">Home</a>
        <a class="button primary" href="editor.php?new=1">New rack</a>
      </div>
    </div>

    <section class="panel">
      <div class="panel-head">
        <h2>Manage</h2>
        <p>Table view with filtering, opening, duplicating and deleting.</p>
      </div>
      <div class="panel-body">
        <?php if (isset($_GET['status']) && $_GET['status'] === 'deleted'): ?>
          <div class="flash success">Rack deleted.</div>
        <?php elseif (isset($_GET['status']) && $_GET['status'] === 'duplicated'): ?>
          <div class="flash success">Rack gedupliceerd.</div>
        <?php elseif (isset($_GET['status']) && $_GET['status'] === 'error'): ?>
          <div class="flash error"><?php echo h($_GET['message'] ?? 'Actie mislukt.'); ?></div>
        <?php endif; ?>

        <?php if ($databaseError !== null): ?>
          <div class="flash error"><?php echo h($databaseError); ?></div>
        <?php endif; ?>

        <form class="filters" method="get" action="saved_racks.php">
          <div class="field">
            <label for="q">Filter</label>
            <input id="q" name="q" type="text" value="<?php echo h($search); ?>" placeholder="Search by rack name, location, project, version or template">
          </div>
          <button class="button primary" type="submit">Filter toepassen</button>
          <a class="button secondary" href="saved_racks.php">Reset</a>
        </form>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h2>All saved racks</h2>
        <p><?php echo count($racks); ?> resultaat<?php echo count($racks) === 1 ? '' : 'en'; ?>.</p>
      </div>
      <div class="panel-body">
        <?php if ($racks !== []): ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Rack</th>
                  <th>Location / project</th>
                  <th>Template</th>
                  <th>Version / date</th>
                  <th>Inhoud</th>
                  <th>Laatst bijgewerkt</th>
                  <th>Acties</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($racks as $rack): ?>
                  <tr>
                    <td>
                      <span class="rack-title"><?php echo h($rack['rackName']); ?></span>
                      <div class="rack-meta"><?php echo (int)$rack['rackUnits']; ?> U · ID <?php echo (int)$rack['id']; ?></div>
                    </td>
                    <td>
                      <div><?php echo h($rack['location'] !== '' ? $rack['location'] : '—'); ?></div>
                      <div class="muted"><?php echo h($rack['project'] !== '' ? $rack['project'] : '—'); ?></div>
                    </td>
                    <td><span class="badge"><?php echo h($rack['templateName']); ?></span></td>
                    <td>
                      <div><?php echo h($rack['versionLabel']); ?></div>
                      <div class="muted"><?php echo h($rack['issueDate']); ?></div>
                    </td>
                    <td>
                      <span class="badge"><?php echo (int)$rack['itemCount']; ?> item<?php echo (int)$rack['itemCount'] === 1 ? '' : 's'; ?></span>
                    </td>
                    <td class="muted"><?php echo h($rack['updatedAt'] ?? ''); ?></td>
                    <td>
                      <div class="row-actions">
                        <a class="button secondary" href="editor.php?rack=<?php echo (int)$rack['id']; ?>">Open</a>
                        <form class="inline-form" method="post" action="rack_actions.php">
                          <input type="hidden" name="action" value="duplicate">
                          <input type="hidden" name="rack_id" value="<?php echo (int)$rack['id']; ?>">
                          <input type="hidden" name="return_url" value="saved_racks.php<?php echo $search !== '' ? '?q=' . urlencode($search) : ''; ?>">
                          <button class="button secondary" type="submit">Dupliceren</button>
                        </form>
                        <form class="inline-form" method="post" action="rack_actions.php" onsubmit="return confirm('Are you sure you want to delete this rack?');">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="rack_id" value="<?php echo (int)$rack['id']; ?>">
                          <input type="hidden" name="return_url" value="saved_racks.php<?php echo $search !== '' ? '?q=' . urlencode($search) : ''; ?>">
                          <button class="button danger" type="submit">Delete</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="empty-state">
            <strong>No racks found</strong>
            Pas je filter aan of maak eerst een nieuw rack aan in de editor.
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</body>
</html>
