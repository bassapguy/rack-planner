CREATE TABLE IF NOT EXISTS toolbox_tools (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tool_key VARCHAR(120) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT DEFAULT NULL,
    home_path VARCHAR(255) NOT NULL,
    required_permission VARCHAR(120) DEFAULT NULL,
    status ENUM('draft', 'published', 'on_hold', 'archived', 'deleted') NOT NULL DEFAULT 'draft',
    status_note VARCHAR(255) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_toolbox_tools_key (tool_key),
    KEY idx_toolbox_tools_status (status),
    KEY idx_toolbox_tools_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO toolbox_permissions (permission_key, label, description, sort_order)
VALUES ('tools.manage', 'Manage tools', 'Register tools and control publish, hold, archive, and delete status.', 35)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    sort_order = VALUES(sort_order);

INSERT INTO toolbox_role_permissions (role_key, permission_key)
VALUES
    ('super_admin', 'tools.manage'),
    ('admin', 'tools.manage')
ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key);

INSERT INTO toolbox_tools (tool_key, name, description, home_path, required_permission, status, status_note, sort_order)
VALUES (
    'rack-planner',
    'Rack Planner',
    'Build rack layouts, manage exports, and keep rack documentation together.',
    'tools/rack-planner/index.php',
    'rack_planner.access',
    'published',
    NULL,
    10
)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    home_path = VALUES(home_path),
    required_permission = VALUES(required_permission),
    sort_order = VALUES(sort_order),
    updated_at = CURRENT_TIMESTAMP;
