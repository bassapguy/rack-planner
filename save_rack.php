<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

function rackJsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rackJsonResponse(['ok' => false, 'message' => 'Ongeldige request.'], 405);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    rackJsonResponse(['ok' => false, 'message' => 'Kon request-body niet lezen.'], 400);
}

$rackId = isset($data['rackId']) ? (int)$data['rackId'] : 0;
$templateSlug = trim((string)($data['template'] ?? 'coolblue-v1'));
$rackName = trim((string)($data['rackName'] ?? ''));
$location = trim((string)($data['location'] ?? ''));
$project = trim((string)($data['project'] ?? ''));
$versionLabel = trim((string)($data['versionLabel'] ?? ''));
$issueDate = trim((string)($data['issueDate'] ?? ''));
$rackUnits = (int)($data['rackUnits'] ?? 0);
$items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];

if ($rackName === '') {
    rackJsonResponse(['ok' => false, 'message' => 'Racknaam is verplicht.'], 422);
}
if ($versionLabel === '') {
    rackJsonResponse(['ok' => false, 'message' => 'Versienummer is verplicht.'], 422);
}
if ($rackUnits < 1 || $rackUnits > 60) {
    rackJsonResponse(['ok' => false, 'message' => 'Rackhoogte moet tussen 1 en 60 HE liggen.'], 422);
}
if ($issueDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)) {
    $issueDate = date('Y-m-d');
}

$cleanItems = [];
$libraryUuids = [];
foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }

    $name = trim((string)($item['name'] ?? ''));
    $svgUrl = trim((string)($item['svgUrl'] ?? ''));
    $comments = trim((string)($item['comments'] ?? ''));
    $face = (($item['face'] ?? 'front') === 'back') ? 'back' : 'front';
    $he = (int)($item['he'] ?? 0);
    $widthPct = (int)($item['widthPct'] ?? 0);
    $uStart = (int)($item['uStart'] ?? 0);
    $libraryId = isset($item['libraryId']) ? trim((string)$item['libraryId']) : '';

    if ($name === '' || $svgUrl === '') {
        continue;
    }
    if ($he < 1 || $he > 20) {
        continue;
    }
    if ($widthPct < 10 || $widthPct > 100) {
        continue;
    }
    if ($uStart < 1 || ($uStart + $he - 1) > $rackUnits) {
        continue;
    }

    $cleanItems[] = [
        'libraryId' => $libraryId !== '' ? $libraryId : null,
        'name' => $name,
        'he' => $he,
        'widthPct' => $widthPct,
        'svgUrl' => $svgUrl,
        'uStart' => $uStart,
        'comments' => $comments,
        'face' => $face,
    ];

    if ($libraryId !== '') {
        $libraryUuids[$libraryId] = true;
    }
}

