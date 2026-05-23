<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rack_repository.php';

function buildAssetDataUri(string $assetUrl): ?string
{
    if ($assetUrl === '' || preg_match('#^(?:https?:)?//#i', $assetUrl)) {
        return null;
    }

    $clean = ltrim(str_replace([chr(92), chr(0)], ['/', ''], $assetUrl), '/');
    if ($clean === '' || strpos($clean, '..') !== false) {
        return null;
    }

    $fullPath = __DIR__ . '/' . $clean;
    if (!is_file($fullPath) || !is_readable($fullPath)) {
        return null;
    }

    $content = file_get_contents($fullPath);
    if ($content === false) {
        return null;
    }

    $extension = strtolower((string)pathinfo($fullPath, PATHINFO_EXTENSION));
    $mimeMap = [
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];
    $mimeType = $mimeMap[$extension] ?? null;
    if ($mimeType === null && function_exists('mime_content_type')) {
        $detected = @mime_content_type($fullPath);
        if (is_string($detected) && strpos($detected, 'image/') === 0) {
            $mimeType = $detected;
        }
    }

    if ($mimeType === null) {
        return null;
    }

    if ($mimeType === 'image/svg+xml' && stripos($content, '<svg') === false) {
        return null;
    }

    return 'data:' . $mimeType . ';base64,' . base64_encode($content);
}

function buildSvgDataUri(string $svgUrl): ?string
{
    $dataUri = buildAssetDataUri($svgUrl);
    return $dataUri !== null && strpos($dataUri, 'data:image/svg+xml;') === 0 ? $dataUri : null;
}


function libraryBaseName(string $name): string
{
    $baseName = trim((string)preg_replace('/\s+\d+U$/i', '', $name));
    return $baseName !== '' ? $baseName : $name;
}

function loadLibraryItems(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT uuid, name, he, width_pct, svg_path, category, created_at, updated_at
         FROM library_items
         WHERE is_active = 1
         ORDER BY FIELD(COALESCE(category, 'uncategorized'), 'fixed', 'devices', 'uncategorized'), name ASC, created_at ASC"
    );

    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $svgUrl = (string)($row['svg_path'] ?? '');
        $category = strtolower(trim((string)($row['category'] ?? 'uncategorized')));
        if (!in_array($category, ['fixed', 'devices'], true)) {
            $category = 'uncategorized';
        }

        $items[] = [
            'id' => (string)$row['uuid'],
            'name' => (string)$row['name'],
            'he' => (int)$row['he'],
            'widthPct' => (int)$row['width_pct'],
            'category' => $category,
            'svgUrl' => $svgUrl,
            'svgDataUri' => buildSvgDataUri($svgUrl),
            'createdAt' => isset($row['created_at']) ? (string)$row['created_at'] : null,
            'updatedAt' => isset($row['updated_at']) ? (string)$row['updated_at'] : null,
        ];
    }

    return $items;
}

$library = [];
$templates = rackPlannerNormalizeTemplates([]);
$rackList = [];
$selectedRack = null;
$databaseError = null;
$selectedRackId = isset($_GET['rack']) ? (int)$_GET['rack'] : 0;

try {
    $pdo = db();
    $library = loadLibraryItems($pdo);
    $templates = array_map(static function (array $template): array {
        $template['logoDataUri'] = buildAssetDataUri((string)($template['logoPath'] ?? ''));
        return $template;
    }, rackPlannerNormalizeTemplates(rackPlannerLoadTemplates($pdo)));
    $rackList = rackPlannerLoadRackSummaries($pdo);
    if ($selectedRackId > 0) {
        $selectedRack = rackPlannerLoadRackDetails($pdo, $selectedRackId);
        if ($selectedRack !== null) {
            $selectedRack['items'] = array_map(static function (array $item): array {
                $item['svgDataUri'] = buildSvgDataUri((string)($item['svgUrl'] ?? ''));
                return $item;
            }, $selectedRack['items']);
        }
    }
} catch (Throwable $e) {
    $databaseError = 'Databasefout: ' . $e->getMessage();
}

