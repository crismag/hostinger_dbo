-- SQLite security and registry tables for the PDO object gateway.
-- Table and column names are identical to the MySQL schema so the registry,
-- QueryBuilder, and services are unchanged across drivers.
CREATE TABLE api_clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id TEXT NOT NULL UNIQUE,
    client_name TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled', 'revoked')),
    -- When allow_database_secrets is enabled, this stores the plaintext HMAC secret value.
    secret_hash TEXT NOT NULL,
    allowed_ips TEXT NULL,
    description TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    rotated_at DATETIME NULL DEFAULT NULL
);
CREATE INDEX idx_clients_status ON api_clients (status);

CREATE TABLE api_client_permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL,
    entity_name TEXT NOT NULL,
    can_select INTEGER NOT NULL DEFAULT 0,
    can_insert INTEGER NOT NULL DEFAULT 0,
    can_update INTEGER NOT NULL DEFAULT 0,
    can_delete INTEGER NOT NULL DEFAULT 0,
    max_rows_per_select INTEGER NOT NULL DEFAULT 100,
    allowed_fields_json TEXT NULL,
    allowed_filter_fields_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (client_id, entity_name),
    FOREIGN KEY (client_id) REFERENCES api_clients (id) ON DELETE CASCADE
);

CREATE TABLE api_nonces (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL,
    nonce TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    UNIQUE (client_id, nonce),
    FOREIGN KEY (client_id) REFERENCES api_clients (id) ON DELETE CASCADE
);
CREATE INDEX idx_nonces_expires ON api_nonces (expires_at);

CREATE TABLE api_rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL,
    bucket_key TEXT NOT NULL,
    request_count INTEGER NOT NULL DEFAULT 0,
    window_start DATETIME NOT NULL,
    window_end DATETIME NOT NULL,
    UNIQUE (client_id, bucket_key),
    FOREIGN KEY (client_id) REFERENCES api_clients (id) ON DELETE CASCADE
);

CREATE TABLE api_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NOT NULL,
    client_id INTEGER NULL,
    entity_name TEXT NULL,
    action_name TEXT NULL,
    request_method TEXT NOT NULL,
    request_path TEXT NOT NULL,
    request_hash TEXT NULL,
    ip_address TEXT NULL,
    status_code INTEGER NOT NULL,
    success INTEGER NOT NULL,
    error_code TEXT NULL,
    duration_ms INTEGER NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_audit_client ON api_audit_logs (client_id);
CREATE INDEX idx_audit_entity_action ON api_audit_logs (entity_name, action_name);
CREATE INDEX idx_audit_created ON api_audit_logs (created_at);

CREATE TABLE api_entities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_name TEXT NOT NULL UNIQUE,
    table_name TEXT NOT NULL,
    primary_key_name TEXT NOT NULL DEFAULT 'id',
    enabled INTEGER NOT NULL DEFAULT 1,
    schema_json TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
