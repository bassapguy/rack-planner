CREATE TABLE IF NOT EXISTS toolbox_roles (
    role_key VARCHAR(60) PRIMARY KEY,
    label VARCHAR(120) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS toolbox_permissions (
    permission_key VARCHAR(120) PRIMARY KEY,
    label VARCHAR(150) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS toolbox_role_permissions (
    role_key VARCHAR(60) NOT NULL,
    permission_key VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_key, permission_key),
    CONSTRAINT fk_toolbox_role_permissions_role FOREIGN KEY (role_key) REFERENCES toolbox_roles(role_key) ON DELETE CASCADE,
    CONSTRAINT fk_toolbox_role_permissions_permission FOREIGN KEY (permission_key) REFERENCES toolbox_permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO toolbox_roles (role_key, label, description, is_system)
VALUES
    ('super_admin', 'Super Admin', 'Full platform access including security and role management.', 1),
    ('admin', 'Admin', 'Operational administrator for users and tool management.', 1),
    ('editor', 'Editor', 'Can work inside tools and edit tool content.', 1),
    ('viewer', 'Viewer', 'Read-only user with access to approved tools.', 1)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_system = VALUES(is_system);

INSERT INTO toolbox_permissions (permission_key, label, description, sort_order)
VALUES
    ('toolbox.access', 'Access toolbox', 'Can sign in and access the toolbox overview.', 10),
    ('users.manage', 'Manage users', 'Create, edit, activate, and deactivate users.', 20),
    ('roles.manage', 'Manage roles', 'Edit role permission assignments.', 30),
    ('rack_planner.access', 'Access Rack Planner', 'Open the Rack Planner module.', 40),
    ('rack_planner.edit', 'Edit in Rack Planner', 'Use the Rack Planner editor and library actions.', 50),
    ('rack_planner.racks.manage', 'Manage saved racks', 'Open, duplicate, and delete saved racks.', 60),
    ('rack_planner.templates.manage', 'Manage export templates', 'Create and edit Rack Planner export templates.', 70)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    sort_order = VALUES(sort_order);

DELETE FROM toolbox_role_permissions;

INSERT INTO toolbox_role_permissions (role_key, permission_key)
VALUES
    ('super_admin', 'toolbox.access'),
    ('super_admin', 'users.manage'),
    ('super_admin', 'roles.manage'),
    ('super_admin', 'rack_planner.access'),
    ('super_admin', 'rack_planner.edit'),
    ('super_admin', 'rack_planner.racks.manage'),
    ('super_admin', 'rack_planner.templates.manage'),
    ('admin', 'toolbox.access'),
    ('admin', 'users.manage'),
    ('admin', 'rack_planner.access'),
    ('admin', 'rack_planner.edit'),
    ('admin', 'rack_planner.racks.manage'),
    ('admin', 'rack_planner.templates.manage'),
    ('editor', 'toolbox.access'),
    ('editor', 'rack_planner.access'),
    ('editor', 'rack_planner.edit'),
    ('viewer', 'toolbox.access'),
    ('viewer', 'rack_planner.access');
