<?php

function rackPlannerLoadTemplates(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, name, slug, document_title, logo_path, is_default
         FROM templates
         ORDER BY is_default DESC, name ASC, id ASC'
    );

    return $stmt->fetchAll();
}

function rackPlannerNormalizeTemplates(array $templates): array
{
    $normalized = [];
    $seen = [];

    foreach ($templates as $template) {
        $slug = (string)($template['slug'] ?? '');
        if ($slug === '') {
            continue;
        }

        $seen[$slug] = true;
        $normalized[] = [
            'id' => isset($template['id']) ? (int)$template['id'] : null,
            'name' => (string)($template['name'] ?? $slug),
            'slug' => $slug,
            'documentTitle' => (string)($template['document_title'] ?? 'Rack Design'),
            'logoPath' => isset($template['logo_path']) && $template['logo_path'] !== '' ? (string)$template['logo_path'] : null,
            'isDefault' => !empty($template['is_default']),
        ];
    }

    if (!isset($seen['plain-v1'])) {
        $normalized[] = [
            'id' => null,
            'name' => 'Plain V1',
            'slug' => 'plain-v1',
            'documentTitle' => 'Rack Design',
            'logoPath' => null,
            'isDefault' => false,
        ];
    }

    return $normalized;
}

function rackPlannerLoadRackSummaries(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT r.id,
                r.rack_name,
                r.location,
                r.project,
                r.version_number,
                r.document_date,
                r.rack_units,
                r.updated_at,
                t.slug AS template_slug,
                t.name AS template_name
         FROM racks r
         LEFT JOIN templates t ON t.id = r.template_id
         ORDER BY r.updated_at DESC, r.id DESC'
    );

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'id' => (int)$row['id'],
            'rackName' => (string)$row['rack_name'],
            'location' => $row['location'] !== null ? (string)$row['location'] : '',
            'project' => $row['project'] !== null ? (string)$row['project'] : '',
            'versionLabel' => (string)$row['version_number'],
            'issueDate' => (string)$row['document_date'],
            'rackUnits' => (int)$row['rack_units'],
            'template' => $row['template_slug'] ? (string)$row['template_slug'] : 'plain-v1',
            'templateName' => $row['template_name'] ? (string)$row['template_name'] : 'Plain V1',
            'updatedAt' => isset($row['updated_at']) ? (string)$row['updated_at'] : null,
        ];
    }

    return $rows;
}

function rackPlannerLoadRackDetails(PDO $pdo, int $rackId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT r.id,
                r.rack_name,
                r.location,
                r.project,
                r.version_number,
                r.document_date,
                r.rack_units,
                t.slug AS template_slug,
                t.document_title,
                t.name AS template_name
         FROM racks r
         LEFT JOIN templates t ON t.id = r.template_id
         WHERE r.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $rackId]);
    $rack = $stmt->fetch();

    if (!$rack) {
        return null;
    }

    $itemStmt = $pdo->prepare(
        'SELECT ri.id,
                ri.side,
                ri.u_position,
                ri.comment_text,
                ri.item_name_snapshot,
                ri.he_snapshot,
                ri.width_pct_snapshot,
                ri.svg_path_snapshot,
                li.uuid AS library_uuid
         FROM rack_items ri
         LEFT JOIN library_items li ON li.id = ri.library_item_id
         WHERE ri.rack_id = :rack_id
         ORDER BY FIELD(ri.side, "front", "back"), ri.u_position ASC, ri.id ASC'
    );
    $itemStmt->execute([':rack_id' => $rackId]);

    $items = [];
    foreach ($itemStmt->fetchAll() as $item) {
        $items[] = [
            'instanceId' => (int)$item['id'],
            'libraryId' => $item['library_uuid'] ? (string)$item['library_uuid'] : null,
            'name' => (string)$item['item_name_snapshot'],
            'he' => (int)$item['he_snapshot'],
            'widthPct' => (int)$item['width_pct_snapshot'],
            'svgUrl' => (string)$item['svg_path_snapshot'],
            'uStart' => (int)$item['u_position'],
            'comments' => $item['comment_text'] !== null ? (string)$item['comment_text'] : '',
            'face' => $item['side'] === 'back' ? 'back' : 'front',
        ];
    }

    return [
        'id' => (int)$rack['id'],
        'template' => $rack['template_slug'] ? (string)$rack['template_slug'] : 'plain-v1',
        'docTitle' => $rack['document_title'] ? (string)$rack['document_title'] : 'Rack Design',
        'rackName' => (string)$rack['rack_name'],
        'location' => $rack['location'] !== null ? (string)$rack['location'] : '',
        'project' => $rack['project'] !== null ? (string)$rack['project'] : '',
        'versionLabel' => (string)$rack['version_number'],
        'issueDate' => (string)$rack['document_date'],
        'rackUnits' => (int)$rack['rack_units'],
        'items' => $items,
    ];
}


