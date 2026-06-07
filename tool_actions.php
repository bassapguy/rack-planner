<?php
require_once __DIR__ . '/bootstrap.php';
$user = toolboxRequirePermission('tools.manage');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    toolboxRedirect('admin_tools.php');
}

if (!toolboxVerifyCsrfToken($_POST['_csrf'] ?? null)) {
    toolboxFlash('error', 'Invalid security token. Please try again.');
    toolboxRedirect('admin_tools.php');
}

function normalizeToolKey(string $value): string
{
    $value = mb_strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9\-]+/', '-', $value);
    $value = preg_replace('/\-+/', '-', $value);
    return trim((string)$value, '-');
}

function normalizeToolStatus(string $value): string
{
    $value = trim($value);
    $definitions = toolboxToolStatusDefinitions();
    return isset($definitions[$value]) ? $value : 'draft';
}

function redirectBack(): void
{
    toolboxRedirect('admin_tools.php');
}

$action = trim((string)($_POST['action'] ?? ''));

try {
    if ($action === 'create_tool' || $action === 'save_tool') {
        $toolId = (int)($_POST['tool_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $toolKey = normalizeToolKey((string)($_POST['tool_key'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $toolIcon = trim((string)($_POST['tool_icon'] ?? ''));
        $versionLabel = trim((string)($_POST['version_label'] ?? ''));
        $homePath = ltrim(trim((string)($_POST['home_path'] ?? '')), '/');
        $requiredPermission = trim((string)($_POST['required_permission'] ?? ''));
        $status = normalizeToolStatus((string)($_POST['status'] ?? 'draft'));
        $sortOrder = (int)($_POST['sort_order'] ?? 100);
        $statusNote = trim((string)($_POST['status_note'] ?? ''));

        if ($name === '' || $toolKey === '' || $homePath === '') {
            throw new RuntimeException('Tool name, key, and home path are required.');
        }
        if (strpos($homePath, '..') !== false) {
            throw new RuntimeException('Home path is not allowed to contain .. segments.');
        }

        if ($action === 'create_tool') {
            $stmt = $pdo->prepare(
                'INSERT INTO toolbox_tools (tool_key, name, tool_icon, version_label, description, home_path, required_permission, status, status_note, sort_order)
                 VALUES (:tool_key, :name, :tool_icon, :version_label, :description, :home_path, :required_permission, :status, :status_note, :sort_order)'
            );
            $stmt->execute([
                ':tool_key' => $toolKey,
                ':name' => $name,
                ':tool_icon' => $toolIcon !== '' ? mb_substr($toolIcon, 0, 8) : null,
                ':version_label' => $versionLabel !== '' ? mb_substr($versionLabel, 0, 40) : null,
                ':description' => $description !== '' ? $description : null,
                ':home_path' => $homePath,
                ':required_permission' => $requiredPermission !== '' ? $requiredPermission : null,
                ':status' => $status,
                ':status_note' => $statusNote !== '' ? $statusNote : null,
                ':sort_order' => $sortOrder,
            ]);
            toolboxAudit($pdo, (int)$user['id'], 'tool.created', ['tool_key' => $toolKey, 'status' => $status]);
            toolboxFlash('success', 'Tool created successfully.');
        } else {
            if ($toolId <= 0) {
                throw new RuntimeException('Invalid tool selected.');
            }
            $stmt = $pdo->prepare(
                'UPDATE toolbox_tools
                 SET tool_key = :tool_key,
                     name = :name,
                     tool_icon = :tool_icon,
                     version_label = :version_label,
                     description = :description,
                     home_path = :home_path,
                     required_permission = :required_permission,
                     status = :status,
                     status_note = :status_note,
                     sort_order = :sort_order,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $stmt->execute([
                ':tool_key' => $toolKey,
                ':name' => $name,
                ':tool_icon' => $toolIcon !== '' ? mb_substr($toolIcon, 0, 8) : null,
                ':version_label' => $versionLabel !== '' ? mb_substr($versionLabel, 0, 40) : null,
                ':tool_icon' => $toolIcon !== '' ? mb_substr($toolIcon, 0, 8) : null,
                ':version_label' => $versionLabel !== '' ? mb_substr($versionLabel, 0, 40) : null,
                ':description' => $description !== '' ? $description : null,
                ':home_path' => $homePath,
                ':required_permission' => $requiredPermission !== '' ? $requiredPermission : null,
                ':status' => $status,
                ':status_note' => $statusNote !== '' ? $statusNote : null,
                ':sort_order' => $sortOrder,
                ':id' => $toolId,
            ]);
            toolboxAudit($pdo, (int)$user['id'], 'tool.updated', ['tool_id' => $toolId, 'tool_key' => $toolKey, 'status' => $status]);
            toolboxFlash('success', 'Tool updated successfully.');
        }
        redirectBack();
    }

    if ($action === 'set_status' || $action === 'soft_delete' || $action === 'restore_tool') {
        $toolId = (int)($_POST['tool_id'] ?? 0);
        if ($toolId <= 0) {
            throw new RuntimeException('Invalid tool selected.');
        }
        $tool = toolboxLoadToolById($toolId, $pdo);
        if (!$tool) {
            throw new RuntimeException('Tool not found.');
        }

        if ($action === 'soft_delete') {
            $newStatus = 'deleted';
        } elseif ($action === 'restore_tool') {
            $newStatus = 'draft';
        } else {
            $newStatus = normalizeToolStatus((string)($_POST['status'] ?? 'draft'));
        }

        $stmt = $pdo->prepare(
            'UPDATE toolbox_tools
             SET status = :status,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $newStatus,
            ':id' => $toolId,
        ]);
        toolboxAudit($pdo, (int)$user['id'], 'tool.status_changed', [
            'tool_id' => $toolId,
            'tool_key' => $tool['tool_key'],
            'from' => $tool['status'],
            'to' => $newStatus,
        ]);
        toolboxFlash('success', 'Tool status updated to ' . toolboxToolStatusLabel($newStatus) . '.');
        redirectBack();
    }

    throw new RuntimeException('Unknown tool action.');
} catch (Throwable $e) {
    toolboxFlash('error', $e->getMessage());
    redirectBack();
}
