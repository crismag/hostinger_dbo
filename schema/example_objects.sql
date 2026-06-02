-- Example registry-controlled object tables.
CREATE TABLE tenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_code VARCHAR(128) NOT NULL UNIQUE,
    tenant_name VARCHAR(255) NOT NULL,
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE projects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    name VARCHAR(255) NOT NULL,
    status VARCHAR(64) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_project_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    status VARCHAR(64) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_tenant_email (tenant_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO api_entities (entity_name, table_name, primary_key_name, schema_json) VALUES
('projects', 'projects', 'id', JSON_OBJECT(
    'fields', JSON_ARRAY('id', 'tenant_id', 'name', 'status', 'description', 'created_at', 'updated_at'),
    'insertable', JSON_ARRAY('tenant_id', 'name', 'status', 'description'),
    'updatable', JSON_ARRAY('name', 'status', 'description'),
    'filterable', JSON_ARRAY('id', 'tenant_id', 'status'),
    'orderable', JSON_ARRAY('id', 'created_at', 'updated_at')
)),
('users', 'users', 'id', JSON_OBJECT(
    'fields', JSON_ARRAY('id', 'tenant_id', 'name', 'email', 'status', 'created_at', 'updated_at'),
    'insertable', JSON_ARRAY('tenant_id', 'name', 'email', 'status'),
    'updatable', JSON_ARRAY('name', 'email', 'status'),
    'filterable', JSON_ARRAY('id', 'tenant_id', 'email', 'status'),
    'orderable', JSON_ARRAY('id', 'created_at', 'updated_at')
));