$editId = $_GET['edit'] ?? null;
$editItem = null;
if ($editId) {
    foreach ($library as $entry) {
        if (($entry['id'] ?? null) === $editId) {
            $editItem = $entry;
            break;
        }
    }
}
if ($editItem !== null) {
    $editItem['baseName'] = libraryBaseName((string)($editItem['name'] ?? ''));
}
$isEditMode = $editItem !== null;
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Rack Planner</title>
  <style>
    :root {
      --bg: #f6f7fb;
      --panel: #ffffff;
      --sidebar-left-width: 360px;
      --sidebar-left-collapsed-width: 72px;
      --sidebar-right-width: 400px;
      --sidebar-right-collapsed-width: 72px;
      --fab-gap: 18px;
      --fab-left: calc(var(--sidebar-left-width) + var(--fab-gap));
      --panel-2: #f0f2f7;
      --text: #1f2937;
      --muted: #6b7280;
      --border: #d6dbe7;
      --accent: #2d5bff;
      --accent-soft: #e8eeff;
      --danger: #c62828;
      --coolblue-orange: #ff6a00;
      --coolblue-header: #edf1f4;
      --rack-bg: #1c2028;
      --rack-rail: #394150;
      --rack-slot: #242938;
      --shadow: 0 10px 30px rgba(16, 24, 40, 0.08);
      --u-height: 28px;
      --rack-inner-width: calc(var(--u-height) * 10.8571428571);
      --rack-frame-side: 22px;
      --rack-rail-width: 14px;
      --rack-units: 24;
    }

    * { box-sizing: border-box; }
    html, body { min-height: 100%; }
    body {
      margin: 0;
      font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: linear-gradient(180deg, #f8f9fc 0%, #eef2f8 100%);
      color: var(--text);
    }

    .app {
      max-width: 1800px;
      margin: 0 auto;
      padding: 24px;
    }

    .header {
      margin-bottom: 20px;
    }

    .header h1 {
      margin: 0 0 6px;
      font-size: 28px;
    }

    .header p {
      margin: 0;
      color: var(--muted);
      max-width: 980px;
      line-height: 1.45;
    }

    .layout {
      display: grid;
      grid-template-columns: minmax(360px, 2fr) minmax(980px, 3fr);
      gap: 20px;
      align-items: start;
    }

    .left-stack {
      display: grid;
      gap: 14px;
    }

    .panel {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 18px;
      box-shadow: var(--shadow);
      overflow: hidden;
    }

    .panel-head {
      padding: 16px 18px;
      border-bottom: 1px solid var(--border);
      background: rgba(255,255,255,0.85);
    }

    .panel-head h2, .panel-head h3 {
      margin: 0;
      font-size: 16px;
    }

    .panel-head p {
      margin: 6px 0 0;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.4;
    }

    .panel-body {
      padding: 18px;
    }

    form,
    .stack {
      display: grid;
      gap: 12px;
    }

    .field,
    .control-field {
      display: grid;
      gap: 6px;
    }

    .control-field label,
    label {
      font-size: 13px;
      font-weight: 600;
    }

    input, button, select {
      font: inherit;
    }

    input[type="text"], input[type="number"], input[type="file"], input[type="date"], select {
      width: 100%;
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 10px 12px;
      background: #fff;
      color: var(--text);
    }

    input:focus, select:focus {
      outline: 2px solid rgba(45, 91, 255, 0.18);
      border-color: var(--accent);
    }

    .help {
      color: var(--muted);
      font-size: 12px;
      line-height: 1.4;
    }

    .button {
      border: 0;
      border-radius: 12px;
      padding: 11px 14px;
      cursor: pointer;
      transition: transform 0.15s ease, background 0.15s ease, opacity 0.15s ease, border-color 0.15s ease;
      text-decoration: none;
      display: inline-flex;
      justify-content: center;
      align-items: center;
      gap: 8px;
    }

    .button:hover { transform: translateY(-1px); }
    .button.primary {
      background: var(--accent);
      color: #fff;
      font-weight: 600;
    }
    .button.secondary {
      background: var(--panel-2);
      color: var(--text);
      border: 1px solid var(--border);
    }
    .button.danger {
      background: #fff1f1;
      color: var(--danger);
      border: 1px solid #f4c7c7;
    }
    .button.small {
      padding: 8px 10px;
      border-radius: 10px;
      font-size: 12px;
    }

    .flash {
      margin-bottom: 14px;
      padding: 12px 14px;
      border-radius: 12px;
      font-size: 14px;
      display: none;
    }

    .flash.success {
      background: #eefaf0;
      border: 1px solid #b9e3bf;
      color: #216e39;
      display: block;
    }

    .flash.error {
      background: #fff1f1;
      border: 1px solid #f0bcbc;
      color: #8f1d1d;
      display: block;
    }

    .workspace-panel .panel-head {
      background: linear-gradient(180deg, rgba(255,255,255,0.94) 0%, rgba(249,250,252,0.94) 100%);
    }

    .workspace-controls {
      display: grid;
      gap: 12px;
      margin-bottom: 18px;
    }

    .control-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
    }

    .control-grid.compact {
      grid-template-columns: 1.1fr 1fr 0.9fr 0.9fr;
    }

    .sidebar-right .control-grid,
    .sidebar-right .control-grid.compact {
      grid-template-columns: 1fr;
    }

    .sidebar-right .control-field {
      display: block;
      width: 100%;
    }

    .sidebar-right .control-field > label,
    .sidebar-right .control-field > input,
    .sidebar-right .control-field > select,
    .sidebar-right .control-field > .face-toggle,
    .sidebar-right .control-field > .help {
      display: block;
      width: 100%;
    }

    .sidebar-right .control-field > .face-toggle {
      display: flex;
    }

    .toolbar-row {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      align-items: center;
      justify-content: space-between;
    }

    .toolbar-row .group {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      align-items: center;
    }

    .control-field.actions {
      grid-column: 1 / -1;
    }

    .action-toolbar {
      justify-content: flex-start;
      align-items: center;
      flex-wrap: wrap;
    }

    .action-toolbar .button {
      white-space: nowrap;
    }

    .face-toggle {
      display: inline-flex;
      padding: 4px;
      border-radius: 999px;
      background: #eef2f8;
      border: 1px solid var(--border);
      gap: 4px;
    }

    .face-toggle button {
      border: 0;
      background: transparent;
      color: var(--muted);
      padding: 8px 14px;
      border-radius: 999px;
      font-weight: 600;
      cursor: pointer;
    }

    .face-toggle button.active {
      background: #fff;
      color: var(--text);
      box-shadow: 0 2px 10px rgba(15,23,42,0.08);
    }

    .template-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      border-radius: 999px;
      background: #fff;
      border: 1px solid var(--border);
      color: var(--muted);
      font-size: 13px;
    }

    .page-preview-wrap {
      overflow: auto;
      padding: 6px;
    }

    .page-preview {
      width: min(100%, 1120px);
      min-width: max-content;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #dbe4f0;
      border-radius: 22px;
      box-shadow: 0 18px 48px rgba(15, 23, 42, 0.12);
      overflow: hidden;
    }

    .page-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      padding: 14px 24px;
      background: var(--coolblue-header);
      border-bottom: 1px solid #d8e0e8;
    }

    .brand-logo {
      width: 124px;
      height: 52px;
      min-width: 124px;
      border-radius: 14px;
      background: #ffffff;
      color: #fff;
      display: grid;
      place-items: center;
      text-align: center;
      font-size: 17px;
      line-height: 0.94;
      font-weight: 800;
      letter-spacing: -0.03em;
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.28), 0 4px 12px rgba(15, 23, 42, 0.08);
      border: 1px solid rgba(15, 23, 42, 0.08);
      padding: 8px 12px;
      overflow: hidden;
    }

    .brand-logo img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      display: block;
    }

    .brand-logo.has-image {
      background: #ffffff;
    }

    .page-doc-title {
      font-size: 28px;
      font-weight: 800;
      letter-spacing: -0.03em;
      flex: 1 1 auto;
      min-width: 0;
    }

    .page-top-right {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }

    .page-body {
      padding: 14px 24px 20px;
      background: #fff;
    }

    .page-title-row {
      display: none;
    }

    .page-rack-title {
      margin: 0;
      font-size: 18px;
      font-weight: 700;
      letter-spacing: -0.02em;
      color: #0f172a;
      white-space: nowrap;
    }

    .face-chip {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 6px 10px;
      border-radius: 999px;
      background: #f7f9fc;
      border: 1px solid #dbe4f0;
      color: #475569;
      font-size: 12px;
      font-weight: 700;
      white-space: nowrap;
    }

    .page-preview.face-back .face-chip {
      background: #eef5ff;
      border-color: #cfe0ff;
      color: #214b96;
    }

    .page-preview.face-back .rack-canvas {
      background:
        linear-gradient(90deg, rgba(255,255,255,0.06), transparent 4%, transparent 96%, rgba(255,255,255,0.06)),
        repeating-linear-gradient(
          to bottom,
          rgba(130, 177, 255, 0.14) 0,
          rgba(130, 177, 255, 0.14) 1px,
          transparent 1px,
          transparent calc(var(--u-height) - 1px),
          rgba(0,0,0,0.22) calc(var(--u-height) - 1px),
          rgba(0,0,0,0.22) var(--u-height)
        ),
        linear-gradient(180deg, #22304a 0%, #17212f 100%);
    }

    .page-preview.face-back .rack-canvas::after {
      content: "BACK VIEW";
      position: absolute;
      top: 12px;
      right: 14px;
      font-size: 10px;
      letter-spacing: 0.18em;
      color: rgba(255,255,255,0.45);
      font-weight: 700;
      pointer-events: none;
    }

    .page-footer {
      display: flex;
      justify-content: space-between;
      gap: 16px;
      align-items: center;
      padding: 12px 24px 18px;
      border-top: 1px solid #edf2f7;
      color: #64748b;
      font-size: 12px;
      background: #fff;
    }

    .page-footer .meta {
      display: flex;
      flex-wrap: wrap;
      gap: 14px;
      align-items: center;
    }

    .rack-sheet {
      display: grid;
      grid-template-columns: auto 320px;
      gap: 26px;
      align-items: start;
      min-width: max-content;
    }

    .rack {
      display: grid;
      grid-template-columns: 56px auto 56px;
      gap: 10px;
      align-items: stretch;
      user-select: none;
    }

    .rack-labels {
      position: relative;
      width: 56px;
      height: calc(var(--u-height) * var(--rack-units));
    }

    .rack-label {
      position: absolute;
      right: 0;
      transform: translateY(-50%);
      color: var(--muted);
      font-size: 12px;
      font-variant-numeric: tabular-nums;
    }

    .rack-shell {
      position: relative;
      width: calc(var(--rack-inner-width) + (var(--rack-frame-side) * 2));
      height: calc(var(--u-height) * var(--rack-units));
      background: linear-gradient(180deg, #353c4d 0%, #232936 48%, #1b202a 100%);
      border-radius: 20px;
      box-shadow:
        inset 0 1px 0 rgba(255,255,255,0.08),
        inset 0 0 0 1px rgba(255,255,255,0.03),
        0 24px 44px rgba(0, 0, 0, 0.22);
      padding: 0 var(--rack-frame-side);
      overflow: hidden;
    }

    .rack-shell::before,
    .rack-shell::after {
      content: "";
      position: absolute;
      top: 0;
      bottom: 0;
      width: 10px;
      background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.01));
      box-shadow: inset -1px 0 0 rgba(0,0,0,0.28);
      pointer-events: none;
    }

    .rack-shell::before { left: 0; }
    .rack-shell::after {
      right: 0;
      transform: scaleX(-1);
    }

    .rack-rail {
      position: absolute;
      top: 8px;
      bottom: 8px;
      width: var(--rack-rail-width);
      border-radius: 8px;
      background:
        linear-gradient(180deg, rgba(255,255,255,0.12), rgba(255,255,255,0.03)),
        repeating-linear-gradient(
          to bottom,
          #5c677b 0,
          #5c677b 5px,
          #3d4657 5px,
          #3d4657 28px
        );
      border-left: 1px solid rgba(255,255,255,0.08);
      border-right: 1px solid rgba(0,0,0,0.38);
      box-shadow: inset 0 0 0 1px rgba(0,0,0,0.14);
      z-index: 2;
    }

    .rack-rail.left { left: 6px; }
    .rack-rail.right { right: 6px; }

    .rack-canvas {
      position: relative;
      width: var(--rack-inner-width);
      height: calc(var(--u-height) * var(--rack-units));
      margin: 0 auto;
      background:
        linear-gradient(90deg, rgba(255,255,255,0.04), transparent 4%, transparent 96%, rgba(255,255,255,0.04)),
        repeating-linear-gradient(
          to bottom,
          rgba(255,255,255,0.09) 0,
          rgba(255,255,255,0.09) 1px,
          transparent 1px,
          transparent calc(var(--u-height) - 1px),
          rgba(0,0,0,0.22) calc(var(--u-height) - 1px),
          rgba(0,0,0,0.22) var(--u-height)
        ),
        linear-gradient(180deg, #202633 0%, #181d27 100%);
      box-shadow:
        inset 18px 0 18px rgba(0,0,0,0.22),
        inset -18px 0 18px rgba(0,0,0,0.22),
        inset 0 0 0 1px rgba(255,255,255,0.03);
      overflow: hidden;
    }

    .rack-empty {
      position: absolute;
      inset: 0;
      display: grid;
      place-items: center;
      color: rgba(255,255,255,0.55);
      text-align: center;
      font-size: 14px;
      padding: 20px;
      pointer-events: none;
    }

    .rack-item {
      position: absolute;
      left: 50%;
      transform: translateX(-50%);
      border-radius: 4px;
      overflow: visible;
      background: transparent;
      border: 0;
      box-shadow:
        inset 0 1px 0 rgba(255,255,255,0.08),
        0 3px 8px rgba(0,0,0,0.22);
      cursor: grab;
      transition: box-shadow 0.12s ease, filter 0.12s ease;
    }

    .rack-item::before {
      content: "";
      position: absolute;
      inset: 0;
      border-radius: 4px;
      box-shadow:
        inset 0 0 0 1px rgba(15,23,42,0.22),
        inset 0 1px 0 rgba(255,255,255,0.06),
        inset 0 -1px 0 rgba(0,0,0,0.18);
      pointer-events: none;
    }

    .rack-item.dragging {
      cursor: grabbing;
      box-shadow:
        inset 0 1px 0 rgba(255,255,255,0.12),
        0 10px 22px rgba(45,91,255,0.28),
        0 0 0 2px rgba(95,140,255,0.28);
      filter: saturate(1.03);
    }

    .rack-item img {
      display: block;
      width: 100%;
      height: 100%;
      object-fit: contain;
      background: transparent;
      pointer-events: none;
      position: relative;
      z-index: 1;
    }

    .rack-item .badge {
      position: absolute;
      left: 8px;
      bottom: 6px;
      background: rgba(17,24,39,0.84);
      color: #fff;
      font-size: 11px;
      padding: 4px 7px;
      border-radius: 999px;
      pointer-events: none;
      z-index: 3;
    }

    .rack-item .remove {
      position: absolute;
      top: 6px;
      right: 6px;
      width: 24px;
      height: 24px;
      border: 0;
      border-radius: 999px;
      background: rgba(255,255,255,0.95);
      color: #111827;
      cursor: pointer;
      font-size: 16px;
      line-height: 1;
      box-shadow: 0 2px 10px rgba(0,0,0,0.16);
      z-index: 3;
    }

    .comments-lane {
      position: relative;
      width: 320px;
      min-height: calc(var(--u-height) * var(--rack-units));
    }

    .comments-empty {
      height: calc(var(--u-height) * var(--rack-units));
      display: grid;
      place-items: center;
      text-align: center;
      color: var(--muted);
      border: 1px dashed var(--border);
      border-radius: 16px;
      background: linear-gradient(180deg, #fbfcfe 0%, #f5f7fb 100%);
      padding: 16px;
      font-size: 14px;
      line-height: 1.5;
    }

    .comment-card {
      position: absolute;
      left: 18px;
      right: 0;
      --connector-top: 20px;
      background: rgba(255,255,255,0.98);
      border: 1px solid #dbe3f0;
      border-radius: 14px;
      box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
      padding: 10px 12px;
    }

    .comment-card::before {
      content: "";
      position: absolute;
      left: -18px;
      top: var(--connector-top);
      width: 18px;
      border-top: 2px solid #cbd5e1;
    }

    .comment-card::after {
      content: "";
      position: absolute;
      left: -22px;
      top: calc(var(--connector-top) - 4px);
      width: 8px;
      height: 8px;
      border-radius: 999px;
      background: #94a3b8;
      box-shadow: 0 0 0 4px #eef2f7;
    }

    .comment-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
      margin-bottom: 8px;
      font-size: 12px;
      color: var(--muted);
    }

    .comment-title {
      font-weight: 700;
      color: var(--text);
    }

    .comment-body {
      width: 100%;
      min-width: 0;
    }

    .library-grid {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: flex-start;
      max-height: 320px;
      overflow: auto;
      padding: 2px;
    }


    .library-tabs,
    .library-manager-tabs {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin-bottom: 12px;
    }

    .library-tab-button,
    .library-manager-tab-button {
      border: 1px solid #d9e3ef;
      background: #f8fbff;
      color: var(--muted);
      border-radius: 999px;
      padding: 8px 12px;
      font-size: 12px;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.16s ease;
    }

    .library-tab-button.active,
    .library-manager-tab-button.active {
      background: #dbeafe;
      border-color: #93c5fd;
      color: #1d4ed8;
      box-shadow: 0 6px 16px rgba(59, 130, 246, 0.14);
    }

    .library-category-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 8px;
      border-radius: 999px;
      background: #f1f5f9;
      border: 1px solid #dbe4f0;
      color: #475569;
      font-size: 11px;
      font-weight: 700;
      margin-bottom: 8px;
    }

    .library-icon-button {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      border: 1px solid #e5ebf4;
      background: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 4px;
      cursor: pointer;
      transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
    }

    .library-icon-button:hover {
      transform: translateY(-1px);
      border-color: #93c5fd;
      box-shadow: 0 10px 20px rgba(59, 130, 246, 0.12);
    }

    .library-icon-button img {
      width: 34px;
      height: 34px;
      object-fit: contain;
      background: transparent;
      pointer-events: none;
    }

    .library-modal {
      position: fixed;
      inset: 0;
      display: none;
      z-index: 70;
    }

    .library-modal.open {
      display: block;
    }

    .library-modal-backdrop {
      position: absolute;
      inset: 0;
      background: rgba(15, 23, 42, 0.45);
      backdrop-filter: blur(4px);
    }

    .library-modal-panel {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      width: min(980px, calc(100vw - 48px));
      max-height: min(86vh, 960px);
      display: flex;
      flex-direction: column;
      overflow: hidden;
      border-radius: 24px;
      box-shadow: 0 40px 80px rgba(15, 23, 42, 0.26);
    }

    .library-modal-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }

    .library-modal-actions {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .library-manager-grid {
      display: grid;
      gap: 12px;
      grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
      max-height: calc(86vh - 180px);
      overflow: auto;
      padding-right: 4px;
    }

    .library-card {
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 14px;
      display: grid;
      grid-template-columns: 78px 1fr;
      gap: 14px;
      align-items: center;
      background: #fff;
    }

    .library-card img {
      width: 78px;
      height: 60px;
      object-fit: contain;
      border-radius: 10px;
      background: transparent;
      border: 1px solid #edf1f7;
      padding: 6px;
    }

    .library-card h4 {
      margin: 0 0 4px;
      font-size: 14px;
    }

    .library-meta {
      margin: 0 0 10px;
      color: var(--muted);
      font-size: 12px;
    }

    .library-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      align-items: center;
    }

    .library-actions form {
      display: inline;
    }

    .summary-box {
      padding: 12px;
      border-radius: 12px;
      background: #f8fafc;
      border: 1px solid #e8edf5;
    }

    .summary-box strong {
      display: block;
      margin-bottom: 4px;
      font-size: 13px;
    }

    .summary-box span {
      color: var(--muted);
      font-size: 13px;
      line-height: 1.4;
    }

    .empty-library {
      color: var(--muted);
      font-size: 14px;
      line-height: 1.5;
      padding: 12px 0;
    }

    .ratio-help {
      margin-top: 14px;
      border: 1px solid #dbe4f0;
      border-radius: 14px;
      background: #f8fbff;
      overflow: hidden;
    }

    .ratio-help summary {
      cursor: pointer;
      list-style: none;
      padding: 12px 14px;
      font-weight: 700;
      font-size: 13px;
      color: #1e3a5f;
    }

    .ratio-help summary::-webkit-details-marker {
      display: none;
    }

    .ratio-help-body {
      padding: 0 14px 14px;
      font-size: 12px;
      line-height: 1.5;
      color: #475569;
    }

    .ratio-help-body p {
      margin: 0 0 10px;
    }

    .ratio-help-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 4px 12px;
    }

    .ratio-help-grid span {
      display: block;
      white-space: nowrap;
    }

    .ratio-help.compact {
      margin-top: 10px;
    }

    .ratio-help.compact .ratio-help-body {
      font-size: 11px;
    }

    @media (max-width: 1400px) {
      .control-grid,
      .control-grid.compact {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 1220px) {
      .layout {
        grid-template-columns: 1fr;
      }
      .rack-sheet {
        grid-template-columns: 1fr;
      }
      .comments-lane {
        width: 100%;
        min-height: 240px;
      }
      .comment-card {
        left: 0;
      }
      .comment-card::before,
      .comment-card::after {
        display: none;
      }
    }


    /* Layout refresh */
    body {
      overflow: hidden;
    }

    .app.shell-layout {
      max-width: none;
      width: 100%;
      height: 100vh;
      padding: 0;
      margin: 0;
      display: grid;
      grid-template-columns: auto minmax(0, 1fr) auto;
      gap: 0;
    }

    .sidebar {
      position: relative;
      z-index: 20;
      width: var(--sidebar-left-width);
      min-width: var(--sidebar-left-collapsed-width);
      background: rgba(255, 255, 255, 0.84);
      backdrop-filter: blur(16px);
      border-right: 1px solid rgba(214, 219, 231, 0.9);
      box-shadow: 0 18px 50px rgba(15, 23, 42, 0.08);
      display: flex;
      flex-direction: column;
      transition: width 0.24s ease;
      overflow: hidden;
    }

    .sidebar-right {
      width: var(--sidebar-right-width);
      border-right: 0;
      border-left: 1px solid rgba(214, 219, 231, 0.9);
    }

    .sidebar.collapsed {
      width: var(--sidebar-left-collapsed-width);
    }

    .sidebar-right.collapsed {
      width: var(--sidebar-right-collapsed-width);
    }

    .sidebar-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 18px 16px;
      border-bottom: 1px solid rgba(214, 219, 231, 0.9);
      min-height: 78px;
    }

    .sidebar-brand {
      min-width: 0;
      display: grid;
      gap: 4px;
    }

    .sidebar-title-text {
      font-size: 16px;
      font-weight: 800;
      letter-spacing: -0.02em;
    }

    .sidebar-subtitle {
      font-size: 12px;
      color: var(--muted);
      line-height: 1.35;
    }

    .sidebar-actions {
      display: inline-flex;
      gap: 8px;
      align-items: center;
      flex-shrink: 0;
    }

    .sidebar-content {
      flex: 1;
      min-height: 0;
      overflow: auto;
      padding: 16px;
      display: grid;
      gap: 14px;
      align-content: start;
    }

    .sidebar.collapsed .sidebar-brand,
    .sidebar.collapsed .sidebar-content {
      display: none;
    }

    .sidebar.collapsed .sidebar-top {
      justify-content: center;
      padding: 14px 10px;
    }

    .icon-button {
      width: 40px;
      height: 40px;
      border-radius: 999px;
      border: 1px solid var(--border);
      background: #fff;
      color: var(--text);
      display: inline-grid;
      place-items: center;
      cursor: pointer;
      font: inherit;
      font-size: 18px;
      box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
      transition: transform 0.15s ease, box-shadow 0.15s ease;
    }

    .icon-button:hover {
      transform: translateY(-1px);
      box-shadow: 0 12px 24px rgba(15, 23, 42, 0.12);
    }

    .workspace-shell {
      position: relative;
      min-width: 0;
      overflow: auto;
      padding: 104px 28px 24px;
      background:
        radial-gradient(circle at top left, rgba(45, 91, 255, 0.09), transparent 28%),
        linear-gradient(180deg, #f8f9fc 0%, #eef2f8 100%);
    }

    .workspace-stage {
      max-width: 1480px;
      margin: 0 auto;
    }

    .workspace-panel {
      overflow: visible;
    }

    .workspace-panel .panel-head {
      padding-right: 160px;
    }

    .fab-new-rack {
      position: fixed;
      top: 18px;
      left: var(--fab-left);
      z-index: 70;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 14px 18px;
      border-radius: 999px;
      background: var(--accent);
      color: #fff;
      text-decoration: none;
      box-shadow: 0 18px 36px rgba(45, 91, 255, 0.28);
      font-weight: 700;
      transition: left 0.24s ease, transform 0.15s ease, box-shadow 0.15s ease;
    }

    .fab-new-rack .icon {
      font-size: 20px;
      line-height: 1;
    }

    .floating-actions {
      position: fixed;
      top: 18px;
      left: 50%;
      transform: translateX(-50%);
      z-index: 65;
      display: inline-flex;
      gap: 10px;
      align-items: center;
      padding: 8px;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.82);
      backdrop-filter: blur(14px);
      border: 1px solid rgba(214, 219, 231, 0.9);
      box-shadow: 0 18px 46px rgba(15, 23, 42, 0.12);
    }

    .floating-action {
      border: 0;
      border-radius: 999px;
      padding: 12px 16px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      white-space: nowrap;
      font: inherit;
      font-weight: 700;
      color: var(--text);
      background: #fff;
      box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
      transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
    }

    .floating-action:hover {
      transform: translateY(-1px);
      box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12);
    }

    .floating-action.primary {
      background: var(--accent);
      color: #fff;
    }

    .floating-action.secondary {
      background: #fff;
      color: var(--text);
      border: 1px solid rgba(214, 219, 231, 0.9);
    }

    .floating-action .icon {
      font-size: 17px;
      line-height: 1;
    }

    .panel-head-with-action {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
    }

    .panel-head-with-action > div {
      min-width: 0;
      flex: 1;
    }

    .icon-button-primary {
      background: var(--accent);
      color: #fff;
      border-color: transparent;
      box-shadow: 0 10px 24px rgba(45, 91, 255, 0.22);
      flex: 0 0 auto;
    }

    .icon-button-primary:hover {
      transform: translateY(-1px);
      box-shadow: 0 14px 28px rgba(45, 91, 255, 0.28);
    }

    .upload-drawer {
      position: fixed;
      inset: 0;
      z-index: 90;
      display: none;
    }

    .upload-drawer.open {
      display: block;
    }

    .upload-backdrop {
      position: absolute;
      inset: 0;
      background: rgba(15, 23, 42, 0.34);
      backdrop-filter: blur(2px);
    }

    .upload-panel {
      position: absolute;
      top: 18px;
      left: 18px;
      bottom: 18px;
      width: min(460px, calc(100vw - 36px));
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 24px;
      box-shadow: 0 26px 80px rgba(15, 23, 42, 0.22);
      overflow: auto;
    }

    .upload-panel .panel-head {
      position: sticky;
      top: 0;
      z-index: 2;
      background: rgba(255,255,255,0.96);
      backdrop-filter: blur(14px);
    }

    .upload-head-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }

    .saved-rack-link {
      text-decoration: none;
      color: inherit;
    }

    @media (max-width: 1500px) {
      .sidebar-right {
        width: 360px;
      }
    }

    @media (max-width: 1200px) {
      body {
        overflow: auto;
      }
      .app.shell-layout {
        grid-template-columns: 1fr;
        height: auto;
      }
      .sidebar,
      .sidebar-right,
      .sidebar.collapsed {
        width: 100%;
      }
      .sidebar.collapsed .sidebar-brand,
      .sidebar.collapsed .sidebar-content {
        display: grid;
      }
      .workspace-shell {
        padding-top: 120px;
      }
      .floating-actions {
        left: 88px;
        right: 18px;
        transform: none;
        width: auto;
        justify-content: flex-start;
        flex-wrap: wrap;
        border-radius: 24px;
      }
      .floating-action .button-label,
      .fab-new-rack .label {
        display: none;
      }
    }

    .print-root {
      display: none;
    }

    .print-page {
      width: 210mm;
      min-height: 297mm;
      margin: 0 auto;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #fff;
      page-break-after: always;
      break-after: page;
    }

    .print-page:last-child {
      page-break-after: auto;
      break-after: auto;
    }

    .print-page img {
      width: 210mm;
      height: 297mm;
      display: block;
      object-fit: contain;
    }

    @page {
      size: A4 portrait;
      margin: 0;
    }

    @media print {
      html, body {
        background: #fff;
        margin: 0;
      }

      body {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }

      body > *:not(.print-root) {
        display: none !important;
      }

      .print-root {
        display: block !important;
      }
    }
  </style>
