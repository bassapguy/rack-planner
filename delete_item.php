<?php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: editor.php?status=error&message=' . urlencode('Ongeldige request.'));
    exit;
}

$id = trim($_POST['id'] ?? '');
if ($id === '') {
    header('Location: editor.php?status=error&message=' . urlencode('Geen library-item geselecteerd.'));
    exit;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare(
        'UPDATE library_items
         SET is_active = 0,
             updated_at = CURRENT_TIMESTAMP
         WHERE uuid = :uuid'
    );
    $stmt->execute([':uuid' => $id]);

    if ($stmt->rowCount() === 0) {
        $checkStmt = $pdo->prepare('SELECT id FROM library_items WHERE uuid = :uuid LIMIT 1');
        $checkStmt->execute([':uuid' => $id]);
        if (!$checkStmt->fetchColumn()) {
            header('Location: editor.php?status=error&message=' . urlencode('Library-item niet gevonden.'));
            exit;
        }
    }

    header('Location: editor.php?status=deleted');
    exit;
} catch (Throwable $e) {
    header('Location: editor.php?status=error&message=' . urlencode('Verwijderen mislukt: ' . $e->getMessage()));
    exit;
}