try {
    $pdo = db();
    $templateId = null;
    if ($templateSlug !== '') {
        $templateStmt = $pdo->prepare('SELECT id FROM templates WHERE slug = :slug LIMIT 1');
        $templateStmt->execute([':slug' => $templateSlug]);
        $templateIdValue = $templateStmt->fetchColumn();
        if ($templateIdValue !== false) {
            $templateId = (int)$templateIdValue;
        }
    }

    $libraryIdMap = [];
    if (!empty($libraryUuids)) {
        $placeholders = implode(', ', array_fill(0, count($libraryUuids), '?'));
        $libraryStmt = $pdo->prepare("SELECT id, uuid FROM library_items WHERE uuid IN ($placeholders)");
        $libraryStmt->execute(array_keys($libraryUuids));
        foreach ($libraryStmt->fetchAll() as $row) {
            $libraryIdMap[(string)$row['uuid']] = (int)$row['id'];
        }
    }

    $pdo->beginTransaction();

    if ($rackId > 0) {
        $checkStmt = $pdo->prepare('SELECT id FROM racks WHERE id = :id LIMIT 1');
        $checkStmt->execute([':id' => $rackId]);
        if (!$checkStmt->fetchColumn()) {
            $rackId = 0;
        }
    }

    if ($rackId > 0) {
        $updateStmt = $pdo->prepare(
            'UPDATE racks
             SET template_id = :template_id,
                 rack_name = :rack_name,
                 location = :location,
                 project = :project,
                 version_number = :version_number,
                 document_date = :document_date,
                 rack_units = :rack_units,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $updateStmt->bindValue(':template_id', $templateId, $templateId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $updateStmt->bindValue(':rack_name', $rackName);
        $updateStmt->bindValue(':location', $location !== '' ? $location : null, $location !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $updateStmt->bindValue(':project', $project !== '' ? $project : null, $project !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $updateStmt->bindValue(':version_number', $versionLabel);
        $updateStmt->bindValue(':document_date', $issueDate);
        $updateStmt->bindValue(':rack_units', $rackUnits, PDO::PARAM_INT);
        $updateStmt->bindValue(':id', $rackId, PDO::PARAM_INT);
        $updateStmt->execute();
    } else {
        $insertStmt = $pdo->prepare(
            'INSERT INTO racks (template_id, rack_name, location, project, version_number, document_date, rack_units)
             VALUES (:template_id, :rack_name, :location, :project, :version_number, :document_date, :rack_units)'
        );
        $insertStmt->bindValue(':template_id', $templateId, $templateId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $insertStmt->bindValue(':rack_name', $rackName);
        $insertStmt->bindValue(':location', $location !== '' ? $location : null, $location !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $insertStmt->bindValue(':project', $project !== '' ? $project : null, $project !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $insertStmt->bindValue(':version_number', $versionLabel);
        $insertStmt->bindValue(':document_date', $issueDate);
        $insertStmt->bindValue(':rack_units', $rackUnits, PDO::PARAM_INT);
        $insertStmt->execute();
        $rackId = (int)$pdo->lastInsertId();
    }

    $deleteStmt = $pdo->prepare('DELETE FROM rack_items WHERE rack_id = :rack_id');
    $deleteStmt->execute([':rack_id' => $rackId]);

    if (!empty($cleanItems)) {
        $itemStmt = $pdo->prepare(
            'INSERT INTO rack_items (
                rack_id,
                library_item_id,
                side,
                u_position,
                comment_text,
                item_name_snapshot,
                he_snapshot,
                width_pct_snapshot,
                svg_path_snapshot
            ) VALUES (
                :rack_id,
                :library_item_id,
                :side,
                :u_position,
                :comment_text,
                :item_name_snapshot,
                :he_snapshot,
                :width_pct_snapshot,
                :svg_path_snapshot
            )'
        );

        foreach ($cleanItems as $item) {
            $resolvedLibraryId = null;
            if ($item['libraryId'] !== null && isset($libraryIdMap[$item['libraryId']])) {
                $resolvedLibraryId = $libraryIdMap[$item['libraryId']];
            }

            $itemStmt->bindValue(':rack_id', $rackId, PDO::PARAM_INT);
            $itemStmt->bindValue(':library_item_id', $resolvedLibraryId, $resolvedLibraryId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $itemStmt->bindValue(':side', $item['face']);
            $itemStmt->bindValue(':u_position', $item['uStart'], PDO::PARAM_INT);
            $itemStmt->bindValue(':comment_text', $item['comments'] !== '' ? $item['comments'] : null, $item['comments'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $itemStmt->bindValue(':item_name_snapshot', $item['name']);
            $itemStmt->bindValue(':he_snapshot', $item['he'], PDO::PARAM_INT);
            $itemStmt->bindValue(':width_pct_snapshot', $item['widthPct'], PDO::PARAM_INT);
            $itemStmt->bindValue(':svg_path_snapshot', $item['svgUrl']);
            $itemStmt->execute();
        }
    }

    $pdo->commit();

    rackJsonResponse([
        'ok' => true,
        'rackId' => $rackId,
        'redirectUrl' => 'index.php?rack=' . $rackId . '&status=rack-saved',
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    rackJsonResponse([
        'ok' => false,
        'message' => 'Rack opslaan mislukt: ' . $e->getMessage(),
    ], 500);
}
