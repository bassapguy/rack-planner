<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rack_repository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: saved_racks.php');
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));
$rackId = isset($_POST['rack_id']) ? (int)$_POST['rack_id'] : 0;
$returnUrl = trim((string)($_POST['return_url'] ?? 'saved_racks.php'));
if ($returnUrl === '' || preg_match('/^https?:/i', $returnUrl)) {
    $returnUrl = 'saved_racks.php';
}

function rackActionRedirect(string $url, string $status, string $message = ''): void
{
    $separator = strpos($url, '?') === false ? '?' : '&';
    $target = $url . $separator . 'status=' . rawurlencode($status);
    if ($message !== '') {
        $target .= '&message=' . rawurlencode($message);
    }
    header('Location: ' . $target);
    exit;
}

if ($rackId < 1 || !in_array($action, ['delete', 'duplicate'], true)) {
    rackActionRedirect($returnUrl, 'error', 'Ongeldige rack-actie.');
}

try {
    $pdo = db();
    if ($action === 'delete') {
        $deleted = rackPlannerDeleteRack($pdo, $rackId);
        rackActionRedirect($returnUrl, $deleted ? 'deleted' : 'error', $deleted ? '' : 'Rack niet gevonden.');
    }

    $newRackId = rackPlannerDuplicateRack($pdo, $rackId);
    if ($newRackId === null) {
        rackActionRedirect($returnUrl, 'error', 'Rack niet gevonden.');
    }

    rackActionRedirect($returnUrl, 'duplicated');
} catch (Throwable $e) {
    rackActionRedirect($returnUrl, 'error', $e->getMessage());
}