function rackPlannerBuildRackSummaryFilters(string $search = ''): array
{
    $search = trim($search);
    if ($search == '') {
        return ['where' => '', 'params' => []];
    }

    $like = '%' . $search . '%';

    return [
        'where' => 'WHERE (
            r.rack_name LIKE :search OR
            COALESCE(r.location, "") LIKE :search OR
            COALESCE(r.project, "") LIKE :search OR
            r.version_number LIKE :search OR
            COALESCE(t.name, "") LIKE :search
        )',
        'params' => [':search' => $like],
    ];
}

function rackPlannerLoadRackManagementRows(PDO $pdo, string $search = ''): array
{
    $filter = rackPlannerBuildRackSummaryFilters($search);
    $sql = 'SELECT r.id,
                   r.rack_name,
                   r.location,
                   r.project,
                   r.version_number,
                   r.document_date,
                   r.rack_units,
                   r.updated_at,
                   t.slug AS template_slug,
                   t.name AS template_name,
                   COUNT(ri.id) AS item_count
            FROM racks r
            LEFT JOIN templates t ON t.id = r.template_id
            LEFT JOIN rack_items ri ON ri.rack_id = r.id '
            . $filter['where'] . '
            GROUP BY r.id, r.rack_name, r.location, r.project, r.version_number, r.document_date, r.rack_units, r.updated_at, t.slug, t.name
            ORDER BY r.updated_at DESC, r.id DESC';

    $stmt = $pdo->prepare($sql);
    foreach ($filter['params'] as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->execute();

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'id' => (int)$row['id'],
            'rackName' => (string)$row['rack_name'],
            'location' => $row['location'] !== null ? (string)$row['location'] : '',
            'project' => $row['project'] !== null ? (string)$row['project'] : '',
            'versionLabel' => (string)$row['version_number'],
            'issueDate' => (string)$row['document_date'],
            'rackUnits' => (int)$row['rack_units'],
            'template' => $row['template_slug'] ? (string)$row['template_slug'] : 'plain-v1',
            'templateName' => $row['template_name'] ? (string)$row['template_name'] : 'Plain V1',
            'updatedAt' => isset($row['updated_at']) ? (string)$row['updated_at'] : null,
            'itemCount' => isset($row['item_count']) ? (int)$row['item_count'] : 0,
        ];
    }

    return $rows;
}

function rackPlannerDeleteRack(PDO $pdo, int $rackId): bool
{
    $stmt = $pdo->prepare('DELETE FROM racks WHERE id = :id');
    $stmt->execute([':id' => $rackId]);
    return $stmt->rowCount() > 0;
}

