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
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Rack Planner Editor</title>
  <link rel="stylesheet" href="../../assets/css/base.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/base.css') ?: time(); ?>">
  <link rel="stylesheet" href="../../assets/css/buttons.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/buttons.css') ?: time(); ?>">
  <link rel="stylesheet" href="../../assets/css/forms.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/forms.css') ?: time(); ?>">
  <link rel="stylesheet" href="../../assets/css/tables.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/tables.css') ?: time(); ?>">
  <link rel="stylesheet" href="assets/css/rack-planner.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/rack-planner.css') ?: time(); ?>">
</head>
<body>
  <a class="fab-new-rack" href="editor.php?new=1" title="New rack">
    <span class="icon" aria-hidden="true">＋</span>
    <span class="label">New rack</span>
  </a>

  <div class="floating-actions" aria-label="Quick actions">
    <a class="floating-action secondary" href="../../index.php" title="Home">
      <span class="icon" aria-hidden="true">🏠</span>
      <span class="button-label">Home</span>
    </a>
    <button id="saveRackButton" class="floating-action primary" type="button" title="Save rack">
      <span class="icon" aria-hidden="true">💾</span>
      <span class="button-label">Save new rack</span>
    </button>
    <button id="printPage" class="floating-action primary" type="button" title="Export Front and Back to print or PDF">
      <span class="icon" aria-hidden="true">🖨</span>
      <span class="button-label">Print / PDF</span>
    </button>
    <button id="clearFace" class="floating-action secondary" type="button" title="Clear only the current side">
      <span class="icon" aria-hidden="true">🧹</span>
      <span class="button-label">Clear side</span>
    </button>
  </div>

  <div class="app shell-layout">
    <aside class="sidebar sidebar-left" id="leftSidebar">
      <div class="sidebar-top">
        <div class="sidebar-brand">
          <div class="sidebar-title-text">Rack Planner</div>
          
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
          <div class="flash success">Rack saved to the database.</div>
        <?php elseif (isset($_GET['status']) && $_GET['status'] === 'saved'): ?>
          <div class="flash success">SVG saved to the library.</div>
        <?php elseif (isset($_GET['status']) && $_GET['status'] === 'updated'): ?>
          <div class="flash success">Library item updated.</div>
        <?php elseif (isset($_GET['status']) && $_GET['status'] === 'deleted'): ?>
          <div class="flash success">Library item deleted.</div>
        <?php elseif (isset($_GET['status']) && $_GET['status'] === 'rack-deleted'): ?>
          <div class="flash success">Rack deleted.</div>
        <?php elseif (isset($_GET['status']) && $_GET['status'] === 'rack-duplicated'): ?>
          <div class="flash success">Rack gedupliceerd.</div>
        <?php elseif (isset($_GET['status']) && $_GET['status'] === 'error'): ?>
          <div class="flash error"><?php echo htmlspecialchars($_GET['message'] ?? 'Save failed.', ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <section class="panel">
          <div class="panel-head panel-head-with-action">
            <div>
              <h3>Saved racks</h3>
              
            </div>
            <a class="button secondary" href="saved_racks.php">View all</a>
          </div>
          <div class="panel-body stack">
            <?php if ($rackList !== []): ?>
              <?php foreach (array_slice($rackList, 0, 2) as $rackEntry): ?>
                <a class="summary-box saved-rack-link" href="editor.php?rack=<?php echo (int)$rackEntry['id']; ?>">
                  <strong><?php echo htmlspecialchars($rackEntry['rackName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                  <span><?php echo htmlspecialchars($rackEntry['versionLabel'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($rackEntry['issueDate'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo (int)$rackEntry['rackUnits']; ?> U</span>
                  <?php if (($rackEntry['location'] ?? '') !== '' || ($rackEntry['project'] ?? '') !== ''): ?>
                    <span><?php echo htmlspecialchars(trim(($rackEntry['location'] ?? '') . ' · ' . ($rackEntry['project'] ?? ''), ' ·'), ENT_QUOTES, 'UTF-8'); ?></span>
                  <?php endif; ?>
                </a>
              <?php endforeach; ?>
              <?php if (count($rackList) > 2): ?>
                <div class="summary-box">
                  <strong>Nog <?php echo count($rackList) - 2; ?> racks</strong>
                </div>
              <?php endif; ?>
            <?php else: ?>
              <div class="summary-box">
                <strong>No saved racks yet</strong>
                
              </div>
            <?php endif; ?>
          </div>
        </section>


        <section class="panel">
          <div class="panel-head panel-head-with-action">
            <div>
              <h3>Templates</h3>
              <p>Manage branding, fields en duplicaten op een aparte pagina.</p>
            </div>
            <a class="button secondary" href="saved_templates.php">View all</a>
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
                <strong>No templates yet</strong>
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
            <button class="button secondary small" type="button" id="openLibraryModal">Manage</button>
          </div>
          <div class="panel-body">
            <div class="library-tabs" id="libraryTabs">
              <button class="library-tab-button active" type="button" data-library-category="fixed">Fixed cabinet content</button>
              <button class="library-tab-button" type="button" data-library-category="devices">Devices</button>
            </div>
            <div id="libraryGrid" class="library-grid"></div>
            <div id="emptyLibrary" class="empty-library" style="display:none;">No items in this category yet.</div>
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
            <p>Work in the active view. The floating toolbar stays available for save, print/PDF and clearing the active side.</p>
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
                          <div class="rack-empty" id="rackEmpty">No items in this view yet.<br>Place an item on the active side from the left.</div>
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
          <div class="sidebar-subtitle">Metadata, template and active view</div>
        </div>
        <div class="sidebar-actions">
          <button class="icon-button" type="button" id="toggleRightSidebar" title="Rechter sidebar inklappen">❯</button>
        </div>
      </div>
      <div class="sidebar-content">
        <section class="panel">
          <div class="panel-head">
            <h2>Document settings</h2>
            <p>Hier vul je de gegevens in voor de editor en de A4-export.</p>
          </div>
          <div class="panel-body stack">
            <div class="row g-3 control-grid compact">
              <div class="control-field col-12">
                <label for="templateSelect">Template</label>
                <select id="templateSelect">
                  <?php foreach ($templates as $template): ?>
                    <option value="<?php echo htmlspecialchars($template['slug'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($template['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                  <?php endforeach; ?>
                </select>
                <a class="button secondary" href="saved_templates.php" style="margin-top:10px; width:100%;">Manage templates</a>
              </div>
              <div class="control-field col-12">
                <label for="rackUnits">Rackhoogte (U)</label>
                <input id="rackUnits" type="number" min="1" max="60" step="1" value="24">
              </div>
              <div class="control-field col-12">
                <label for="versionLabel">Version</label>
                <input id="versionLabel" type="text" value="V1" required>
              </div>
              <div class="control-field col-12">
                <label for="issueDate">Date</label>
                <input id="issueDate" type="date">
              </div>
            </div>

            <div class="row g-3 control-grid">
              <div class="control-field col-12">
                <label for="rackName">Racknaam</label>
                <input id="rackName" type="text" value="MER - Rack 1" required>
              </div>
              <div class="control-field col-12">
                <label for="locationInput">Location</label>
                <input id="locationInput" type="text" value="" placeholder="Bijv. MER of winkelnaam">
              </div>
              <div class="control-field col-12">
                <label for="projectInput">Project</label>
                <input id="projectInput" type="text" value="" placeholder="e.g. Delft opening">
              </div>
              <div class="control-field col-12">
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
            <h2>Manage library</h2>
            <p>View your full library, add new SVG items and manage existing parts in detail.</p>
          </div>
          <div class="library-modal-actions">
            <button class="button primary" type="button" id="openUploadDrawerFromModal">New SVG</button>
            <button class="icon-button" type="button" data-close-library="1" title="Close">×</button>
          </div>
        </div>
      </div>
      <div class="panel-body">
        <div class="library-manager-tabs" id="libraryManagerTabs">
          <button class="library-manager-tab-button active" type="button" data-library-manager-category="all">All</button>
          <button class="library-manager-tab-button" type="button" data-library-manager-category="fixed">Fixed cabinet content</button>
          <button class="library-manager-tab-button" type="button" data-library-manager-category="devices">Devices</button>
          <button class="library-manager-tab-button" type="button" data-library-manager-category="uncategorized">Uncategorized</button>
        </div>
        <div id="libraryManagerGrid" class="library-manager-grid"></div>
        <div id="emptyLibraryManager" class="empty-library" style="display:none;">There are no items in this selection.</div>
      </div>
    </div>
  </div>

  <div class="upload-drawer<?php echo $isEditMode ? ' open' : ''; ?>" id="uploadDrawer">
    <div class="upload-backdrop" data-close-upload="1"></div>
    <div class="upload-panel panel">
      <div class="panel-head">
        <div class="upload-head-row">
          <div>
            <h2><?php echo $isEditMode ? 'Edit library item' : 'New SVG toevoegen'; ?></h2>
            <p><?php echo $isEditMode ? 'Pas metadata aan en vervang optioneel de SVG.' : 'Upload een custom SVG en koppel meteen metadata voor rackgebruik.'; ?></p>
          </div>
          <button class="icon-button" type="button" data-close-upload="1" title="Close">×</button>
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
            <div class="help">De app voegt automatisch de U toe aan de naam, bijvoorbeeld: Legplank 2U.</div>
          </div>

          <div class="field">
            <label for="category">Categorie</label>
            <select id="category" name="category">
              <option value="fixed" <?php echo (($editItem['category'] ?? 'uncategorized') === 'fixed') ? 'selected' : ''; ?>>Fixed cabinet content</option>
              <option value="devices" <?php echo (($editItem['category'] ?? 'uncategorized') === 'devices') ? 'selected' : ''; ?>>Devices</option>
              <option value="uncategorized" <?php echo (($editItem['category'] ?? 'uncategorized') === 'uncategorized') ? 'selected' : ''; ?>>Uncategorized</option>
            </select>
            <div class="help">Gebruik Fixed cabinet content voor vaste kastcomponenten en Devices voor actieve apparatuur.</div>
          </div>

          <div class="field">
            <label for="he">Hoogte (U)</label>
            <input id="he" name="he" type="number" min="1" max="20" step="1" value="<?php echo htmlspecialchars((string)($editItem['he'] ?? 1)); ?>" required>
          </div>

          <div class="field">
            <label for="widthPct">Width (% of 19-inch front)</label>
            <input id="widthPct" name="widthPct" type="number" min="10" max="100" step="1" value="<?php echo htmlspecialchars((string)($editItem['widthPct'] ?? 100)); ?>" required>
            <div class="help">Gebruik bijvoorbeeld 100 voor volle 19-inch width of 50 voor half-width.</div>
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
            <div class="help"><?php echo $isEditMode ? 'Leave empty to keep the current SVG.' : 'SVG only. The app checks whether the file really contains SVG content.'; ?></div>
          </div>

          <div class="toolbar-row" style="justify-content:flex-start;">
            <button class="button primary" type="submit"><?php echo $isEditMode ? 'Save changes' : 'Save to library'; ?></button>
            <button class="button secondary" type="button" data-close-upload="1">Close</button>
            <?php if ($isEditMode): ?>
              <a class="button secondary" href="editor.php">Cancel</a>
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

    const U_UIGHT = 28;
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
      if (category === 'fixed') return 'Fixed cabinet content';
      if (category === 'devices') return 'Devices';
      return 'Uncategorized';
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
      setActionButtonLabel(saveRackButton, isExistingRack ? 'Save rack' : 'Save new rack');
      saveRackButton.title = isExistingRack ? 'Update the current saved rack' : 'Save this rack as a new record';
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
        console.warn('Could not load saved rack state', error);
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
      const totalHeight = state.rackUnits * U_UIGHT;
      leftLabels.style.height = totalHeight + 'px';
      rightLabels.style.height = totalHeight + 'px';

      for (let u = 1; u <= state.rackUnits; u += 1) {
        const top = totalHeight - ((u - 0.5) * U_UIGHT);
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
            <div class="library-meta">${item.he} U · ${item.widthPct}% width</div>
            <div class="library-actions">
              <button class="button secondary small place-button" type="button">${escapeHtml(placeLabel)}</button>
              <a class="button secondary small" href="editor.php?edit=${encodeURIComponent(item.id)}${state.rackId ? `&rack=${encodeURIComponent(state.rackId)}` : ''}">Bewerk</a>
              <form action="delete_item.php" method="post" onsubmit="return confirm('Are you sure you want to delete this library item?');">
                <input type="hidden" name="id" value="${escapeHtml(item.id)}">
                <button class="button danger small" type="submit">Delete</button>
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
      return (state.rackUnits - (item.uStart + item.he - 1)) * U_UIGHT;
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
            return (a.item?.uStart || 0) - (b.item?.uStart || 0);
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

      const rackHeight = state.rackUnits * U_UIGHT;
      commentsLane.style.minHeight = rackHeight + 'px';

      if (!visibleItems.length) {
        commentsLane.innerHTML = '<div class="comments-empty">Plaats een item in deze view om hier per plaatsing een comment toe te voegen.</div>';
        return;
      }

      const commentLayouts = calculateStackedCommentLayouts(
        visibleItems.map(item => ({
          item,
          desiredY: getItemTop(item),
          anchorY: getItemTop(item) + (item.he * U_UIGHT / 2),
          boxH: Math.max(item.he * U_UIGHT, 74)
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
            <span>${faceLabel(item.face)} · ${item.he} U · U${item.uStart}</span>
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
        el.style.height = (item.he * U_UIGHT) + 'px';
        el.style.top = getItemTop(item) + 'px';
        el.innerHTML = `
          <img src="${escapeHtml(item.svgUrl)}" alt="${escapeHtml(item.name)}">
          <div class="badge">${escapeHtml(item.name)} · ${item.he} U</div>
          <button class="remove" type="button" aria-label="Deleteen">×</button>
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
      const clampedTop = Math.max(0, Math.min(topY, (state.rackUnits - he) * U_UIGHT));
      const topSlots = Math.round(clampedTop / U_UIGHT);
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


    function truncateWithEllipsis(value, maxChars) {
      const text = String(value || '').trim();
      if (!text) return '';
      if (text.length <= maxChars) return text;
      if (maxChars <= 1) return text.slice(0, maxChars);
      return text.slice(0, Math.max(1, maxChars - 1)).trimEnd() + '…';
    }

    function buildCompactCommentLine(name, comment, maxChars) {
      const safeName = String(name || '').trim();
      const safeComment = String(comment || '').trim();
      if (!safeComment) return truncateWithEllipsis(safeName || 'No comment', maxChars);
      if (!safeName) return truncateWithEllipsis(safeComment, maxChars);
      if (`${safeName} - ${safeComment}`.length <= maxChars) return `${safeName} - ${safeComment}`;

      const separator = ' - ';
      const minName = Math.max(4, Math.floor(maxChars * 0.35));
      const minComment = Math.max(5, maxChars - separator.length - minName);
      const nameChars = Math.max(minName, Math.min(safeName.length, Math.floor(maxChars * 0.45)));
      const commentChars = Math.max(5, maxChars - separator.length - nameChars);
      return `${truncateWithEllipsis(safeName, nameChars)}${separator}${truncateWithEllipsis(safeComment, commentChars)}`;
    }

    function buildPageSvg(face) {
      const items = getItemsForFace(face);
      const PAGE_W = 1587;
      const PAGE_H = 1123;
      const headerH = 78;
      const contentTop = 100;
      const contentBottom = PAGE_H - 42;
      const contentH = contentBottom - contentTop;
      const labelW = 26;
      const rackInnerW = 304;
      const frameSide = 18;
      const railW = 10;
      const shellW = rackInnerW + frameSide * 2;
      const rackTotalW = labelW + shellW + labelW;
      const baseRackH = state.rackUnits * U_UIGHT;
      const rackAreaW = 520;
      const scale = Math.min(rackAreaW / rackTotalW, contentH / baseRackH);
      const rackScaledW = rackTotalW * scale;
      const rackScaledH = baseRackH * scale;
      const rackX = 52;
      const rackY = contentTop + Math.max(0, (contentH - rackScaledH) / 2);
      const shellX = rackX + labelW * scale;
      const innerX = shellX + frameSide * scale;
      const commentX = 660;
      const commentW = PAGE_W - commentX - 52;
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

      svg += `<text x="${templateLogoSource ? 160 : 92}" y="34" font-family="Arial, sans-serif" font-size="28" font-weight="700" fill="#0f172a">${escapeXml(template.documentTitle || state.docTitle || 'Rack Design')}</text>`;
      if (metaLine) {
        svg += `<text x="${templateLogoSource ? 160 : 92}" y="54" font-family="Arial, sans-serif" font-size="14" font-weight="500" fill="#475569">${escapeXml(metaLine)}</text>`;
      }
      svg += `<text x="${PAGE_W - 38}" y="42" text-anchor="end" font-family="Arial, sans-serif" font-size="18" font-weight="700" fill="#0f172a">${escapeXml(getPageTitleForFace(face))}</text>`;

      svg += `<g transform="translate(${rackX}, ${rackY}) scale(${scale})">`;
      svg += `<rect x="${labelW}" y="0" width="${shellW}" height="${baseRackH}" rx="18" ry="18" fill="#2b3140"/>`;
      svg += `<rect x="${labelW + 8}" y="8" width="${shellW - 16}" height="${baseRackH - 16}" rx="12" ry="12" fill="#1b202a" stroke="#4b5563" stroke-width="1"/>`;
      svg += `<rect x="${labelW + frameSide}" y="0" width="${rackInnerW}" height="${baseRackH}" fill="#1d2430"/>`;
      svg += `<rect x="${labelW + frameSide - railW/2}" y="0" width="${railW}" height="${baseRackH}" fill="#8b95a7" opacity="0.9"/>`;
      svg += `<rect x="${labelW + frameSide + rackInnerW - railW/2}" y="0" width="${railW}" height="${baseRackH}" fill="#8b95a7" opacity="0.9"/>`;

      for (let u = 1; u <= state.rackUnits; u += 1) {
        const y = baseRackH - u * U_UIGHT;
        const labelY = y + Math.round(U_UIGHT * 0.50) + 1;
        svg += `<line x1="${labelW + frameSide}" y1="${y}" x2="${labelW + frameSide + rackInnerW}" y2="${y}" stroke="#3f4756" stroke-width="1"/>`;
        svg += `<text x="${labelW - 6}" y="${labelY}" text-anchor="end" font-family="Arial, sans-serif" font-size="11" fill="#475569">${u}</text>`;
        svg += `<text x="${labelW + shellW + 6}" y="${labelY}" font-family="Arial, sans-serif" font-size="11" fill="#475569">${u}</text>`;
      }
      svg += `<line x1="${labelW + frameSide}" y1="${baseRackH}" x2="${labelW + frameSide + rackInnerW}" y2="${baseRackH}" stroke="#3f4756" stroke-width="1"/>`;

      items.forEach(item => {
        const itemTop = getItemTop(item);
        const itemX = labelW + frameSide + ((rackInnerW - (rackInnerW * item.widthPct / 100)) / 2);
        const itemW = rackInnerW * item.widthPct / 100;
        const itemH = item.he * U_UIGHT;
        const exportHref = item.svgDataUri || absoluteUrl(item.svgUrl);
        svg += `<image href="${escapeXml(exportHref)}" x="${itemX}" y="${itemTop}" width="${itemW}" height="${itemH}" preserveAspectRatio="xMidYMid meet"/>`;
      });

      if (!items.length) {
        const emptyRackLines = ['No items placed', `${faceLabel(face)} view`];
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
          const itemHeightScaled = item.he * U_UIGHT * scale;
          const comment = normalizeComment(item.comments).trim();
          const boxH = Math.max(20, Math.round(itemHeightScaled));
          const availableLines = boxH >= 64 ? 3 : boxH >= 42 ? 2 : 1;
          const displayText = comment ? `${item.name} - ${comment}` : (item.name || 'No comment');
          const approxChars = boxH >= 64 ? 18 : boxH >= 42 ? 16 : 18;
          const textLines = availableLines === 1
            ? [buildCompactCommentLine(item.name, comment, approxChars)]
            : wrapText(displayText, approxChars).slice(0, availableLines);
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
        const itemCenterY = rackY + (getItemTop(item) * scale) + ((item.he * U_UIGHT * scale) / 2);
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
        const emptyLines = wrapText('No comments on this side yet.', 34).slice(0, 2);
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
        alert('Versionnummer is verplicht.');
        versionLabelInput.focus();
        return;
      }

      const triggerButton = saveRackButton;
      saveRackButton.disabled = true;
      setActionButtonLabel(triggerButton, (Number.isInteger(state.rackId) && state.rackId > 0) ? 'Save rack...' : 'Save new rack...');

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
          throw new Error((payload && payload.message) ? payload.message : 'Save rack mislukt.');
        }

        if (payload.rackId) {
          state.rackId = payload.rackId;
        }
        refreshSaveButton();
        saveState();
        window.location.href = payload.redirectUrl || `editor.php?rack=${encodeURIComponent(state.rackId)}&status=rack-saved`;
      } catch (error) {
        alert(error.message || 'Save rack mislukt.');
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
