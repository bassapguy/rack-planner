<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rack_repository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: saved_templates.php');
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));
$templateId = isset($_POST['template_id']) ? (int)$_POST['template_id'] : 0;
$returnUrl = trim((string)($_POST['return_url'] ?? 'saved_templates.php'));
if ($returnUrl === '' || preg_match('/^https?:/i', $returnUrl)) {
    $returnUrl = 'saved_templates.php';
}

function templateActionRedirect(string $url, string $status, string $message = ''): void
{
    $separator = strpos($url, '?') === false ? '?' : '&';
    $target = $url . $separator . 'status=' . rawurlencode($status);
    if ($message !== '') {
        $target .= '&message=' . rawurlencode($message);
    }
    header('Location: ' . $target);
    exit;
}

if ($templateId < 1 || !in_array($action, ['delete', 'duplicate'], true)) {
    templateActionRedirect($returnUrl, 'error', 'Ongeldige template-actie.');
}

try {
    $pdo = db();
    if ($action === 'delete') {
        $deleted = rackPlannerDeleteTemplate($pdo, $templateId);
        templateActionRedirect($returnUrl, $deleted ? 'deleted' : 'error', $deleted ? '' : 'Template niet gevonden.');
    }

    $newTemplateId = rackPlannerDuplicateTemplate($pdo, $templateId);
    if ($newTemplateId === null) {
        templateActionRedirect($returnUrl, 'error', 'Template niet gevonden.');
    }

    templateActionRedirect($returnUrl, 'duplicated');
} catch (Throwable $e) {
    templateActionRedirect($returnUrl, 'error', $e->getMessage());
}