function rackPlannerDuplicateRack(PDO $pdo, int $rackId): ?int
{
    $rack = rackPlannerLoadRackDetails($pdo, $rackId);
    if (!$rack) {
        return null;
    }

    $metaStmt = $pdo->prepare('SELECT template_id, location, project, version_number, document_date, rack_units, rack_name FROM racks WHERE id = :id LIMIT 1');
    $metaStmt->execute([':id' => $rackId]);
    $source = $metaStmt->fetch();
    if (!$source) {
        return null;
    }

    $newName = (string)$source['rack_name'];
    if (mb_stripos($newName, 'kopie') === false) {
        $newName .= ' kopie';
    }

    $pdo->beginTransaction();
    try {
        $insertRack = $pdo->prepare('INSERT INTO racks (template_id, rack_name, location, project, version_number, document_date, rack_units)
                                     VALUES (:template_id, :rack_name, :location, :project, :version_number, :document_date, :rack_units)');
        $insertRack->bindValue(':template_id', $source['template_id'] !== null ? (int)$source['template_id'] : null, $source['template_id'] !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $insertRack->bindValue(':rack_name', $newName);
        $insertRack->bindValue(':location', $source['location'] !== null ? (string)$source['location'] : null, $source['location'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $insertRack->bindValue(':project', $source['project'] !== null ? (string)$source['project'] : null, $source['project'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $insertRack->bindValue(':version_number', (string)$source['version_number']);
        $insertRack->bindValue(':document_date', (string)$source['document_date']);
        $insertRack->bindValue(':rack_units', (int)$source['rack_units'], PDO::PARAM_INT);
        $insertRack->execute();
        $newRackId = (int)$pdo->lastInsertId();

        $copyItems = $pdo->prepare('INSERT INTO rack_items (
                                        rack_id,
                                        library_item_id,
                                        side,
                                        u_position,
                                        comment_text,
                                        item_name_snapshot,
                                        he_snapshot,
                                        width_pct_snapshot,
                                        svg_path_snapshot
                                    )
                                    SELECT :new_rack_id,
                                           library_item_id,
                                           side,
                                           u_position,
                                           comment_text,
                                           item_name_snapshot,
                                           he_snapshot,
                                           width_pct_snapshot,
                                           svg_path_snapshot
                                    FROM rack_items
                                    WHERE rack_id = :source_rack_id');
        $copyItems->execute([
            ':new_rack_id' => $newRackId,
            ':source_rack_id' => $rackId,
        ]);

        $pdo->commit();
        return $newRackId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}


function rackPlannerTemplateFieldDefinitions(): array
{
    return [
        'rack_name' => ['label' => 'Racknaam', 'required' => true, 'enabled' => true],
        'location' => ['label' => 'Locatie', 'required' => false, 'enabled' => true],
        'project' => ['label' => 'Project', 'required' => false, 'enabled' => true],
        'version_number' => ['label' => 'Versienummer', 'required' => true, 'enabled' => true],
        'document_date' => ['label' => 'Datum', 'required' => false, 'enabled' => true],
    ];
}

function rackPlannerBuildTemplateFieldState(array $rows): array
{
    $definitions = rackPlannerTemplateFieldDefinitions();
    $indexed = [];
    foreach ($rows as $row) {
        $key = (string)($row['field_key'] ?? '');
        if ($key !== '') {
            $indexed[$key] = $row;
        }
    }

    $result = [];
    $order = 1;
    foreach ($definitions as $key => $definition) {
        $row = $indexed[$key] ?? [];
        $result[] = [
            'fieldKey' => $key,
            'fieldLabel' => (string)($row['field_label'] ?? $definition['label']),
            'isEnabled' => array_key_exists($key, $indexed) ? !empty($row['is_enabled']) : (bool)$definition['enabled'],
            'isRequired' => array_key_exists($key, $indexed) ? !empty($row['is_required']) : (bool)$definition['required'],
            'sortOrder' => isset($row['sort_order']) ? (int)$row['sort_order'] : $order,
        ];
        $order++;
    }

    usort($result, static function (array $a, array $b): int {
        return [$a['sortOrder'], $a['fieldLabel']] <=> [$b['sortOrder'], $b['fieldLabel']];
    });

    return $result;
}

function rackPlannerBuildTemplateFilters(string $search = ''): array
{
    $search = trim($search);
    if ($search === '') {
        return ['where' => '', 'params' => []];
    }

    $like = '%' . $search . '%';
    return [
        'where' => 'WHERE (
            t.name LIKE :search OR
            t.slug LIKE :search OR
            COALESCE(t.document_title, "") LIKE :search
        )',
        'params' => [':search' => $like],
    ];
}

function rackPlannerLoadTemplateManagementRows(PDO $pdo, string $search = ''): array
{
    $filter = rackPlannerBuildTemplateFilters($search);
    $sql = 'SELECT t.id,
                   t.name,
                   t.slug,
                   t.document_title,
                   t.logo_path,
                   t.paper_size,
                   t.orientation,
                   t.is_default,
                   t.updated_at,
                   COUNT(tf.id) AS field_count,
                   COUNT(r.id) AS rack_count
            FROM templates t
            LEFT JOIN template_fields tf ON tf.template_id = t.id
            LEFT JOIN racks r ON r.template_id = t.id '
            . $filter['where'] . '
            GROUP BY t.id, t.name, t.slug, t.document_title, t.logo_path, t.paper_size, t.orientation, t.is_default, t.updated_at
            ORDER BY t.is_default DESC, t.updated_at DESC, t.id DESC';

    $stmt = $pdo->prepare($sql);
    foreach ($filter['params'] as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->execute();

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'slug' => (string)$row['slug'],
            'documentTitle' => (string)$row['document_title'],
            'logoPath' => $row['logo_path'] !== null ? (string)$row['logo_path'] : '',
            'paperSize' => (string)$row['paper_size'],
            'orientation' => (string)$row['orientation'],
            'isDefault' => !empty($row['is_default']),
            'updatedAt' => isset($row['updated_at']) ? (string)$row['updated_at'] : null,
            'fieldCount' => isset($row['field_count']) ? (int)$row['field_count'] : 0,
            'rackCount' => isset($row['rack_count']) ? (int)$row['rack_count'] : 0,
        ];
    }

    return $rows;
}

function rackPlannerLoadTemplateDetails(PDO $pdo, int $templateId): ?array
{
    $stmt = $pdo->prepare('SELECT id, name, slug, document_title, logo_path, paper_size, orientation, is_default FROM templates WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $templateId]);
    $template = $stmt->fetch();
    if (!$template) {
        return null;
    }

    $fieldStmt = $pdo->prepare('SELECT field_key, field_label, is_enabled, is_required, sort_order FROM template_fields WHERE template_id = :template_id ORDER BY sort_order ASC, id ASC');
    $fieldStmt->execute([':template_id' => $templateId]);

    return [
        'id' => (int)$template['id'],
        'name' => (string)$template['name'],
        'slug' => (string)$template['slug'],
        'documentTitle' => (string)$template['document_title'],
        'logoPath' => $template['logo_path'] !== null ? (string)$template['logo_path'] : '',
        'paperSize' => (string)$template['paper_size'],
        'orientation' => (string)$template['orientation'],
        'isDefault' => !empty($template['is_default']),
        'fields' => rackPlannerBuildTemplateFieldState($fieldStmt->fetchAll()),
    ];
}

function rackPlannerSlugify(string $value): string
{
    $value = trim(mb_strtolower($value));
    if ($value === '') {
        return 'template';
    }
    $value = preg_replace('~[^\pL\pN]+~u', '-', $value) ?? 'template';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'template';
}

function rackPlannerEnsureUniqueTemplateSlug(PDO $pdo, string $slug, ?int $exceptId = null): string
{
    $base = rackPlannerSlugify($slug);
    $candidate = $base;
    $suffix = 2;

    while (true) {
        $sql = 'SELECT id FROM templates WHERE slug = :slug';
        $params = [':slug' => $candidate];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $exceptId;
        }
        $stmt = $pdo->prepare($sql . ' LIMIT 1');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        if (!$stmt->fetch()) {
            return $candidate;
        }
        $candidate = $base . '-' . $suffix;
        $suffix++;
    }
}

function rackPlannerSaveTemplate(PDO $pdo, array $payload): int
{
    $templateId = isset($payload['id']) && (int)$payload['id'] > 0 ? (int)$payload['id'] : null;
    $name = trim((string)($payload['name'] ?? ''));
    $slug = trim((string)($payload['slug'] ?? ''));
    $documentTitle = trim((string)($payload['document_title'] ?? 'Rack Design')) ?: 'Rack Design';
    $logoPath = trim((string)($payload['logo_path'] ?? ''));
    $isDefault = !empty($payload['is_default']);
    $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];

    if ($name === '') {
        throw new InvalidArgumentException('Template naam is verplicht.');
    }

    $slug = rackPlannerEnsureUniqueTemplateSlug($pdo, $slug !== '' ? $slug : $name, $templateId);

    $pdo->beginTransaction();
    try {
        if ($isDefault) {
            $pdo->exec('UPDATE templates SET is_default = 0');
        }

        if ($templateId === null) {
            $stmt = $pdo->prepare('INSERT INTO templates (name, slug, document_title, logo_path, paper_size, orientation, is_default) VALUES (:name, :slug, :document_title, :logo_path, "A4", "portrait", :is_default)');
            $stmt->execute([
                ':name' => $name,
                ':slug' => $slug,
                ':document_title' => $documentTitle,
                ':logo_path' => $logoPath !== '' ? $logoPath : null,
                ':is_default' => $isDefault ? 1 : 0,
            ]);
            $templateId = (int)$pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare('UPDATE templates SET name = :name, slug = :slug, document_title = :document_title, logo_path = :logo_path, is_default = :is_default WHERE id = :id');
            $stmt->execute([
                ':id' => $templateId,
                ':name' => $name,
                ':slug' => $slug,
                ':document_title' => $documentTitle,
                ':logo_path' => $logoPath !== '' ? $logoPath : null,
                ':is_default' => $isDefault ? 1 : 0,
            ]);
        }

        $pdo->prepare('DELETE FROM template_fields WHERE template_id = :template_id')->execute([':template_id' => $templateId]);
        $insertField = $pdo->prepare('INSERT INTO template_fields (template_id, field_key, field_label, is_enabled, is_required, sort_order) VALUES (:template_id, :field_key, :field_label, :is_enabled, :is_required, :sort_order)');
        $definitions = rackPlannerTemplateFieldDefinitions();
        $sortOrder = 1;
        foreach ($definitions as $fieldKey => $definition) {
            $fieldPayload = $fields[$fieldKey] ?? [];
            $fieldLabel = trim((string)($fieldPayload['field_label'] ?? $definition['label']));
            $isEnabled = !empty($fieldPayload['is_enabled']);
            $isRequired = !empty($fieldPayload['is_required']);
            $insertField->execute([
                ':template_id' => $templateId,
                ':field_key' => $fieldKey,
                ':field_label' => $fieldLabel !== '' ? $fieldLabel : $definition['label'],
                ':is_enabled' => $isEnabled ? 1 : 0,
                ':is_required' => $isRequired ? 1 : 0,
                ':sort_order' => $sortOrder,
            ]);
            $sortOrder++;
        }

        $pdo->commit();
        return $templateId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function rackPlannerDuplicateTemplate(PDO $pdo, int $templateId): ?int
{
    $template = rackPlannerLoadTemplateDetails($pdo, $templateId);
    if (!$template) {
        return null;
    }

    $copyName = $template['name'];
    if (mb_stripos($copyName, 'kopie') === false) {
        $copyName .= ' kopie';
    }

    $fieldPayload = [];
    foreach ($template['fields'] as $field) {
        $fieldPayload[$field['fieldKey']] = [
            'field_label' => $field['fieldLabel'],
            'is_enabled' => $field['isEnabled'],
            'is_required' => $field['isRequired'],
        ];
    }

    return rackPlannerSaveTemplate($pdo, [
        'name' => $copyName,
        'slug' => $template['slug'] . '-kopie',
        'document_title' => $template['documentTitle'],
        'logo_path' => $template['logoPath'],
        'is_default' => false,
        'fields' => $fieldPayload,
    ]);
}

function rackPlannerDeleteTemplate(PDO $pdo, int $templateId): bool
{
    $stmt = $pdo->prepare('DELETE FROM templates WHERE id = :id');
    $stmt->execute([':id' => $templateId]);
    return $stmt->rowCount() > 0;
}
