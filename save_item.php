<?php
require_once __DIR__ . '/db.php';

function buildLibraryItemName(string $name, int $he): string
{
    $baseName = trim(preg_replace('/\s+\d+U$/i', '', $name));
    return trim($baseName . ' ' . $he . 'U');
}

function fail(string $message, ?string $editId = null): void
{
    $location = 'index.php?status=error&message=' . urlencode($message);
    if ($editId) {
        $location .= '&edit=' . urlencode($editId);
    }
    header('Location: ' . $location);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Ongeldige request.');
}

$name = trim($_POST['name'] ?? '');
$he = (int)($_POST['he'] ?? 0);
$widthPct = (int)($_POST['widthPct'] ?? 0);
$category = strtolower(trim((string)($_POST['category'] ?? 'uncategorized')));
$existingId = trim($_POST['existing_id'] ?? '');
$existingSvgUrl = trim($_POST['existing_svg_url'] ?? '');
$isEditMode = $existingId !== '';

if ($name === '') {
    fail('Naam is verplicht.', $existingId ?: null);
}
if ($he < 1 || $he > 20) {
    fail('HE moet tussen 1 en 20 liggen.', $existingId ?: null);
}

$normalizedName = buildLibraryItemName($name, $he);
if ($widthPct < 10 || $widthPct > 100) {
    fail('Breedte moet tussen 10 en 100 procent liggen.', $existingId ?: null);
}
if (!in_array($category, ['fixed', 'devices', 'uncategorized'], true)) {
    $category = 'uncategorized';
}

$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';

if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
    fail('Kon uploadmap niet aanmaken.', $existingId ?: null);
}

$svgUrl = $existingSvgUrl;
$hasUploadedFile = isset($_FILES['svg']) && (($_FILES['svg']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

if (!$isEditMode && !$hasUploadedFile) {
    fail('SVG-bestand is verplicht.', null);
}

if ($hasUploadedFile) {
    if (($_FILES['svg']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        fail('SVG upload mislukt.', $existingId ?: null);
    }

    $tmpPath = $_FILES['svg']['tmp_name'];
    $originalName = $_FILES['svg']['name'] ?? 'item.svg';
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension !== 'svg') {
        fail('Alleen SVG-bestanden zijn toegestaan.', $existingId ?: null);
    }

    $content = file_get_contents($tmpPath);
    if ($content === false || stripos($content, '<svg') === false) {
        fail('Bestand lijkt geen geldige SVG te zijn.', $existingId ?: null);
    }

    $safeBase = preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($originalName, PATHINFO_FILENAME));
    $safeBase = trim((string)$safeBase, '-');
    if ($safeBase === '') {
        $safeBase = 'rack-item';
    }

    $fileName = $safeBase . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.svg';
    $targetPath = $uploadDir . '/' . $fileName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        fail('Kon SVG niet opslaan op schijf.', $existingId ?: null);
    }

    $svgUrl = 'uploads/' . $fileName;
}

try {
    $pdo = db();

    if ($isEditMode) {
        $stmt = $pdo->prepare(
            'UPDATE library_items
             SET name = :name,
                 he = :he,
                 width_pct = :width_pct,
                 category = :category,
                 svg_path = :svg_path,
                 is_active = 1,
                 updated_at = CURRENT_TIMESTAMP
             WHERE uuid = :uuid'
        );
        $stmt->execute([
            ':name' => $normalizedName,
            ':he' => $he,
            ':width_pct' => $widthPct,
            ':category' => $category,
            ':svg_path' => $svgUrl,
            ':uuid' => $existingId,
        ]);

        if ($stmt->rowCount() === 0) {
            $checkStmt = $pdo->prepare('SELECT id FROM library_items WHERE uuid = :uuid LIMIT 1');
            $checkStmt->execute([':uuid' => $existingId]);
            if (!$checkStmt->fetchColumn()) {
                fail('Library-item niet gevonden.', null);
            }
        }

        header('Location: index.php?status=updated');
        exit;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO library_items (uuid, name, he, width_pct, category, svg_path, is_active)
         VALUES (:uuid, :name, :he, :width_pct, :category, :svg_path, 1)'
    );
    $stmt->execute([
        ':uuid' => bin2hex(random_bytes(12)),
        ':name' => $normalizedName,
        ':he' => $he,
        ':width_pct' => $widthPct,
        ':category' => $category,
        ':svg_path' => $svgUrl,
    ]);

    header('Location: index.php?status=saved');
    exit;
} catch (Throwable $e) {
    fail('Opslaan mislukt: ' . $e->getMessage(), $existingId ?: null);
}
