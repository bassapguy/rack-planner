<?php
require_once __DIR__ . '/../../bootstrap.php';
toolboxRequireRole(['super_admin', 'admin']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rack_repository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: saved_templates.php');
    exit;
}

function templateSaveRedirect(string $status, string $message = '', ?int $templateId = null): void
{
    $target = 'saved_templates.php?status=' . rawurlencode($status);
    if ($templateId !== null) {
        $target = 'template_editor.php?id=' . $templateId . '&status=' . rawurlencode($status);
    }
    if ($message !== '') {
        $target .= (strpos($target, '?') === false ? '?' : '&') . 'message=' . rawurlencode($message);
    }
    header('Location: ' . $target);
    exit;
}

$templateId = isset($_POST['template_id']) ? (int)$_POST['template_id'] : 0;
$name = trim((string)($_POST['name'] ?? ''));
$slug = trim((string)($_POST['slug'] ?? ''));
$documentTitle = trim((string)($_POST['document_title'] ?? 'Rack Design')) ?: 'Rack Design';
$isDefault = !empty($_POST['is_default']);
$fields = is_array($_POST['fields'] ?? null) ? $_POST['fields'] : [];

$logoPath = '';
try {
    $pdo = db();
    if ($templateId > 0) {
        $existing = rackPlannerLoadTemplateDetails($pdo, $templateId);
        if ($existing) {
            $logoPath = (string)($existing['logoPath'] ?? '');
        }
    }

    if (isset($_FILES['logo_file']) && is_array($_FILES['logo_file']) && (int)($_FILES['logo_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $error = (int)($_FILES['logo_file']['error'] ?? UPLOAD_ERR_OK);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Logo upload is mislukt.');
        }
        $tmpName = (string)($_FILES['logo_file']['tmp_name'] ?? '');
        $originalName = (string)($_FILES['logo_file']['name'] ?? 'logo');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
        if (!in_array($extension, $allowed, true)) {
            throw new RuntimeException('Alleen PNG, JPG, JPEG, SVG of WEBP is toegestaan voor logo uploads.');
        }
        $logoDir = __DIR__ . '/uploads/logos';
        if (!is_dir($logoDir) && !mkdir($logoDir, 0777, true) && !is_dir($logoDir)) {
            throw new RuntimeException('Kon de logo map niet aanmaken.');
        }
        $safeBase = rackPlannerSlugify($name !== '' ? $name : 'template-logo');
        $filename = $safeBase . '-' . date('YmdHis') . '.' . $extension;
        $targetPath = $logoDir . '/' . $filename;
        if (!move_uploaded_file($tmpName, $targetPath)) {
            throw new RuntimeException('Kon het geüploade logo niet opslaan.');
        }
        $logoPath = 'uploads/logos/' . $filename;
    }

    $newTemplateId = rackPlannerSaveTemplate($pdo, [
        'id' => $templateId > 0 ? $templateId : null,
        'name' => $name,
        'slug' => $slug,
        'document_title' => $documentTitle,
        'logo_path' => $logoPath,
        'is_default' => $isDefault,
        'fields' => $fields,
    ]);

    header('Location: template_editor.php?id=' . $newTemplateId . '&status=saved');
    exit;
} catch (Throwable $e) {
    templateSaveRedirect('error', $e->getMessage(), $templateId > 0 ? $templateId : null);
}
