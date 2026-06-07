<?php
require_once __DIR__ . '/bootstrap.php';
$user = toolboxRequirePermission('tools.manage');
$pdo = db();
$flash = toolboxConsumeFlash();
$search = trim((string)($_GET['q'] ?? ''));
$filterStatus = trim((string)($_GET['status'] ?? ''));
$editId = (int)($_GET['edit'] ?? 0);
$editTool = null;
$statusDefinitions = toolboxToolStatusDefinitions();
$permissionDefinitions = toolboxPermissionDefinitions($pdo);
$tools = [];
$databaseError = null;

try {
    $tools = toolboxLoadAllTools($pdo);
    if ($search !== '') {
        $needle = mb_strtolower($search);
        $tools = array_values(array_filter($tools, static function (array $tool) use ($needle): bool {
            $haystack = mb_strtolower(($tool['name'] ?? '') . ' ' . ($tool['tool_key'] ?? '') . ' ' . ($tool['description'] ?? '') . ' ' . ($tool['home_path'] ?? ''));
            return strpos($haystack, $needle) !== false;
        }));
    }
    if ($filterStatus !== '' && isset($statusDefinitions[$filterStatus])) {
        $tools = array_values(array_filter($tools, static function (array $tool) use ($filterStatus): bool {
            return (string)($tool['status'] ?? '') === $filterStatus;
        }));
    }
    if ($editId > 0) {
        $editTool = toolboxLoadToolById($editId, $pdo);
    }
} catch (Throwable $e) {
    $databaseError = $e->getMessage();
}

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Tool registry · <?php echo h(toolboxAppName()); ?></title>
  <link rel="stylesheet" href="assets/css/base.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/base.css') ?: time(); ?>">
  <link rel="stylesheet" href="assets/css/buttons.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/buttons.css') ?: time(); ?>">
  <link rel="stylesheet" href="assets/css/forms.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/forms.css') ?: time(); ?>">
  <link rel="stylesheet" href="assets/css/tables.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/tables.css') ?: time(); ?>">
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
        <h1>Tool registry</h1>
        <p>Register tools and manage Draft, Published, On hold, Archived, and Deleted states.</p>
      </div>
    </header>

    <?php if ($flash): ?>
      <div class="flash <?php echo h((string)$flash['type']); ?>"><?php echo h((string)$flash['message']); ?></div>
    <?php endif; ?>
    <?php if ($databaseError !== null): ?>
      <div class="flash error">Database error: <?php echo h($databaseError); ?></div>
    <?php endif; ?>

    <section class="panel" style="margin-bottom:20px;">
      <div class="panel-head">
        <h2><?php echo $editTool ? 'Edit tool' : 'Register tool'; ?></h2>
        <p><?php echo $editTool ? 'Update metadata, permissions, and lifecycle status.' : 'Add a tool to the registry so it can be published to users.'; ?></p>
      </div>
      <div class="panel-body">
        <form method="post" action="tool_actions.php" class="form-stack">
          <input type="hidden" name="_csrf" value="<?php echo h(toolboxCsrfToken()); ?>">
          <input type="hidden" name="action" value="<?php echo $editTool ? 'save_tool' : 'create_tool'; ?>">
          <?php if ($editTool): ?>
            <input type="hidden" name="tool_id" value="<?php echo (int)$editTool['id']; ?>">
          <?php endif; ?>
          <div class="field">
            <label for="name">Tool name</label>
            <input type="text" id="name" name="name" required value="<?php echo h($editTool['name'] ?? ''); ?>">
          </div>
          <div class="field">
            <label for="tool_key">Tool key</label>
            <input type="text" id="tool_key" name="tool_key" required value="<?php echo h($editTool['tool_key'] ?? ''); ?>" placeholder="rack-planner">
            <small class="help">Use a stable slug-like key, e.g. <code>rack-planner</code>.</small>
          </div>
          <div class="field">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="3"><?php echo h($editTool['description'] ?? ''); ?></textarea>
          </div>
          <div class="field">
            <label for="home_path">Home path</label>
            <input type="text" id="home_path" name="home_path" required value="<?php echo h($editTool['home_path'] ?? ''); ?>" placeholder="tools/rack-planner/index.php">
          </div>
          <div class="field">
            <label for="required_permission">Required permission</label>
            <select id="required_permission" name="required_permission">
              <option value="">No permission requirement</option>
              <?php foreach ($permissionDefinitions as $permissionKey => $permissionDefinition): ?>
                <option value="<?php echo h($permissionKey); ?>" <?php echo (($editTool['required_permission'] ?? '') === $permissionKey) ? 'selected' : ''; ?>><?php echo h($permissionDefinition['label']); ?> (<?php echo h($permissionKey); ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status" required>
              <?php $selectedStatus = (string)($editTool['status'] ?? 'draft'); ?>
              <?php foreach ($statusDefinitions as $statusKey => $statusDefinition): ?>
                <option value="<?php echo h($statusKey); ?>" <?php echo $selectedStatus === $statusKey ? 'selected' : ''; ?>><?php echo h($statusDefinition['label']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="sort_order">Sort order</label>
            <input type="number" id="sort_order" name="sort_order" value="<?php echo (int)($editTool['sort_order'] ?? 100); ?>">
          </div>
          <div class="field">
            <label for="status_note">Status note</label>
            <textarea id="status_note" name="status_note" rows="2" placeholder="Optional note shown to admins when a tool is on hold, archived, or deleted."><?php echo h($editTool['status_note'] ?? ''); ?></textarea>
          </div>
          <div class="button-group">
            <button class="button primary" type="submit"><?php echo $editTool ? 'Save tool' : 'Create tool'; ?></button>
            <?php if ($editTool): ?>
              <a class="button secondary" href="admin_tools.php">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h2>Registered tools</h2>
        <p>Use status actions to publish, put on hold, archive, or soft delete tools.</p>
      </div>
      <div class="panel-body">
        <form method="get" class="toolbar" style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
          <input type="text" name="q" value="<?php echo h($search); ?>" placeholder="Search tools" style="min-width:260px;">
          <select name="status">
            <option value="">All statuses</option>
            <?php foreach ($statusDefinitions as $statusKey => $statusDefinition): ?>
              <option value="<?php echo h($statusKey); ?>" <?php echo $filterStatus === $statusKey ? 'selected' : ''; ?>><?php echo h($statusDefinition['label']); ?></option>
            <?php endforeach; ?>
          </select>
          <button class="button secondary" type="submit">Filter</button>
          <a class="button secondary" href="admin_tools.php">Reset</a>
        </form>

        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Tool</th>
                <th>Status</th>
                <th>Permission</th>
                <th>Path</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$tools): ?>
                <tr><td colspan="5">No tools found.</td></tr>
              <?php endif; ?>
              <?php foreach ($tools as $tool): ?>
                <tr>
                  <td>
                    <strong><?php echo h($tool['name']); ?></strong><br>
                    <small><?php echo h($tool['tool_key']); ?></small>
                    <?php if (!empty($tool['description'])): ?><br><small><?php echo h($tool['description']); ?></small><?php endif; ?>
                    <?php if (!empty($tool['status_note'])): ?><br><small>Status note: <?php echo h($tool['status_note']); ?></small><?php endif; ?>
                  </td>
                  <td><span class="badge"><?php echo h(toolboxToolStatusLabel((string)$tool['status'])); ?></span></td>
                  <td><?php echo h($tool['required_permission'] ?: '—'); ?></td>
                  <td><code><?php echo h($tool['home_path']); ?></code></td>
                  <td>
                    <div class="button-group" style="margin-bottom:8px;">
                      <a class="button secondary" href="admin_tools.php?edit=<?php echo (int)$tool['id']; ?>">Edit</a>
                      <a class="button secondary" href="<?php echo h($tool['home_path']); ?>">Open</a>
                    </div>
                    <div class="button-group" style="gap:8px; flex-wrap:wrap;">
                      <?php foreach ($statusDefinitions as $statusKey => $statusDefinition): ?>
                        <?php if ($statusKey === (string)$tool['status']) { continue; } ?>
                        <form method="post" action="tool_actions.php" style="display:inline;">
                          <input type="hidden" name="_csrf" value="<?php echo h(toolboxCsrfToken()); ?>">
                          <input type="hidden" name="action" value="set_status">
                          <input type="hidden" name="tool_id" value="<?php echo (int)$tool['id']; ?>">
                          <input type="hidden" name="status" value="<?php echo h($statusKey); ?>">
                          <button class="button secondary" type="submit"><?php echo h($statusDefinition['label']); ?></button>
                        </form>
                      <?php endforeach; ?>
                      <form method="post" action="tool_actions.php" style="display:inline;" onsubmit="return confirm('Soft delete this tool? It will be hidden from normal users.');">
                        <input type="hidden" name="_csrf" value="<?php echo h(toolboxCsrfToken()); ?>">
                        <input type="hidden" name="action" value="soft_delete">
                        <input type="hidden" name="tool_id" value="<?php echo (int)$tool['id']; ?>">
                        <button class="button secondary" type="submit">Soft delete</button>
                      </form>
                      <?php if ((string)$tool['status'] === 'deleted'): ?>
                        <form method="post" action="tool_actions.php" style="display:inline;">
                          <input type="hidden" name="_csrf" value="<?php echo h(toolboxCsrfToken()); ?>">
                          <input type="hidden" name="action" value="restore_tool">
                          <input type="hidden" name="tool_id" value="<?php echo (int)$tool['id']; ?>">
                          <button class="button secondary" type="submit">Restore to draft</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </main>
</body>
</html>
