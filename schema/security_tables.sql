-- MySQL/MariaDB security and registry tables for the PDO object gateway.
CREATE TABLE api_clients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id VARCHAR(128) NOT NULL UNIQUE,
    client_name VARCHAR(255) NOT NULL,
    status ENUM('active', 'disabled', 'revoked') NOT NULL DEFAULT 'active',
    -- When allow_database_secrets is enabled, this stores the plaintext HMAC secret value.
    secret_hash VARCHAR(255) NOT NULL,
    allowed_ips TEXT NULL,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    rotated_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_client_id (client_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE api_client_permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    entity_name VARCHAR(128) NOT NULL,
    can_select BOOLEAN NOT NULL DEFAULT FALSE,
    can_insert BOOLEAN NOT NULL DEFAULT FALSE,
    can_update BOOLEAN NOT NULL DEFAULT FALSE,
    can_delete BOOLEAN NOT NULL DEFAULT FALSE,
    max_rows_per_select INT NOT NULL DEFAULT 100,
    allowed_fields_json JSON NULL,
    allowed_filter_fields_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_client_entity (client_id, entity_name),
    CONSTRAINT fk_perm_client FOREIGN KEY (client_id) REFERENCES api_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE api_nonces (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    nonce VARCHAR(128) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    UNIQUE KEY uk_client_nonce (client_id, nonce),
    INDEX idx_expires (expires_at),
    CONSTRAINT fk_nonce_client FOREIGN KEY (client_id) REFERENCES api_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE api_rate_limits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    bucket_key VARCHAR(128) NOT NULL,
    request_count INT NOT NULL DEFAULT 0,
    window_start TIMESTAMP NOT NULL,
    window_end TIMESTAMP NOT NULL,
    UNIQUE KEY uk_client_bucket (client_id, bucket_key),
    CONSTRAINT fk_rl_client FOREIGN KEY (client_id) REFERENCES api_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE api_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(64) NOT NULL,
    client_id BIGINT UNSIGNED NULL,
    entity_name VARCHAR(128) NULL,
    action_name VARCHAR(32) NULL,
    request_method VARCHAR(16) NOT NULL,
    request_path VARCHAR(255) NOT NULL,
    request_hash CHAR(64) NULL,
    ip_address VARCHAR(64) NULL,
    status_code INT NOT NULL,
    success BOOLEAN NOT NULL,
    error_code VARCHAR(128) NULL,
    duration_ms INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    INDEX idx_entity_action (entity_name, action_name),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE api_entities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_name VARCHAR(128) NOT NULL UNIQUE,
    table_name VARCHAR(128) NOT NULL,
    primary_key_name VARCHAR(128) NOT NULL DEFAULT 'id',
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    schema_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
