<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rack_repository.php';

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$templateId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$databaseError = null;
$template = null;

try {
    $pdo = db();
    if ($templateId > 0) {
        $template = rackPlannerLoadTemplateDetails($pdo, $templateId);
    }
} catch (Throwable $e) {
    $databaseError = $e->getMessage();
}

if ($template === null) {
    $template = [
        'id' => null,
        'name' => '',
        'slug' => '',
        'documentTitle' => 'Rack Design',
        'logoPath' => '',
        'paperSize' => 'A4',
        'orientation' => 'portrait',
        'isDefault' => false,
        'fields' => rackPlannerBuildTemplateFieldState([]),
    ];
}
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo $template['id'] ? 'Edit template' : 'New template'; ?></title>
  <style>
    :root {
      --bg: #eef3f8; --panel: #fff; --panel-soft: #f7f9fc; --text: #172033; --muted: #667085; --border: #d7deea; --accent: #2d5bff; --success: #0f9d58; --danger: #d92d20; --shadow: 0 18px 44px rgba(15,23,42,.10); font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
    }
    * { box-sizing:border-box; }
    body { margin:0; background:linear-gradient(180deg, #f6f8fb 0%, var(--bg) 100%); color:var(--text); }
    .page { max-width: 1180px; margin:0 auto; padding:28px; display:grid; gap:20px; }
    .hero { display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; }
    .hero h1 { margin:0; font-size:32px; letter-spacing:-.03em; }
    .hero p { margin:8px 0 0; color:var(--muted); max-width:760px; }
    .hero-actions { display:flex; gap:10px; flex-wrap:wrap; }
    .button, button { border:0; border-radius:14px; padding:12px 16px; font:inherit; font-weight:700; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:8px; }
    .button.primary { background:var(--accent); color:#fff; box-shadow:0 14px 32px rgba(45,91,255,.18); }
    .button.secondary { background:#fff; color:var(--text); border:1px solid var(--border); }
    .panel { background:var(--panel); border:1px solid rgba(215,222,234,.9); border-radius:24px; box-shadow:var(--shadow); overflow:hidden; }
    .panel-head { padding:22px 24px 0; }
    .panel-head h2 { margin:0; font-size:20px; letter-spacing:-.02em; }
    .panel-head p { margin:8px 0 0; color:var(--muted); }
    .panel-body { padding:24px; display:grid; gap:20px; }
    .grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
    .field { display:grid; gap:8px; }
    .field.full { grid-column:1 / -1; }
    .field label { font-weight:700; font-size:14px; }
    .field input[type="text"], .field input[type="file"] { width:100%; border:1px solid var(--border); border-radius:14px; padding:12px 14px; font:inherit; background:#fff; }
    .field small, .muted { color:var(--muted); }
    .checkbox { display:flex; align-items:center; gap:10px; padding-top:30px; }
    table { width:100%; border-collapse:collapse; }
    th, td { border-bottom:1px solid #e9edf5; padding:14px 12px; text-align:left; }
    th { background:var(--panel-soft); font-size:13px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
    .logo-preview { display:flex; align-items:center; gap:14px; padding:14px; border:1px dashed var(--border); border-radius:16px; background:#fafcff; }
    .logo-preview img { max-height:56px; max-width:180px; object-fit:contain; }
    .flash { padding:14px 16px; border-radius:16px; font-weight:600; }
    .flash.error { background:rgba(217,45,32,.08); color:#a1261c; border:1px solid rgba(217,45,32,.18); }
    .badge { display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:6px 10px; background:#f3f5fb; border:1px solid var(--border); font-size:12px; font-weight:700; }
    @media (max-width: 860px) { .page { padding:18px; } .grid { grid-template-columns:1fr; } .checkbox { padding-top:0; } }
  </style>
</head>
<body>
  <div class="page">
    <section class="hero">
      <div>
        <h1><?php echo $template['id'] ? 'Edit template' : 'New template'; ?></h1>
        <p>Manage hier de templatebasis voor je A4 export. Rack Design staat vast als documenttitel; jij bepaalt naam, branding en welke metadata-fields zichtbaar of verplicht zijn.</p>
      </div>
      <div class="hero-actions">
        <a class="button secondary" href="saved_templates.php">Back to templates</a>
        <a class="button secondary" href="index.php">Home</a>
      </div>
    </section>

    <?php if ($databaseError !== null): ?>
      <div class="flash error"><?php echo h($databaseError); ?></div>
    <?php endif; ?>

    <form class="panel" method="post" action="save_template.php" enctype="multipart/form-data">
      <div class="panel-head">
        <h2>Template settings</h2>
        <p>Logo upload werkt direct naar uploads/logos. Velden hieronder bepalen straks wat zichtbaar en verplicht is in de planner en export.</p>
      </div>
      <div class="panel-body">
        <input type="hidden" name="template_id" value="<?php echo (int)($template['id'] ?? 0); ?>">
        <div class="grid">
          <div class="field">
            <label for="name">Template name</label>
            <input id="name" name="name" type="text" value="<?php echo h($template['name']); ?>" required>
          </div>
          <div class="field">
            <label for="slug">Slug</label>
            <input id="slug" name="slug" type="text" value="<?php echo h($template['slug']); ?>" placeholder="coolblue-v1">
          </div>
          <div class="field">
            <label for="document_title">Document title</label>
            <input id="document_title" name="document_title" type="text" value="<?php echo h($template['documentTitle']); ?>" readonly>
            <small>Deze staat nu vast op Rack Design.</small>
          </div>
          <label class="checkbox"><input type="checkbox" name="is_default" value="1"<?php echo !empty($template['isDefault']) ? ' checked' : ''; ?>> Make this the default template</label>
          <div class="field full">
            <label for="logo_file">Logo upload</label>
            <input id="logo_file" name="logo_file" type="file" accept=".png,.jpg,.jpeg,.svg,.webp">
            <small>Leave empty to keep the current logo.</small>
          </div>
          <div class="field full">
            <label>Huidig logo</label>
            <div class="logo-preview">
              <?php if ($template['logoPath'] !== ''): ?>
                <img src="<?php echo h($template['logoPath']); ?>" alt="Template logo">
                <div>
                  <div class="badge">Logo actief</div>
                  <div class="muted" style="margin-top:8px;"><?php echo h($template['logoPath']); ?></div>
                </div>
              <?php else: ?>
                <div class="muted">No logo linked yet.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div>
          <h2 style="margin:0 0 12px; font-size:20px; letter-spacing:-.02em;">Metadata-fields</h2>
          <table>
            <thead>
              <tr>
                <th>Veld</th>
                <th>Label</th>
                <th>Zichtbaar</th>
                <th>Verplicht</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($template['fields'] as $field): ?>
                <tr>
                  <td><strong><?php echo h($field['fieldKey']); ?></strong></td>
                  <td>
                    <input type="text" name="fields[<?php echo h($field['fieldKey']); ?>][field_label]" value="<?php echo h($field['fieldLabel']); ?>" style="width:100%; border:1px solid var(--border); border-radius:12px; padding:10px 12px; font:inherit;">
                  </td>
                  <td><input type="checkbox" name="fields[<?php echo h($field['fieldKey']); ?>][is_enabled]" value="1"<?php echo !empty($field['isEnabled']) ? ' checked' : ''; ?>></td>
                  <td><input type="checkbox" name="fields[<?php echo h($field['fieldKey']); ?>][is_required]" value="1"<?php echo !empty($field['isRequired']) ? ' checked' : ''; ?>></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="hero-actions">
          <button class="button primary" type="submit">Save template</button>
          <a class="button secondary" href="saved_templates.php">Cancel</a>
        </div>
      </div>
    </form>
  </div>
</body>
</html>