</head>
<body>
  <a class="fab-new-rack" href="index.php?new=1" title="Nieuw rack starten">
    <span class="icon" aria-hidden="true">＋</span>
    <span class="label">Nieuw rack</span>
  </a>

  <div class="floating-actions" aria-label="Snelle acties">
    <button id="saveRackButton" class="floating-action primary" type="button" title="Rack opslaan">
      <span class="icon" aria-hidden="true">💾</span>
      <span class="button-label">Nieuw rack opslaan</span>
    </button>
    <button id="printPage" class="floating-action primary" type="button" title="Front en Back exporteren naar print of PDF">
      <span class="icon" aria-hidden="true">🖨</span>
      <span class="button-label">Print / PDF</span>
    </button>
    <button id="clearFace" class="floating-action secondary" type="button" title="Maak alleen de huidige zijde leeg">
      <span class="icon" aria-hidden="true">🧹</span>
      <span class="button-label">Leeg zijde</span>
    </button>
  </div>

  <div class="app shell-layout">
    <aside class="sidebar sidebar-left" id="leftSidebar">
      <div class="sidebar-top">
        <div class="sidebar-brand">
          <div class="sidebar-title-text">Rack Planner</div>
          <div class="sidebar-subtitle">Opgeslagen racks en library</div>
        </div>
        <div class="sidebar-actions">
          <button class="icon-button" type="button" id="toggleLeftSidebar" title="Linker sidebar inklappen">❮</button>
        </div>
      </div>
      <div class="sidebar-content">
        <?php if ($databaseError !== null): ?>
          <div class="flash error"><?php echo htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['status']) && $_GET['status'] === 'rack-saved'): ?>
          <div class="flash success">Rack opgeslagen in de database.</div>
        <?php elseif (isset($_GET['status']) && $_GET['status'] === 'saved'): ?>
          <div class="flash success">SVG opgeslagen in de library.</div>
        <?php elseif (isset($_GET['status']) && $_GET['status'] === 'updated'): ?>
          <div class="flash success">Library-item bijgewerkt.</div>
        <?php elseif (isset($_GET['status']) && $_GET['status'] === 'deleted'): ?>
          <div class="flash success">Library-item verwijderd.</div>
        <?php elseif (isset($_GET['status']) && $_GET['status'] === 'rack-deleted'): ?>
          <div class="flash success">Rack verwijderd.</div>
        <?php elseif (isset($_GET['status']) && $_GET['status'] === 'rack-duplicated'): ?>
          <div class="flash success">Rack gedupliceerd.</div>
        <?php elseif (isset($_GET['status']) && $_GET['status'] === 'error'): ?>
          <div class="flash error"><?php echo htmlspecialchars($_GET['message'] ?? 'Opslaan mislukt.', ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <section class="panel">
          <div class="panel-head panel-head-with-action">
            <div>
              <h3>Opgeslagen racks</h3>
              <p>Gebruik de aparte beheerpagina voor openen, verwijderen, dupliceren en filteren.</p>
            </div>
            <a class="button secondary" href="saved_racks.php">Alles bekijken</a>
          </div>
          <div class="panel-body stack">
            <?php if ($rackList !== []): ?>
              <?php foreach (array_slice($rackList, 0, 2) as $rackEntry): ?>
                <a class="summary-box saved-rack-link" href="index.php?rack=<?php echo (int)$rackEntry['id']; ?>">
                  <strong><?php echo htmlspecialchars($rackEntry['rackName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                  <span><?php echo htmlspecialchars($rackEntry['versionLabel'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($rackEntry['issueDate'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo (int)$rackEntry['rackUnits']; ?> HE</span>
                  <?php if (($rackEntry['location'] ?? '') !== '' || ($rackEntry['project'] ?? '') !== ''): ?>
                    <span><?php echo htmlspecialchars(trim(($rackEntry['location'] ?? '') . ' · ' . ($rackEntry['project'] ?? ''), ' ·'), ENT_QUOTES, 'UTF-8'); ?></span>
                  <?php endif; ?>
                </a>
              <?php endforeach; ?>
              <?php if (count($rackList) > 2): ?>
                <div class="summary-box">
                  <strong>Nog <?php echo count($rackList) - 2; ?> racks</strong>
                  <span>Open de beheerpagina om alles te zien en te beheren.</span>
                </div>
              <?php endif; ?>
            <?php else: ?>
              <div class="summary-box">
                <strong>Nog geen opgeslagen racks</strong>
                <span>Sla je eerste opzet op en beheer ze straks via de overzichtspagina.</span>
              </div>
            <?php endif; ?>
          </div>
        </section>


        <section class="panel">
          <div class="panel-head panel-head-with-action">
            <div>
              <h3>Templates</h3>
              <p>Beheer branding, velden en duplicaten op een aparte pagina.</p>
            </div>
            <a class="button secondary" href="saved_templates.php">Alles bekijken</a>
          </div>
          <div class="panel-body stack">
            <?php if ($templates !== []): ?>
              <?php foreach (array_slice($templates, 0, 2) as $templateEntry): ?>
                <a class="summary-box saved-rack-link" href="template_editor.php<?php echo isset($templateEntry['id']) && $templateEntry['id'] ? '?id=' . (int)$templateEntry['id'] : ''; ?>">
                  <strong><?php echo htmlspecialchars($templateEntry['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                  <span><?php echo htmlspecialchars($templateEntry['slug'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($templateEntry['documentTitle'], ENT_QUOTES, 'UTF-8'); ?></span>
                  <?php if (!empty($templateEntry['isDefault'])): ?>
                    <span>Default template</span>
                  <?php endif; ?>
                </a>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="summary-box">
                <strong>Nog geen templates</strong>
                <span>Maak je eerste exporttemplate aan via de beheerpagina.</span>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <section class="panel">
          <div class="panel-head panel-head-with-action">
            <div>
              <h3>Library</h3>
              <p>Compacte iconen. Hover voor de naam, klik om te plaatsen.</p>
            </div>
            <button class="button secondary small" type="button" id="openLibraryModal">Beheer</button>
          </div>
          <div class="panel-body">
            <div class="library-tabs" id="libraryTabs">
              <button class="library-tab-button active" type="button" data-library-category="fixed">Vaste kast inhoud</button>
              <button class="library-tab-button" type="button" data-library-category="devices">Devices</button>
            </div>
            <div id="libraryGrid" class="library-grid"></div>
            <div id="emptyLibrary" class="empty-library" style="display:none;">Er staan nog geen items in deze categorie. Open Beheer om items toe te voegen of te categoriseren.</div>
            <details class="ratio-help">
              <summary>SVG aspect ratio hulp (1000 px breed)</summary>
              <div class="ratio-help-body">
                <p>Voor full-width 19-inch items geldt: 1U = ongeveer 92 px hoog bij 1000 px breed. Formule: hoogte = 1000 × (1.75 / 19) × aantal U.</p>
                <div class="ratio-help-grid">
                  <span>1U = 1000 × 92 px</span>
                  <span>6U = 1000 × 552 px</span>
                  <span>2U = 1000 × 184 px</span>
                  <span>7U = 1000 × 644 px</span>
                  <span>3U = 1000 × 276 px</span>
                  <span>8U = 1000 × 736 px</span>
                  <span>4U = 1000 × 368 px</span>
                  <span>9U = 1000 × 828 px</span>
                  <span>5U = 1000 × 460 px</span>
                  <span>10U = 1000 × 920 px</span>
                </div>
              </div>
            </details>
          </div>
        </section>
      </div>
    </aside>

    <main class="workspace-shell">
      <div class="workspace-stage">
        <section class="panel workspace-panel">
          <div class="panel-head">
            <h2>Paginavoorbeeld en rackcanvas</h2>
            <p>Werk in de gewenste view. De zwevende actiebalk hierboven blijft beschikbaar voor opslaan, print/PDF en het leegmaken van de actieve zijde.</p>
          </div>
          <div class="panel-body">
            <div class="page-preview-wrap">
              <div class="page-preview" id="pagePreview" data-template="coolblue-v1">
                <div class="page-top" id="pageTop">
                  <div class="brand-logo" id="brandLogo" aria-hidden="true">logo</div>
                  <div class="page-doc-title" id="pageDocTitle">Rack Design</div>
                  <div class="page-top-right">
                    <div class="page-rack-title" id="pageRackTitle">MER - Rack 1 Front</div>
                    <div class="face-chip" id="faceChip">Front view</div>
                  </div>
                </div>
                <div class="page-body">
                  <div class="rack-sheet">
                    <div class="rack" id="rackRoot">
                      <div class="rack-labels" id="leftLabels"></div>
                      <div class="rack-shell">
                        <div class="rack-rail left"></div>
                        <div class="rack-rail right"></div>
                        <div class="rack-canvas" id="rackCanvas">
                          <div class="rack-empty" id="rackEmpty">Nog geen items in deze view.<br>Plaats links een item in de actieve zijde.</div>
                        </div>
                      </div>
                      <div class="rack-labels" id="rightLabels"></div>
                    </div>
                    <div class="comments-lane" id="commentsLane"></div>
                  </div>
                </div>
                <div class="page-footer">
                  <div class="meta">
                    <span id="footerTemplate">Coolblue V1 template</span>
                    <span id="footerRack">MER - Rack 1</span>
                  </div>
                  <div class="meta">
                    <span id="footerVersionDate">V1 - 2026-05-21</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>
    </main>

    <aside class="sidebar sidebar-right" id="rightSidebar">
      <div class="sidebar-top">
        <div class="sidebar-brand">
          <div class="sidebar-title-text">Rack info</div>
          <div class="sidebar-subtitle">Metadata, template en active view</div>
        </div>
        <div class="sidebar-actions">
          <button class="icon-button" type="button" id="toggleRightSidebar" title="Rechter sidebar inklappen">❯</button>
        </div>
      </div>
      <div class="sidebar-content">
        <section class="panel">
          <div class="panel-head">
            <h2>Documentinstellingen</h2>
            <p>Hier vul je de gegevens in voor de editor en de A4-export.</p>
          </div>
          <div class="panel-body stack">
            <div class="control-grid compact">
              <div class="control-field">
                <label for="templateSelect">Template</label>
                <select id="templateSelect">
                  <?php foreach ($templates as $template): ?>
                    <option value="<?php echo htmlspecialchars($template['slug'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($template['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                  <?php endforeach; ?>
                </select>
                <a class="button secondary" href="saved_templates.php" style="margin-top:10px; width:100%;">Templates beheren</a>
              </div>
              <div class="control-field">
                <label for="rackUnits">Rackhoogte (HE)</label>
                <input id="rackUnits" type="number" min="1" max="60" step="1" value="24">
              </div>
              <div class="control-field">
                <label for="versionLabel">Versie</label>
                <input id="versionLabel" type="text" value="V1" required>
              </div>
              <div class="control-field">
                <label for="issueDate">Datum</label>
                <input id="issueDate" type="date">
              </div>
            </div>

            <div class="control-grid">
              <div class="control-field">
                <label for="rackName">Racknaam</label>
                <input id="rackName" type="text" value="MER - Rack 1" required>
              </div>
              <div class="control-field">
                <label for="locationInput">Locatie</label>
                <input id="locationInput" type="text" value="" placeholder="Bijv. MER of winkelnaam">
              </div>
              <div class="control-field">
                <label for="projectInput">Project</label>
                <input id="projectInput" type="text" value="" placeholder="Bijv. Opening Delft">
              </div>
              <div class="control-field">
                <label>View</label>
                <div class="face-toggle" id="faceToggle">
                  <button type="button" data-face="front" class="active">Front</button>
                  <button type="button" data-face="back">Back</button>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>
    </aside>
  </div>

  <div class="library-modal" id="libraryModal">
    <div class="library-modal-backdrop" data-close-library="1"></div>
    <div class="library-modal-panel panel">
      <div class="panel-head">
        <div class="library-modal-head">
          <div>
            <h2>Library beheren</h2>
            <p>Bekijk je volledige library, voeg nieuwe SVG-items toe en beheer bestaande onderdelen in detail.</p>
          </div>
          <div class="library-modal-actions">
            <button class="button primary" type="button" id="openUploadDrawerFromModal">Nieuwe SVG</button>
            <button class="icon-button" type="button" data-close-library="1" title="Sluiten">×</button>
          </div>
        </div>
      </div>
      <div class="panel-body">
        <div class="library-manager-tabs" id="libraryManagerTabs">
          <button class="library-manager-tab-button active" type="button" data-library-manager-category="all">Alles</button>
          <button class="library-manager-tab-button" type="button" data-library-manager-category="fixed">Vaste kast inhoud</button>
          <button class="library-manager-tab-button" type="button" data-library-manager-category="devices">Devices</button>
          <button class="library-manager-tab-button" type="button" data-library-manager-category="uncategorized">Ongecategoriseerd</button>
        </div>
        <div id="libraryManagerGrid" class="library-manager-grid"></div>
        <div id="emptyLibraryManager" class="empty-library" style="display:none;">Er zijn geen items in deze selectie. Voeg hier een nieuw item toe of wijzig categorieën.</div>
      </div>
    </div>
  </div>

  <div class="upload-drawer<?php echo $isEditMode ? ' open' : ''; ?>" id="uploadDrawer">
    <div class="upload-backdrop" data-close-upload="1"></div>
    <div class="upload-panel panel">
      <div class="panel-head">
        <div class="upload-head-row">
          <div>
            <h2><?php echo $isEditMode ? 'Library-item bewerken' : 'Nieuwe SVG toevoegen'; ?></h2>
            <p><?php echo $isEditMode ? 'Pas metadata aan en vervang optioneel de SVG.' : 'Upload een custom SVG en koppel meteen metadata voor rackgebruik.'; ?></p>
          </div>
          <button class="icon-button" type="button" data-close-upload="1" title="Sluiten">×</button>
        </div>
      </div>
      <div class="panel-body">
        <form action="save_item.php" method="post" enctype="multipart/form-data">
          <?php if ($isEditMode): ?>
            <input type="hidden" name="existing_id" value="<?php echo htmlspecialchars($editItem['id']); ?>">
            <input type="hidden" name="existing_svg_url" value="<?php echo htmlspecialchars($editItem['svgUrl']); ?>">
          <?php endif; ?>
          <div class="field">
            <label for="name">Basisnaam</label>
            <input id="name" name="name" type="text" placeholder="Bijv. Legplank" value="<?php echo htmlspecialchars($editItem['baseName'] ?? ''); ?>" required>
            <div class="help">De app voegt automatisch de HE toe aan de naam, bijvoorbeeld: Legplank 2U.</div>
          </div>

          <div class="field">
            <label for="category">Categorie</label>
            <select id="category" name="category">
              <option value="fixed" <?php echo (($editItem['category'] ?? 'uncategorized') === 'fixed') ? 'selected' : ''; ?>>Vaste kast inhoud</option>
              <option value="devices" <?php echo (($editItem['category'] ?? 'uncategorized') === 'devices') ? 'selected' : ''; ?>>Devices</option>
              <option value="uncategorized" <?php echo (($editItem['category'] ?? 'uncategorized') === 'uncategorized') ? 'selected' : ''; ?>>Ongecategoriseerd</option>
            </select>
            <div class="help">Gebruik Vaste kast inhoud voor vaste kastcomponenten en Devices voor actieve apparatuur.</div>
          </div>

          <div class="field">
            <label for="he">Hoogte (HE)</label>
            <input id="he" name="he" type="number" min="1" max="20" step="1" value="<?php echo htmlspecialchars((string)($editItem['he'] ?? 1)); ?>" required>
          </div>

          <div class="field">
            <label for="widthPct">Breedte (% van 19-inch front)</label>
            <input id="widthPct" name="widthPct" type="number" min="10" max="100" step="1" value="<?php echo htmlspecialchars((string)($editItem['widthPct'] ?? 100)); ?>" required>
            <div class="help">Gebruik bijvoorbeeld 100 voor volle 19-inch breedte of 50 voor half-width.</div>
            <details class="ratio-help compact">
              <summary>Aspect ratio hulp bij 1000 px breed</summary>
              <div class="ratio-help-body">
                <p>Full-width 19-inch: 1U = 92 px hoog. Dus 2U = 184 px, 4U = 368 px, 6U = 552 px, 10U = 920 px.</p>
              </div>
            </details>
          </div>

          <div class="field">
            <label for="svg">SVG-bestand <?php echo $isEditMode ? '(optioneel vervangen)' : ''; ?></label>
            <input id="svg" name="svg" type="file" accept=".svg,image/svg+xml" <?php echo $isEditMode ? '' : 'required'; ?>>
            <div class="help"><?php echo $isEditMode ? 'Laat leeg om de huidige SVG te behouden.' : 'Alleen SVG. De app controleert of het bestand echt SVG-inhoud bevat.'; ?></div>
          </div>

          <div class="toolbar-row" style="justify-content:flex-start;">
            <button class="button primary" type="submit"><?php echo $isEditMode ? 'Wijzigingen opslaan' : 'Opslaan in library'; ?></button>
            <button class="button secondary" type="button" data-close-upload="1">Sluiten</button>
            <?php if ($isEditMode): ?>
              <a class="button secondary" href="index.php">Annuleren</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="print-root" id="printRoot"></div>

  <script>
    const library = <?php echo json_encode($library, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const templates = <?php echo json_encode($templates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const serverRack = <?php echo json_encode($selectedRack, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const STORAGE_KEY = 'rackPlannerState.v3';
    const isNewRackMode = new URLSearchParams(window.location.search).has('new');
    const todayIso = new Date().toISOString().slice(0, 10);
    const templateMap = new Map((templates || []).filter(template => template && template.slug).map(template => [template.slug, template]));
    const defaultTemplate = (templates || []).find(template => template && template.isDefault) || (templates || [])[0] || { slug: 'coolblue-v1', name: 'Coolblue V1', documentTitle: 'Rack Design' };

    const state = {
      rackId: null,
      rackUnits: 24,
      items: [],
      dragging: null,
      nextId: 1,
      template: defaultTemplate.slug || 'coolblue-v1',
      docTitle: defaultTemplate.documentTitle || 'Rack Design',
      rackName: 'MER - Rack 1',
      location: '',
      project: '',
      versionLabel: 'V1',
      issueDate: todayIso,
      currentFace: 'front'
    };

    const U_HEIGHT = 28;
    const rackUnitsInput = document.getElementById('rackUnits');
    const rackCanvas = document.getElementById('rackCanvas');
    const leftLabels = document.getElementById('leftLabels');
    const rightLabels = document.getElementById('rightLabels');
    const libraryGrid = document.getElementById('libraryGrid');
    const emptyLibrary = document.getElementById('emptyLibrary');
    const libraryTabs = document.getElementById('libraryTabs');
    const libraryManagerGrid = document.getElementById('libraryManagerGrid');
    const libraryManagerTabs = document.getElementById('libraryManagerTabs');
    const emptyLibraryManager = document.getElementById('emptyLibraryManager');
    const rackEmpty = document.getElementById('rackEmpty');
    const commentsLane = document.getElementById('commentsLane');
    const clearFaceButton = document.getElementById('clearFace');
    const printButton = document.getElementById('printPage');
    const saveRackButton = document.getElementById('saveRackButton');
    const templateSelect = document.getElementById('templateSelect');
    const rackNameInput = document.getElementById('rackName');
    const locationInput = document.getElementById('locationInput');
    const projectInput = document.getElementById('projectInput');
    const versionLabelInput = document.getElementById('versionLabel');
    const issueDateInput = document.getElementById('issueDate');
    const faceToggle = document.getElementById('faceToggle');
    const pagePreview = document.getElementById('pagePreview');
    const brandLogo = document.getElementById('brandLogo');
    const pageDocTitle = document.getElementById('pageDocTitle');
    const pageRackTitle = document.getElementById('pageRackTitle');
    const faceChip = document.getElementById('faceChip');
    const footerTemplate = document.getElementById('footerTemplate');
    const footerRack = document.getElementById('footerRack');
    const footerVersionDate = document.getElementById('footerVersionDate');
    const printRoot = document.getElementById('printRoot');
    const leftSidebar = document.getElementById('leftSidebar');
    const rightSidebar = document.getElementById('rightSidebar');
    const toggleLeftSidebar = document.getElementById('toggleLeftSidebar');
    const toggleRightSidebar = document.getElementById('toggleRightSidebar');
    const uploadDrawer = document.getElementById('uploadDrawer');
    const libraryModal = document.getElementById('libraryModal');
    const openLibraryModal = document.getElementById('openLibraryModal');
    const openUploadDrawerFromModal = document.getElementById('openUploadDrawerFromModal');
    let currentLibraryCategory = 'fixed';
    let currentLibraryManagerCategory = 'all';

    function escapeHtml(value) {
      return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    }

    function escapeXml(value) {
      return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&apos;');
    }

    function faceLabel(face) {
      return face === 'back' ? 'Back' : 'Front';
    }

    function libraryCategoryLabel(category) {
      if (category === 'fixed') return 'Vaste kast inhoud';
      if (category === 'devices') return 'Devices';
      return 'Ongecategoriseerd';
    }

    function normalizeLibraryCategory(category) {
      if (category === 'fixed' || category === 'devices') return category;
      return 'uncategorized';
    }

    function getLibraryItemsByCategory(category) {
      return library.filter(item => normalizeLibraryCategory(item.category) === category);
    }

    function getLibraryItemsForManager(category) {
      if (category === 'all') return library.slice();
      return library.filter(item => normalizeLibraryCategory(item.category) === category);
    }

    function getTemplateBySlug(slug) {
      return templateMap.get(slug) || defaultTemplate;
    }

    function getTemplateLogoSource(template) {
      if (!template) return '';
      return template.logoPath || template.logoDataUri || '';
    }

    function renderTemplateLogo(template) {
      const logoSource = getTemplateLogoSource(template);
      brandLogo.classList.remove('has-image');
      if (logoSource) {
        brandLogo.innerHTML = `<img src="${escapeHtml(logoSource)}" alt="${escapeHtml((template && template.name) ? template.name : 'Template logo')}">`;
        brandLogo.style.background = '#ffffff';
        brandLogo.classList.add('has-image');
        return;
      }

      if (template && template.slug === 'plain-v1') {
        brandLogo.textContent = 'rack';
        brandLogo.style.background = '#334155';
      } else {
        brandLogo.innerHTML = 'cool<br>blue';
        brandLogo.style.background = 'var(--coolblue-orange)';
      }
    }

    function syncTemplateTitle() {
      const template = getTemplateBySlug(state.template);
      state.docTitle = (template && template.documentTitle) ? template.documentTitle : 'Rack Design';
    }

    function setActionButtonLabel(button, label) {
      if (!button) return;
      const labelNode = button.querySelector('.button-label');
      if (labelNode) {
        labelNode.textContent = label;
      } else {
        button.textContent = label;
      }
    }

    function refreshSaveButton() {
      if (!saveRackButton) return;
      const isExistingRack = Number.isInteger(state.rackId) && state.rackId > 0;
      setActionButtonLabel(saveRackButton, isExistingRack ? 'Rack opslaan' : 'Nieuw rack opslaan');
      saveRackButton.title = isExistingRack ? 'Werk het huidige opgeslagen rack bij' : 'Sla dit rack op als nieuw record op';
    }

    function getLibraryMap() {
      return new Map(library.filter(item => item && item.id).map(item => [item.id, item]));
    }

    function normalizeComment(comment) {
      if (typeof comment === 'string') return comment;
      if (Array.isArray(comment)) return comment.filter(Boolean).join(' | ');
      return '';
    }

    function normalizeItem(item) {
      if (!item || !item.svgUrl || !item.name) return null;
      const he = parseInt(item.he, 10);
      const widthPct = parseInt(item.widthPct, 10);
      const uStart = parseInt(item.uStart, 10);
      if (!Number.isInteger(he) || !Number.isInteger(widthPct) || !Number.isInteger(uStart)) return null;

      return {
        instanceId: Number.isInteger(item.instanceId) ? item.instanceId : state.nextId++,
        libraryId: typeof item.libraryId === 'string' ? item.libraryId : null,
        name: item.name,
        he,
        widthPct,
        svgUrl: item.svgUrl,
        svgDataUri: typeof item.svgDataUri === 'string' ? item.svgDataUri : null,
        uStart,
        comments: normalizeComment(item.comments),
        face: item.face === 'back' ? 'back' : 'front'
      };
    }

    function hydrateFromSavedRack(payload) {
      if (!payload || typeof payload !== 'object') return;
      state.rackId = Number.isInteger(payload.id) ? payload.id : (Number.isInteger(payload.rackId) ? payload.rackId : null);
      state.rackUnits = Math.max(1, Math.min(60, parseInt(payload.rackUnits, 10) || 24));
      state.items = Array.isArray(payload.items) ? payload.items.map(normalizeItem).filter(Boolean) : [];
      state.nextId = Math.max(
        parseInt(payload.nextId, 10) || 1,
        state.items.reduce((max, item) => Math.max(max, item.instanceId || 0), 0) + 1
      );
      if (typeof payload.template === 'string' && payload.template) state.template = payload.template;
      state.rackName = typeof payload.rackName === 'string' ? payload.rackName : state.rackName;
      state.location = typeof payload.location === 'string' ? payload.location : '';
      state.project = typeof payload.project === 'string' ? payload.project : '';
      state.versionLabel = typeof payload.versionLabel === 'string' && payload.versionLabel !== '' ? payload.versionLabel : 'V1';
      state.issueDate = typeof payload.issueDate === 'string' && payload.issueDate !== '' ? payload.issueDate : todayIso;
      state.currentFace = payload.currentFace === 'back' ? 'back' : 'front';
      syncTemplateTitle();
      refreshSaveButton();
    }

    function reconcileRackItemsWithLibrary() {
      const libraryMap = getLibraryMap();
      state.items = state.items
        .map(item => {
          const normalized = normalizeItem(item);
          if (!normalized) return null;
          if (normalized.libraryId && libraryMap.has(normalized.libraryId)) {
            const libItem = libraryMap.get(normalized.libraryId);
            normalized.name = libItem.name;
            normalized.he = parseInt(libItem.he, 10);
            normalized.widthPct = parseInt(libItem.widthPct, 10);
            normalized.svgUrl = libItem.svgUrl;
            normalized.svgDataUri = libItem.svgDataUri || normalized.svgDataUri || null;
          }
          return normalized;
        })
        .filter(Boolean)
        .filter(item => item.uStart + item.he - 1 <= state.rackUnits);
    }

    function saveState() {
      localStorage.setItem(STORAGE_KEY, JSON.stringify({
        rackId: state.rackId,
        rackUnits: state.rackUnits,
        items: state.items.map(({ svgDataUri, ...item }) => item),
        nextId: state.nextId,
        template: state.template,
        rackName: state.rackName,
        location: state.location,
        project: state.project,
        versionLabel: state.versionLabel,
        issueDate: state.issueDate,
        currentFace: state.currentFace
      }));
    }

    function loadState() {
      if (isNewRackMode) {
        localStorage.removeItem(STORAGE_KEY);
        state.rackId = null;
        state.template = defaultTemplate.slug || 'coolblue-v1';
        state.docTitle = defaultTemplate.documentTitle || 'Rack Design';
        state.rackName = 'MER - Rack 1';
        state.location = '';
        state.project = '';
        state.versionLabel = 'V1';
        state.issueDate = todayIso;
        state.currentFace = 'front';
        state.items = [];
        state.nextId = 1;
        refreshSaveButton();
        return;
      }

      if (serverRack && typeof serverRack === 'object') {
        hydrateFromSavedRack(serverRack);
        return;
      }

      try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) {
          syncTemplateTitle();
          refreshSaveButton();
          return;
        }
        const saved = JSON.parse(raw);
        hydrateFromSavedRack(saved);
      } catch (error) {
        console.warn('Kon opgeslagen rack state niet laden', error);
      }
    }

    function getVisibleItems() {
      return state.items.filter(item => item.face === state.currentFace);
    }

    function getPageTitle() {
      const base = state.rackName.trim() || 'Rack';
      return `${base} ${faceLabel(state.currentFace)}`;
    }

    function renderMetadata() {
      syncTemplateTitle();
      if ([...templateSelect.options].some(option => option.value === state.template)) {
        templateSelect.value = state.template;
      } else if (templateSelect.options[0]) {
        state.template = templateSelect.options[0].value;
        syncTemplateTitle();
        templateSelect.value = state.template;
      }
      rackNameInput.value = state.rackName;
      locationInput.value = state.location;
      projectInput.value = state.project;
      versionLabelInput.value = state.versionLabel;
      issueDateInput.value = state.issueDate;
      rackUnitsInput.value = state.rackUnits;
      document.documentElement.style.setProperty('--rack-units', state.rackUnits);

      [...faceToggle.querySelectorAll('button')].forEach(button => {
        button.classList.toggle('active', button.dataset.face === state.currentFace);
      });

      const template = getTemplateBySlug(state.template);
      pagePreview.dataset.template = state.template;
      pagePreview.classList.toggle('face-back', state.currentFace === 'back');
      pageDocTitle.textContent = state.docTitle.trim() || 'Rack Design';
      pageRackTitle.textContent = getPageTitle();
      faceChip.textContent = `${faceLabel(state.currentFace)} view`;
      footerRack.textContent = [state.rackName.trim() || 'Rack', state.location.trim(), state.project.trim()].filter(Boolean).join(' · ');
      footerVersionDate.textContent = `${state.versionLabel || 'V1'} - ${state.issueDate || todayIso}`;
      footerTemplate.textContent = `${template.name || 'Template'} template`;
      renderTemplateLogo(template);
    }

    function setRackUnits(units) {
      state.rackUnits = Math.max(1, Math.min(60, parseInt(units, 10) || 24));
      reconcileRackItemsWithLibrary();
      renderLabels();
      renderItems();
      renderMetadata();
      saveState();
    }

    function renderLabels() {
      leftLabels.innerHTML = '';
      rightLabels.innerHTML = '';
      const totalHeight = state.rackUnits * U_HEIGHT;
      leftLabels.style.height = totalHeight + 'px';
      rightLabels.style.height = totalHeight + 'px';

      for (let u = 1; u <= state.rackUnits; u += 1) {
        const top = totalHeight - ((u - 0.5) * U_HEIGHT);
        const label = `<div class="rack-label" style="top:${top}px">U${u}</div>`;
        leftLabels.insertAdjacentHTML('beforeend', label);
        rightLabels.insertAdjacentHTML('beforeend', label);
      }
    }

    function renderLibrary() {
      reconcileRackItemsWithLibrary();
      libraryGrid.innerHTML = '';
      if (libraryManagerGrid) libraryManagerGrid.innerHTML = '';

      const sidebarItems = getLibraryItemsByCategory(currentLibraryCategory);
      const managerItems = getLibraryItemsForManager(currentLibraryManagerCategory);

      if (libraryTabs) {
        libraryTabs.querySelectorAll('[data-library-category]').forEach(button => {
          button.classList.toggle('active', button.dataset.libraryCategory === currentLibraryCategory);
        });
      }

      if (libraryManagerTabs) {
        libraryManagerTabs.querySelectorAll('[data-library-manager-category]').forEach(button => {
          button.classList.toggle('active', button.dataset.libraryManagerCategory === currentLibraryManagerCategory);
        });
      }

      emptyLibrary.style.display = sidebarItems.length === 0 ? 'block' : 'none';
      if (emptyLibraryManager) emptyLibraryManager.style.display = managerItems.length === 0 ? 'block' : 'none';
      const placeLabel = `Plaats op ${faceLabel(state.currentFace)}`;

      sidebarItems.forEach(item => {
        const iconButton = document.createElement('button');
        iconButton.className = 'library-icon-button';
        iconButton.type = 'button';
        iconButton.title = item.name;
        iconButton.setAttribute('aria-label', item.name);
        iconButton.innerHTML = `<img src="${escapeHtml(item.svgUrl)}" alt="${escapeHtml(item.name)}">`;
        iconButton.addEventListener('click', () => addLibraryItemToRack(item));
        libraryGrid.appendChild(iconButton);
      });

      managerItems.forEach(item => {
        if (!libraryManagerGrid) return;
        const card = document.createElement('div');
        card.className = 'library-card';
        card.innerHTML = `
          <img src="${escapeHtml(item.svgUrl)}" alt="${escapeHtml(item.name)}">
          <div>
            <div class="library-category-badge">${escapeHtml(libraryCategoryLabel(normalizeLibraryCategory(item.category)))}</div>
            <h4>${escapeHtml(item.name)}</h4>
            <div class="library-meta">${item.he} HE · ${item.widthPct}% breedte</div>
            <div class="library-actions">
              <button class="button secondary small place-button" type="button">${escapeHtml(placeLabel)}</button>
              <a class="button secondary small" href="index.php?edit=${encodeURIComponent(item.id)}${state.rackId ? `&rack=${encodeURIComponent(state.rackId)}` : ''}">Bewerk</a>
              <form action="delete_item.php" method="post" onsubmit="return confirm('Weet je zeker dat je dit library-item wilt verwijderen?');">
                <input type="hidden" name="id" value="${escapeHtml(item.id)}">
                <button class="button danger small" type="submit">Verwijder</button>
              </form>
            </div>
          </div>
        `;
        card.querySelector('.place-button').addEventListener('click', () => addLibraryItemToRack(item));
        libraryManagerGrid.appendChild(card);
      });
    }

    function findNextAvailableStart(he, face = state.currentFace) {
      const occupied = new Set();
      state.items.filter(item => item.face === face).forEach(item => {
        for (let u = item.uStart; u < item.uStart + item.he; u += 1) occupied.add(u);
      });
      for (let start = 1; start <= state.rackUnits - he + 1; start += 1) {
        let fits = true;
        for (let u = start; u < start + he; u += 1) {
          if (occupied.has(u)) {
            fits = false;
            break;
          }
        }
        if (fits) return start;
      }
      return 1;
    }

    function addLibraryItemToRack(libItem) {
      const he = parseInt(libItem.he, 10);
      const maxStart = Math.max(1, state.rackUnits - he + 1);
      const nextStart = Math.min(maxStart, findNextAvailableStart(he, state.currentFace));
      state.items.push({
        instanceId: state.nextId++,
        libraryId: libItem.id,
        name: libItem.name,
        he,
        widthPct: parseInt(libItem.widthPct, 10),
        category: normalizeLibraryCategory(libItem.category),
        svgUrl: libItem.svgUrl,
        svgDataUri: libItem.svgDataUri || null,
        uStart: nextStart,
        comments: '',
        face: state.currentFace
      });
      renderItems();
      saveState();
    }

    function getItemTop(item) {
      return (state.rackUnits - (item.uStart + item.he - 1)) * U_HEIGHT;
    }

    function updateComment(instanceId, value) {
      const item = state.items.find(entry => entry.instanceId === instanceId);
      if (!item) return;
      item.comments = value;
      saveState();
    }

    function clampNumber(value, min, max) {
      return Math.max(min, Math.min(max, value));
    }

    function calculateStackedCommentLayouts(layoutItems, minY = 0, maxY = null, gap = 12) {
      if (!Array.isArray(layoutItems) || !layoutItems.length) return [];

      const positioned = layoutItems.map(layout => ({ ...layout }));
      let cursor = minY;

      positioned.forEach(layout => {
        layout.boxY = Math.max(layout.desiredY, cursor);
        cursor = layout.boxY + layout.boxH + gap;
      });

      if (typeof maxY === 'number' && Number.isFinite(maxY) && positioned.length) {
        const last = positioned[positioned.length - 1];
        const bottom = last.boxY + last.boxH;

        if (bottom > maxY) {
          last.boxY = Math.max(minY, maxY - last.boxH);

          for (let i = positioned.length - 2; i >= 0; i -= 1) {
            const maxAllowed = positioned[i + 1].boxY - gap - positioned[i].boxH;
            positioned[i].boxY = Math.min(positioned[i].boxY, maxAllowed);
          }

          if (positioned[0].boxY < minY) {
            positioned[0].boxY = minY;
            for (let i = 1; i < positioned.length; i += 1) {
              const minAllowed = positioned[i - 1].boxY + positioned[i - 1].boxH + gap;
              positioned[i].boxY = Math.max(positioned[i].boxY, minAllowed);
            }
          }
        }
      }

      return positioned.map(layout => {
        const anchorInset = Math.min(18, Math.max(8, layout.boxH / 2));
        const anchorY = clampNumber(
          layout.anchorY != null ? layout.anchorY : (layout.desiredY + (layout.boxH / 2)),
          layout.boxY + anchorInset,
          layout.boxY + layout.boxH - anchorInset
        );
        return { ...layout, anchorY };
      });
    }

    function calculateTwoColumnCommentLayouts(layoutItems, minY = 0, maxY = null, gap = 12) {
      if (!Array.isArray(layoutItems) || !layoutItems.length) return [];

      const sortedItems = layoutItems
        .map(layout => ({ ...layout }))
        .sort((a, b) => {
          if (a.desiredY === b.desiredY) {
            return (a.item?.uPosition || 0) - (b.item?.uPosition || 0);
          }
          return a.desiredY - b.desiredY;
        });

      const limitY = (typeof maxY === 'number' && Number.isFinite(maxY)) ? maxY : null;
      const columnBuckets = [[], []];
      const columnCursors = [minY, minY];

      sortedItems.forEach(layout => {
        const options = [0, 1].map(columnIndex => {
          const y = Math.max(layout.desiredY, columnCursors[columnIndex]);
          const overflow = (limitY !== null && (y + layout.boxH > limitY));
          return { columnIndex, y, overflow };
        });

        options.sort((a, b) => {
          if (a.overflow !== b.overflow) return a.overflow ? 1 : -1;
          if (a.y !== b.y) return a.y - b.y;
          return a.columnIndex - b.columnIndex;
        });

        const choice = options[0];
        columnBuckets[choice.columnIndex].push(layout);
        columnCursors[choice.columnIndex] = choice.y + layout.boxH + gap;
      });

      return columnBuckets.flatMap((bucket, columnIndex) => {
        const positioned = calculateStackedCommentLayouts(bucket, minY, maxY, gap);
        return positioned.map(layout => ({ ...layout, columnIndex }));
      });
    }

    function renderComments() {
      const visibleItems = getVisibleItems().slice().sort((a, b) => getItemTop(a) - getItemTop(b));
      commentsLane.innerHTML = '';

      const rackHeight = state.rackUnits * U_HEIGHT;
      commentsLane.style.minHeight = rackHeight + 'px';

      if (!visibleItems.length) {
        commentsLane.innerHTML = '<div class="comments-empty">Plaats een item in deze view om hier per plaatsing een comment toe te voegen.</div>';
        return;
      }

      const commentLayouts = calculateStackedCommentLayouts(
        visibleItems.map(item => ({
          item,
          desiredY: getItemTop(item),
          anchorY: getItemTop(item) + (item.he * U_HEIGHT / 2),
          boxH: Math.max(item.he * U_HEIGHT, 74)
        })),
        0,
        null,
        12
      );

      const finalHeight = commentLayouts.reduce((maxHeight, layout) => Math.max(maxHeight, layout.boxY + layout.boxH), rackHeight);
      commentsLane.style.minHeight = finalHeight + 'px';

      commentLayouts.forEach(({ item, boxY, boxH, anchorY }) => {
        const card = document.createElement('div');
        card.className = 'comment-card';
        card.style.top = boxY + 'px';
        card.style.minHeight = boxH + 'px';
        card.style.setProperty('--connector-top', Math.max(16, Math.min(boxH - 16, anchorY - boxY)) + 'px');
        card.innerHTML = `
          <div class="comment-head">
            <span class="comment-title">${escapeHtml(item.name)}</span>
            <span>${faceLabel(item.face)} · ${item.he} HE · U${item.uStart}</span>
          </div>
          <input class="comment-body" type="text" value="${escapeHtml(normalizeComment(item.comments))}" placeholder="${escapeHtml(item.name)} - comment">
        `;

        const input = card.querySelector('.comment-body');
        input.addEventListener('input', (event) => updateComment(item.instanceId, event.target.value));
        commentsLane.appendChild(card);
      });
    }

    function renderItems() {
      reconcileRackItemsWithLibrary();
      const visibleItems = getVisibleItems();
      rackCanvas.querySelectorAll('.rack-item').forEach(el => el.remove());
      rackEmpty.style.display = visibleItems.length ? 'none' : 'grid';

      visibleItems.forEach(item => {
        const el = document.createElement('div');
        el.className = 'rack-item';
        el.dataset.instanceId = item.instanceId;
        el.style.width = item.widthPct + '%';
        el.style.height = (item.he * U_HEIGHT) + 'px';
        el.style.top = getItemTop(item) + 'px';
        el.innerHTML = `
          <img src="${escapeHtml(item.svgUrl)}" alt="${escapeHtml(item.name)}">
          <div class="badge">${escapeHtml(item.name)} · ${item.he} HE</div>
          <button class="remove" type="button" aria-label="Verwijderen">×</button>
        `;

        el.querySelector('.remove').addEventListener('click', (event) => {
          event.stopPropagation();
          state.items = state.items.filter(entry => entry.instanceId !== item.instanceId);
          renderItems();
          saveState();
        });

        el.addEventListener('pointerdown', (event) => startDrag(event, item.instanceId));
        rackCanvas.appendChild(el);
      });

      renderComments();
      renderMetadata();
    }

    function startDrag(event, instanceId) {
      const target = event.currentTarget;
      if (event.target.closest('.remove')) return;
      const rect = rackCanvas.getBoundingClientRect();
      const item = state.items.find(entry => entry.instanceId === instanceId);
      if (!item) return;

      state.dragging = {
        instanceId,
        offsetY: event.clientY - target.getBoundingClientRect().top,
        rectTop: rect.top
      };

      target.classList.add('dragging');
      target.setPointerCapture(event.pointerId);

      const move = (moveEvent) => {
        if (!state.dragging) return;
        const rawY = moveEvent.clientY - state.dragging.rectTop - state.dragging.offsetY;
        item.uStart = yToRackUnit(rawY, item.he);
        target.style.top = getItemTop(item) + 'px';
        renderComments();
      };

      const end = () => {
        target.classList.remove('dragging');
        target.removeEventListener('pointermove', move);
        target.removeEventListener('pointerup', end);
        target.removeEventListener('pointercancel', end);
        state.dragging = null;
        renderItems();
        saveState();
      };

      target.addEventListener('pointermove', move);
      target.addEventListener('pointerup', end);
      target.addEventListener('pointercancel', end);
    }

    function yToRackUnit(topY, he) {
      const clampedTop = Math.max(0, Math.min(topY, (state.rackUnits - he) * U_HEIGHT));
      const topSlots = Math.round(clampedTop / U_HEIGHT);
      return state.rackUnits - he - topSlots + 1;
    }

    function absoluteUrl(url) {
      try {
        return new URL(url, window.location.href).href;
      } catch (error) {
        return url;
      }
    }

    function formatDisplayDate(isoDate) {
      if (!isoDate) return todayIso;
      const [y, m, d] = String(isoDate).split('-').map(Number);
      if (!y || !m || !d) return isoDate;
      return `${d}-${m}-${y}`;
    }

    function getPageTitleForFace(face) {
      const base = state.rackName.trim() || 'Rack';
      return `${base} ${faceLabel(face)}`;
    }

    function getItemsForFace(face) {
      return state.items
        .map(normalizeItem)
        .filter(Boolean)
        .filter(item => item.face === face)
        .sort((a, b) => getItemTop(a) - getItemTop(b));
    }

    function wrapText(text, maxChars = 28) {
      const words = String(text || '').trim().split(/\s+/).filter(Boolean);
      if (!words.length) return [];
      const lines = [];
      let current = '';
      words.forEach(word => {
        const candidate = current ? `${current} ${word}` : word;
        if (candidate.length <= maxChars) {
          current = candidate;
        } else {
          if (current) lines.push(current);
          if (word.length > maxChars) {
            let rest = word;
            while (rest.length > maxChars) {
              lines.push(rest.slice(0, maxChars - 1) + '…');
              rest = rest.slice(maxChars - 1);
            }
            current = rest;
          } else {
            current = word;
          }
        }
      });
      if (current) lines.push(current);
      return lines;
    }

    function buildSvgTextBlock(lines, x, startY, lineHeight, fontSize, fill, fontWeight = null, textAnchor = null) {
      const safeLines = Array.isArray(lines) ? lines.filter(Boolean) : [];
      if (!safeLines.length) return '';
      return safeLines.map((line, index) => {
        const weightAttr = fontWeight ? ` font-weight="${fontWeight}"` : '';
        const anchorAttr = textAnchor ? ` text-anchor="${textAnchor}"` : '';
        return `<text x="${x}" y="${startY + index * lineHeight}"${anchorAttr} font-family="Arial, sans-serif" font-size="${fontSize}"${weightAttr} fill="${fill}">${escapeXml(line)}</text>`;
      }).join('');
    }

    function buildPageSvg(face) {
      const items = getItemsForFace(face);
      const PAGE_W = 794;
      const PAGE_H = 1123;
      const headerH = 68;
      const contentTop = 88;
      const contentBottom = PAGE_H - 42;
      const contentH = contentBottom - contentTop;
      const labelW = 26;
      const rackInnerW = 304;
      const frameSide = 18;
      const railW = 10;
      const shellW = rackInnerW + frameSide * 2;
      const rackTotalW = labelW + shellW + labelW;
      const baseRackH = state.rackUnits * U_HEIGHT;
      const rackAreaW = 360;
      const scale = Math.min(rackAreaW / rackTotalW, contentH / baseRackH);
      const rackScaledW = rackTotalW * scale;
      const rackScaledH = baseRackH * scale;
      const rackX = 38;
      const rackY = contentTop + Math.max(0, (contentH - rackScaledH) / 2);
      const shellX = rackX + labelW * scale;
      const innerX = shellX + frameSide * scale;
      const commentX = 450;
      const commentW = 292;
      const footerText = `${state.versionLabel || 'V1'} – ${formatDisplayDate(state.issueDate || todayIso)}`;
      const template = getTemplateBySlug(state.template);
      const templateIsPlain = state.template === 'plain-v1';
      const metaLine = [state.location.trim(), state.project.trim()].filter(Boolean).join(' · ');
      const templateLogoSource = getTemplateLogoSource(template);

      let svg = '';
      svg += `<?xml version="1.0" encoding="UTF-8"?>`;
      svg += `<svg xmlns="http://www.w3.org/2000/svg" width="${PAGE_W}" height="${PAGE_H}" viewBox="0 0 ${PAGE_W} ${PAGE_H}">`;
      svg += `<rect width="${PAGE_W}" height="${PAGE_H}" fill="#ffffff"/>`;
      svg += `<rect x="0" y="0" width="${PAGE_W}" height="${headerH}" fill="${templateIsPlain ? '#e2e8f0' : '#edf1f4'}"/>`;

      if (templateLogoSource) {
        svg += `<rect x="20" y="8" rx="12" ry="12" width="124" height="52" fill="#ffffff" stroke="rgba(15,23,42,0.08)"/>`;
        svg += `<image href="${escapeXml(templateLogoSource)}" x="28" y="14" width="108" height="40" preserveAspectRatio="xMidYMid meet"/>`;
      } else if (templateIsPlain) {
        svg += `<rect x="26" y="14" rx="10" ry="10" width="52" height="40" fill="#334155"/>`;
        svg += `<text x="52" y="40" text-anchor="middle" font-family="Arial, sans-serif" font-size="18" font-weight="700" fill="#ffffff">RP</text>`;
      } else {
        svg += `<circle cx="52" cy="34" r="20" fill="#ff6a00"/>`;
        svg += `<text x="52" y="30" text-anchor="middle" font-family="Arial, sans-serif" font-size="10" font-weight="700" fill="#ffffff">cool</text>`;
        svg += `<text x="52" y="42" text-anchor="middle" font-family="Arial, sans-serif" font-size="10" font-weight="700" fill="#ffffff">blue</text>`;
      }

      svg += `<text x="${templateLogoSource ? 160 : 92}" y="42" font-family="Arial, sans-serif" font-size="28" font-weight="700" fill="#0f172a">${escapeXml(template.documentTitle || state.docTitle || 'Rack Design')}</text>`;
      svg += `<text x="${PAGE_W - 38}" y="42" text-anchor="end" font-family="Arial, sans-serif" font-size="18" font-weight="700" fill="#0f172a">${escapeXml(getPageTitleForFace(face))}</text>`;

      svg += `<g transform="translate(${rackX}, ${rackY}) scale(${scale})">`;
      svg += `<rect x="${labelW}" y="0" width="${shellW}" height="${baseRackH}" rx="18" ry="18" fill="#2b3140"/>`;
      svg += `<rect x="${labelW + 8}" y="8" width="${shellW - 16}" height="${baseRackH - 16}" rx="12" ry="12" fill="#1b202a" stroke="#4b5563" stroke-width="1"/>`;
      svg += `<rect x="${labelW + frameSide}" y="0" width="${rackInnerW}" height="${baseRackH}" fill="#1d2430"/>`;
      svg += `<rect x="${labelW + frameSide - railW/2}" y="0" width="${railW}" height="${baseRackH}" fill="#8b95a7" opacity="0.9"/>`;
      svg += `<rect x="${labelW + frameSide + rackInnerW - railW/2}" y="0" width="${railW}" height="${baseRackH}" fill="#8b95a7" opacity="0.9"/>`;

      for (let u = 1; u <= state.rackUnits; u += 1) {
        const y = baseRackH - u * U_HEIGHT;
        const labelY = y + Math.round(U_HEIGHT * 0.50) + 1;
        svg += `<line x1="${labelW + frameSide}" y1="${y}" x2="${labelW + frameSide + rackInnerW}" y2="${y}" stroke="#3f4756" stroke-width="1"/>`;
        svg += `<text x="${labelW - 6}" y="${labelY}" text-anchor="end" font-family="Arial, sans-serif" font-size="11" fill="#475569">${u}</text>`;
        svg += `<text x="${labelW + shellW + 6}" y="${labelY}" font-family="Arial, sans-serif" font-size="11" fill="#475569">${u}</text>`;
      }
      svg += `<line x1="${labelW + frameSide}" y1="${baseRackH}" x2="${labelW + frameSide + rackInnerW}" y2="${baseRackH}" stroke="#3f4756" stroke-width="1"/>`;

      items.forEach(item => {
        const itemTop = getItemTop(item);
        const itemX = labelW + frameSide + ((rackInnerW - (rackInnerW * item.widthPct / 100)) / 2);
        const itemW = rackInnerW * item.widthPct / 100;
        const itemH = item.he * U_HEIGHT;
        const exportHref = item.svgDataUri || absoluteUrl(item.svgUrl);
        svg += `<image href="${escapeXml(exportHref)}" x="${itemX}" y="${itemTop}" width="${itemW}" height="${itemH}" preserveAspectRatio="xMidYMid meet"/>`;
      });

      if (!items.length) {
        const emptyRackLines = ['Geen items geplaatst', `${faceLabel(face)} view`];
        const emptyStartY = (baseRackH / 2) - 6;
        svg += buildSvgTextBlock(emptyRackLines, labelW + shellW / 2, emptyStartY, 20, 14, '#94a3b8', '700', 'middle');
      }
      svg += `</g>`;

      const commentBottomLimit = contentBottom - 18;
      const commentGap = 10;
      const commentColumnGap = 14;
      const commentColumnW = Math.floor((commentW - commentColumnGap) / 2);
      const commentColumnXs = [commentX, commentX + commentColumnW + commentColumnGap];
      const printCommentLayouts = calculateTwoColumnCommentLayouts(
        items.map(item => {
          const itemTopScaled = rackY + (getItemTop(item) * scale);
          const itemHeightScaled = item.he * U_HEIGHT * scale;
          const comment = normalizeComment(item.comments).trim();
          const boxH = Math.max(20, Math.round(itemHeightScaled));
          const availableLines = boxH >= 64 ? 3 : boxH >= 42 ? 2 : 1;
          const displayText = comment ? `${item.name} - ${comment}` : (item.name || 'Geen comment');
          const approxChars = boxH >= 64 ? 18 : boxH >= 42 ? 16 : 14;
          const textLines = wrapText(displayText, approxChars).slice(0, availableLines);
          return {
            item,
            textLines,
            desiredY: itemTopScaled,
            anchorY: itemTopScaled + (itemHeightScaled / 2),
            boxH
          };
        }),
        contentTop,
        commentBottomLimit,
        commentGap
      );

      printCommentLayouts.forEach(({ item, textLines, boxY, boxH, anchorY, columnIndex = 0 }) => {
        const rackEdgeX = innerX + (rackInnerW * scale);
        const itemCenterY = rackY + (getItemTop(item) * scale) + ((item.he * U_HEIGHT * scale) / 2);
        const textLineHeight = boxH >= 56 ? 14 : 12;
        const textFontSize = boxH >= 56 ? 12 : 11;
        const blockHeight = Math.max(1, textLines.length) * textLineHeight;
        const textStartY = boxY + Math.max(textLineHeight, ((boxH - blockHeight) / 2) + textFontSize - 1);
        const connectorInset = Math.max(8, Math.min(18, boxH / 2));
        const connectorY = Math.max(boxY + connectorInset, Math.min(boxY + boxH - connectorInset, anchorY));
        const currentCommentX = commentColumnXs[Math.min(1, Math.max(0, columnIndex))];

        svg += `<line x1="${rackEdgeX + 4}" y1="${itemCenterY}" x2="${currentCommentX - 10}" y2="${connectorY}" stroke="#cbd5e1" stroke-width="1.5"/>`;
        svg += `<circle cx="${rackEdgeX + 4}" cy="${itemCenterY}" r="3.5" fill="#94a3b8"/>`;
        svg += `<rect x="${currentCommentX}" y="${boxY}" width="${commentColumnW}" height="${boxH}" rx="10" ry="10" fill="#ffffff" stroke="#d6dbe7"/>`;
        svg += buildSvgTextBlock(textLines, currentCommentX + 10, textStartY, textLineHeight, textFontSize, '#0f172a', '700');
      });

      if (!items.length) {
        const emptyLines = wrapText('Nog geen comments op deze zijde.', 34).slice(0, 2);
        const emptyBoxH = 56;
        const emptyBoxY = Math.max(contentTop, Math.min(rackY + 20, commentBottomLimit - emptyBoxH));
        const emptyBlockH = Math.max(1, emptyLines.length) * 14;
        const emptyStartY = emptyBoxY + ((emptyBoxH - emptyBlockH) / 2) + 11;
        svg += `<rect x="${commentX}" y="${emptyBoxY}" width="${commentW}" height="${emptyBoxH}" rx="10" ry="10" fill="#ffffff" stroke="#d6dbe7"/>`;
        svg += buildSvgTextBlock(emptyLines, commentX + 14, emptyStartY, 14, 11, '#64748b', '700');
      }

      svg += `<text x="${PAGE_W - 38}" y="${PAGE_H - 18}" text-anchor="end" font-family="Arial, sans-serif" font-size="13" fill="#475569">${escapeXml(footerText)}</text>`;
      svg += `</svg>`;
      return svg;
    }

    function renderPrintPages() {
      const faces = ['front', 'back'];
      printRoot.innerHTML = faces.map(face => {
        const svg = buildPageSvg(face);
        const uri = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
        return `<section class="print-page"><img src="${uri}" alt="${escapeHtml(getPageTitleForFace(face))}"></section>`;
      }).join('');
    }

    function serializeRackPayload(forceNew = false) {
      syncTemplateTitle();
      return {
        rackId: forceNew ? null : state.rackId,
        template: state.template,
        rackName: state.rackName.trim(),
        location: state.location.trim(),
        project: state.project.trim(),
        versionLabel: state.versionLabel.trim(),
        issueDate: state.issueDate || todayIso,
        rackUnits: state.rackUnits,
        items: state.items.map(({ svgDataUri, ...item }) => item)
      };
    }

    async function saveCurrentRack(forceNew = false) {
      state.rackName = rackNameInput.value.trim();
      state.location = locationInput.value;
      state.project = projectInput.value;
      state.versionLabel = versionLabelInput.value.trim();
      state.issueDate = issueDateInput.value || todayIso;
      state.template = templateSelect.value;
      syncTemplateTitle();

      if (!state.rackName) {
        alert('Racknaam is verplicht.');
        rackNameInput.focus();
        return;
      }
      if (!state.versionLabel) {
        alert('Versienummer is verplicht.');
        versionLabelInput.focus();
        return;
      }

      const triggerButton = saveRackButton;
      saveRackButton.disabled = true;
      setActionButtonLabel(triggerButton, (Number.isInteger(state.rackId) && state.rackId > 0) ? 'Rack opslaan...' : 'Nieuw rack opslaan...');

      try {
        const response = await fetch('save_rack.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify(serializeRackPayload(false))
        });

        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || !payload.ok) {
          throw new Error((payload && payload.message) ? payload.message : 'Rack opslaan mislukt.');
        }

        if (payload.rackId) {
          state.rackId = payload.rackId;
        }
        refreshSaveButton();
        saveState();
        window.location.href = payload.redirectUrl || `index.php?rack=${encodeURIComponent(state.rackId)}&status=rack-saved`;
      } catch (error) {
        alert(error.message || 'Rack opslaan mislukt.');
      } finally {
        saveRackButton.disabled = false;
        refreshSaveButton();
      }
    }

    rackUnitsInput.addEventListener('change', () => setRackUnits(rackUnitsInput.value));

    templateSelect.addEventListener('change', () => {
      state.template = templateSelect.value;
      syncTemplateTitle();
      renderMetadata();
      saveState();
    });

    rackNameInput.addEventListener('input', () => {
      state.rackName = rackNameInput.value;
      renderMetadata();
      saveState();
    });

    locationInput.addEventListener('input', () => {
      state.location = locationInput.value;
      renderMetadata();
      saveState();
    });

    projectInput.addEventListener('input', () => {
      state.project = projectInput.value;
      renderMetadata();
      saveState();
    });

    versionLabelInput.addEventListener('input', () => {
      state.versionLabel = versionLabelInput.value;
      renderMetadata();
      saveState();
    });

    issueDateInput.addEventListener('change', () => {
      state.issueDate = issueDateInput.value || todayIso;
      renderMetadata();
      saveState();
    });

    faceToggle.addEventListener('click', (event) => {
      const button = event.target.closest('button[data-face]');
      if (!button) return;
      state.currentFace = button.dataset.face === 'back' ? 'back' : 'front';
      renderLibrary();
      renderItems();
      saveState();
    });

    clearFaceButton.addEventListener('click', () => {
      state.items = state.items.filter(item => item.face !== state.currentFace);
      renderItems();
      saveState();
    });

    saveRackButton.addEventListener('click', () => saveCurrentRack(false));

    printButton.addEventListener('click', () => {
      renderPrintPages();
      window.setTimeout(() => window.print(), 80);
    });

    function syncFloatingNewRackPosition() {
      const root = document.documentElement;
      if (!root || !leftSidebar) return;
      const leftWidth = leftSidebar.classList.contains('collapsed')
        ? 'calc(var(--sidebar-left-collapsed-width) + var(--fab-gap))'
        : 'calc(var(--sidebar-left-width) + var(--fab-gap))';
      root.style.setProperty('--fab-left', leftWidth);
    }

    function applySidebarState(sidebar, storageKey, collapsed) {
      if (!sidebar) return;
      sidebar.classList.toggle('collapsed', collapsed);
      try {
        localStorage.setItem(storageKey, collapsed ? '1' : '0');
      } catch (error) {}
      if (sidebar === leftSidebar && toggleLeftSidebar) {
        toggleLeftSidebar.textContent = collapsed ? '❯' : '❮';
        syncFloatingNewRackPosition();
      }
      if (sidebar === rightSidebar && toggleRightSidebar) {
        toggleRightSidebar.textContent = collapsed ? '❮' : '❯';
      }
    }

    function restoreSidebarState(sidebar, storageKey, fallbackCollapsed = false) {
      let collapsed = fallbackCollapsed;
      try {
        const stored = localStorage.getItem(storageKey);
        if (stored === '1' || stored === '0') collapsed = stored === '1';
      } catch (error) {}
      applySidebarState(sidebar, storageKey, collapsed);
    }

    function openLibraryPanel() {
      if (libraryModal) libraryModal.classList.add('open');
    }

    function closeLibraryPanel() {
      if (libraryModal) libraryModal.classList.remove('open');
    }

    function openUploadPanel() {
      closeLibraryPanel();
      if (uploadDrawer) uploadDrawer.classList.add('open');
    }

    function closeUploadPanel() {
      if (uploadDrawer) uploadDrawer.classList.remove('open');
    }

    if (toggleLeftSidebar) {
      toggleLeftSidebar.addEventListener('click', () => applySidebarState(leftSidebar, 'rackPlannerUi.leftSidebarCollapsed', !leftSidebar.classList.contains('collapsed')));
    }

    if (toggleRightSidebar) {
      toggleRightSidebar.addEventListener('click', () => applySidebarState(rightSidebar, 'rackPlannerUi.rightSidebarCollapsed', !rightSidebar.classList.contains('collapsed')));
    }

    if (openLibraryModal) {
      openLibraryModal.addEventListener('click', openLibraryPanel);
    }

    if (openUploadDrawerFromModal) {
      openUploadDrawerFromModal.addEventListener('click', openUploadPanel);
    }

    if (libraryTabs) {
      libraryTabs.addEventListener('click', (event) => {
        const button = event.target.closest('[data-library-category]');
        if (!button) return;
        currentLibraryCategory = button.dataset.libraryCategory === 'devices' ? 'devices' : 'fixed';
        renderLibrary();
      });
    }

    if (libraryManagerTabs) {
      libraryManagerTabs.addEventListener('click', (event) => {
        const button = event.target.closest('[data-library-manager-category]');
        if (!button) return;
        currentLibraryManagerCategory = button.dataset.libraryManagerCategory || 'all';
        renderLibrary();
      });
    }

    if (libraryModal) {
      libraryModal.addEventListener('click', (event) => {
        if (event.target.closest('[data-close-library="1"]')) {
          closeLibraryPanel();
        }
      });
    }

    if (uploadDrawer) {
      uploadDrawer.addEventListener('click', (event) => {
        if (event.target.closest('[data-close-upload="1"]')) {
          closeUploadPanel();
        }
      });
    }

    window.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') return;
      closeUploadPanel();
      closeLibraryPanel();
    });

    restoreSidebarState(leftSidebar, 'rackPlannerUi.leftSidebarCollapsed', false);
    restoreSidebarState(rightSidebar, 'rackPlannerUi.rightSidebarCollapsed', false);
    syncFloatingNewRackPosition();

    loadState();
    reconcileRackItemsWithLibrary();
    refreshSaveButton();
    renderLibrary();
    renderLabels();
    renderItems();
    renderMetadata();
    setRackUnits(state.rackUnits);
    renderPrintPages();
  </script>
</body>
</html>
