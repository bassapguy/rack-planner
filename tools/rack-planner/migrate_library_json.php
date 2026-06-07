<?php
require_once __DIR__ . '/db.php';

$libraryFile = __DIR__ . '/data/library.json';

if (!is_file($libraryFile)) {
    exit("Geen data/library.json gevonden.\n");
}

$json = file_get_contents($libraryFile);
$data = json_decode((string)$json, true);
if (!is_array($data)) {
    exit("library.json bevat geen geldige array.\n");
}

$pdo = db();
$inserted = 0;
$updated = 0;

$selectStmt = $pdo->prepare('SELECT id FROM library_items WHERE uuid = :uuid LIMIT 1');
$insertStmt = $pdo->prepare(
    'INSERT INTO library_items (uuid, name, he, width_pct, svg_path, is_active, created_at, updated_at)
     VALUES (:uuid, :name, :he, :width_pct, :svg_path, 1, :created_at, :updated_at)'
);
$updateStmt = $pdo->prepare(
    'UPDATE library_items
     SET name = :name,
         he = :he,
         width_pct = :width_pct,
         svg_path = :svg_path,
         is_active = 1,
         updated_at = :updated_at
     WHERE uuid = :uuid'
);

foreach ($data as $row) {
    if (!is_array($row)) {
        continue;
    }

    $uuid = trim((string)($row['id'] ?? ''));
    $name = trim((string)($row['name'] ?? ''));
    $he = (int)($row['he'] ?? 0);
    $widthPct = (int)($row['widthPct'] ?? 0);
    $svgPath = trim((string)($row['svgUrl'] ?? ''));
    $createdAt = trim((string)($row['createdAt'] ?? '')) ?: date('Y-m-d H:i:s');
    $updatedAt = trim((string)($row['updatedAt'] ?? '')) ?: $createdAt;

    if ($uuid === '' || $name === '' || $he < 1 || $widthPct < 1 || $svgPath === '') {
        continue;
    }

    $createdAt = date('Y-m-d H:i:s', strtotime($createdAt) ?: time());
    $updatedAt = date('Y-m-d H:i:s', strtotime($updatedAt) ?: time());

    $selectStmt->execute([':uuid' => $uuid]);
    $exists = (bool)$selectStmt->fetchColumn();

    if ($exists) {
        $updateStmt->execute([
            ':uuid' => $uuid,
            ':name' => $name,
            ':he' => $he,
            ':width_pct' => $widthPct,
            ':svg_path' => $svgPath,
            ':updated_at' => $updatedAt,
        ]);
        $updated++;
    } else {
        $insertStmt->execute([
            ':uuid' => $uuid,
            ':name' => $name,
            ':he' => $he,
            ':width_pct' => $widthPct,
            ':svg_path' => $svgPath,
            ':created_at' => $createdAt,
            ':updated_at' => $updatedAt,
        ]);
        $inserted++;
    }
}

echo "Klaar. Ingevoegd: {$inserted}, bijgewerkt: {$updated}.\n";
