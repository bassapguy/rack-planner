<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rack_repository.php';

$search = trim((string)($_GET['q'] ?? ''));
$databaseError = null;
$templates = [];

try {
    $pdo = db();
    $templates = rackPlannerLoadTemplateManagementRows($pdo, $search);
} catch (Throwable $e) {
    $databaseError = $e->getMessage();
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
$status = (string)($_GET['status'] ?? '');
$message = (string)($_GET['message'] ?? '');
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Templates beheren</title>
  <style>
    :root {
      --bg: #eef3f8; --panel: #ffffff; --panel-soft: #f7f9fc; --text: #172033; --muted: #667085; --border: #d7deea; --accent: #2d5bff; --danger: #d92d20; --success: #0f9d58; --shadow: 0 18px 44px rgba(15,23,42,.10); font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
    }
    * { box-sizing: border-box; }
    body { margin:0; background: linear-gradient(180deg, #f6f8fb 0%, var(--bg) 100%); color: var(--text); }
    .page { max-width: 1480px; margin:0 auto; padding:28px; display:grid; gap:20px; }
    .hero { display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; }
    .hero h1 { margin:0; font-size:32px; line-height:1.05; letter-spacing:-.03em; }
    .hero p { margin:8px 0 0; color:var(--muted); max-width:760px; }
    .hero-actions, .row-actions, .filters-actions { display:flex; gap:10px; flex-wrap:wrap; }
    .button, button { border:0; border-radius:14px; padding:12px 16px; font:inherit; font-weight:700; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:8px; }
    .button.primary { background:var(--accent); color:#fff; box-shadow:0 14px 32px rgba(45,91,255,.18); }
    .button.secondary { background:#fff; color:var(--text); border:1px solid var(--border); }
    .button.danger { background:#fff; color:var(--danger); border:1px solid rgba(217,45,32,.24); }
    .panel { background:var(--panel); border:1px solid rgba(215,222,234,.9); border-radius:24px; box-shadow:var(--shadow); overflow:hidden; }
    .panel-head { padding:22px 24px 0; }
    .panel-head h2 { margin:0; font-size:20px; letter-spacing:-.02em; }
    .panel-head p { margin:8px 0 0; color:var(--muted); }
    .panel-body { padding:24px; }
    .flash { padding:14px 16px; border-radius:16px; font-weight:600; margin-bottom:18px; }
    .flash.success { background:rgba(15,157,88,.10); color:#0d7b47; border:1px solid rgba(15,157,88,.18); }
    .flash.error { background:rgba(217,45,32,.08); color:#a1261c; border:1px solid rgba(217,45,32,.18); }
    .filters { display:grid; grid-template-columns:minmax(280px,1.4fr) auto; gap:12px; align-items:end; }
    .field { display:grid; gap:8px; }
    .field label { font-weight:700; font-size:14px; }
    .field input { width:100%; min-width:0; border:1px solid var(--border); border-radius:14px; padding:12px 14px; font:inherit; background:#fff; }
    .table-wrap { overflow:auto; border-radius:18px; border:1px solid var(--border); background:#fff; }
    table { width:100%; border-collapse:collapse; min-width:1040px; }
    th, td { text-align:left; padding:14px 16px; vertical-align:top; border-bottom:1px solid #e9edf5; }
    th { background:var(--panel-soft); font-size:13px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
    tbody tr:hover { background:#fafcff; }
    .title { font-weight:800; letter-spacing:-.02em; display:block; margin-bottom:4px; }
    .muted { color:var(--muted); }
    .badge { display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:6px 10px; background:#f3f5fb; border:1px solid var(--border); font-size:12px; font-weight:700; white-space:nowrap; }
    .empty-state { padding:42px 24px; text-align:center; color:var(--muted); }
    .empty-state strong { display:block; color:var(--text); margin-bottom:6px; font-size:18px; }
    .inline-form { margin:0; }
    @media (max-width: 920px) { .page { padding:18px; } .filters { grid-template-columns:1fr; } }
  </style>
</head>
<body>
  <div class="page">
    <section class="hero">
      <div>
        <h1>Templates beheren</h1>
        <p>Beheer je exporttemplates los van de editor. Dupliceren, verwijderen, filteren en open direct een template om velden of branding aan te passen.</p>
      </div>
      <div class="hero-actions">
        <a class="button secondary" href="index.php">Terug naar planner</a>
        <a class="button primary" href="template_editor.php">Nieuw template</a>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h2>Overzicht</h2>
        <p>Zoek op naam, slug of documenttitel en beheer je templates vanuit één pagina.</p>
      </div>
      <div class="panel-body">
        <?php if ($databaseError !== null): ?>
          <div class="flash error"><?php echo h($databaseError); ?></div>
        <?php elseif ($status === 'saved'): ?>
          <div class="flash success">Template opgeslagen.</div>
        <?php elseif ($status === 'duplicated'): ?>
          <div class="flash success">Template gedupliceerd.</div>
        <?php elseif ($status === 'deleted'): ?>
          <div class="flash success">Template verwijderd.</div>
        <?php elseif ($status === 'error'): ?>
          <div class="flash error"><?php echo h($message !== '' ? $message : 'Actie mislukt.'); ?></div>
        <?php endif; ?>

        <form class="filters" method="get">
          <div class="field">
            <label for="q">Filter</label>
            <input id="q" name="q" type="search" value="<?php echo h($search); ?>" placeholder="Zoek op naam, slug of documenttitel">
          </div>
          <div class="filters-actions">
            <button class="button secondary" type="submit">Filteren</button>
            <a class="button secondary" href="saved_templates.php">Reset</a>
          </div>
        </form>

        <div class="table-wrap" style="margin-top:20px;">
          <?php if ($templates === []): ?>
            <div class="empty-state">
              <strong>Geen templates gevonden</strong>
              <span>Maak je eerste template aan of pas je filter aan.</span>
            </div>
          <?php else: ?>
            <table>
              <thead>
                <tr>
                  <th>Template</th>
                  <th>Slug</th>
                  <th>Logo</th>
                  <th>Velden</th>
                  <th>Gebruikt in racks</th>
                  <th>Laatste update</th>
                  <th>Acties</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($templates as $template): ?>
                  <tr>
                    <td>
                      <span class="title"><?php echo h($template['name']); ?></span>
                      <div class="muted"><?php echo h($template['documentTitle']); ?></div>
                      <?php if ($template['isDefault']): ?><div class="badge" style="margin-top:8px;">Default</div><?php endif; ?>
                    </td>
                    <td><span class="badge"><?php echo h($template['slug']); ?></span></td>
                    <td><?php echo $template['logoPath'] !== '' ? '<span class="badge">Ja</span>' : '<span class="muted">Geen logo</span>'; ?></td>
                    <td><span class="badge"><?php echo (int)$template['fieldCount']; ?> velden</span></td>
                    <td><span class="badge"><?php echo (int)$template['rackCount']; ?> racks</span></td>
                    <td class="muted"><?php echo h($template['updatedAt'] ?? ''); ?></td>
                    <td>
                      <div class="row-actions">
                        <a class="button secondary" href="template_editor.php?id=<?php echo (int)$template['id']; ?>">Openen</a>
                        <form class="inline-form" method="post" action="template_actions.php">
                          <input type="hidden" name="action" value="duplicate">
                          <input type="hidden" name="template_id" value="<?php echo (int)$template['id']; ?>">
                          <input type="hidden" name="return_url" value="saved_templates.php<?php echo $search !== '' ? '?q=' . rawurlencode($search) : ''; ?>">
                          <button class="button secondary" type="submit">Dupliceren</button>
                        </form>
                        <form class="inline-form" method="post" action="template_actions.php" onsubmit="return confirm('Weet je zeker dat je deze template wilt verwijderen?');">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="template_id" value="<?php echo (int)$template['id']; ?>">
                          <input type="hidden" name="return_url" value="saved_templates.php<?php echo $search !== '' ? '?q=' . rawurlencode($search) : ''; ?>">
                          <button class="button danger" type="submit">Verwijderen</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </div>
</body>
</html>
