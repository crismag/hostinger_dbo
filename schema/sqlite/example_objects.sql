-- SQLite example registry-controlled object tables (mirror of the MySQL variant).
CREATE TABLE tenants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_code TEXT NOT NULL UNIQUE,
    tenant_name TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled')),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE projects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    name TEXT NOT NULL,
    status TEXT NOT NULL,
    description TEXT NULL,
    -- Marks rows that are safe to expose through the optional public demo accessor.
    is_demo INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL
);
CREATE INDEX idx_project_tenant ON projects (tenant_id);
CREATE INDEX idx_project_is_demo ON projects (is_demo);

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    UNIQUE (tenant_id, email)
);

-- schema_json is stored as plain JSON text (SQLite has no JSON column type or JSON_OBJECT()).
INSERT INTO api_entities (entity_name, table_name, primary_key_name, schema_json) VALUES
('projects', 'projects', 'id', '{"fields":["id","tenant_id","name","status","description","is_demo","created_at","updated_at"],"insertable":["tenant_id","name","status","description","is_demo"],"updatable":["name","status","description"],"filterable":["id","tenant_id","status","is_demo"],"orderable":["id","created_at","updated_at"],"searchable":["name","description"],"groupable":["status","tenant_id","is_demo"],"aggregatable":["id"]}'),
('users', 'users', 'id', '{"fields":["id","tenant_id","name","email","status","created_at","updated_at"],"insertable":["tenant_id","name","email","status"],"updatable":["name","email","status"],"filterable":["id","tenant_id","email","status"],"orderable":["id","created_at","updated_at"],"searchable":["name","email"],"groupable":["status","tenant_id"],"aggregatable":["id"]}');
