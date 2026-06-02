<?php

/**
 * @file security.example.php
 *
 * Provides the copyable security configuration template consumed by the API gateway.
 *
 * Creation Date: 2026-06-02
 * Inputs: Deployment-specific values edited by the operator after copying this template.
 * Outputs: Returns a PHP configuration array.
 * Usage: Copy to config/security.php and customize deployment values.
 * Author: Cris Magalang
 * Code Assistants and generators: Codex and Claude code
 */
declare(strict_types=1);

/** Copy to security.php and provide secrets through the environment where possible. */
return [
    'timestamp_window_seconds' => 300,
    'max_body_bytes' => 65536,
    'max_requests_per_minute' => 60,

    // The key is api_clients.client_id. Prefer injecting values from a secret store or environment.
    'client_secrets' => [
        'example-client' => getenv('API_CLIENT_EXAMPLE_SECRET') ?: 'replace-with-a-long-random-secret',
    ],
    // If enabled, api_clients.secret_hash is treated as an MVP plaintext secret reference value.
    // Keep false when secrets are supplied above; harden storage before production use.
    'allow_database_secrets' => false,

    // Finding #7 — reject plaintext HTTP. Localhost and dev_mode are always exempt.
    'require_https' => true,
    'dev_mode' => false,
    // Trust proxy-provided X-Forwarded-Proto / X-Forwarded-For only from these immediate peers.
    // Use exact IPs or CIDR ranges and leave empty when the gateway is directly exposed.
    'trusted_proxies' => [
        // '203.0.113.10',
        // '2001:db8::/32',
    ],

    // Finding #1 — IP-keyed abuse gate that runs before authentication. Filesystem-backed; never
    // touches the database, so invalid clients and bot scans cannot generate DB reads or audit writes.
    'pre_auth_rate_limit' => [
        'enabled' => true,
        'minute_limit' => 30,
        'hour_limit' => 500,
        // Defaults to sys_get_temp_dir().'/dbo_gateway_ratelimit'. Must be writable by PHP.
        'storage_dir' => null,
    ],

    // Finding #5 — audit volume control. Modes: all | authenticated_only | sampled | critical_only.
    'audit' => [
        'mode' => 'authenticated_only',
        'sample_rate' => 10,    // sampled mode: record 1 in N failures (successes always recorded)
        'retention_days' => 90, // used by bin/cleanup.php
    ],

    // Finding #4 — require update/delete to filter by the primary key (no accidental bulk mutations).
    'mutation_guard' => [
        'enabled' => true,
    ],

    // Finding #3 — tenant isolation. on_violation: reject (recommended) | override.
    'tenant_scope' => [
        'on_violation' => 'reject',
    ],

    // Per-client configuration keyed by api_clients.client_id.
    // - enforced_filters: server-controlled scope merged into every where (insert: into data).
    //   Clients must NOT send these fields themselves.
    // - allow_bulk_updates: exempt this client from the mutation guard.
    'clients' => [
        'example-client' => [
            'enforced_filters' => [
                // 'tenant_id' => 1001,
            ],
            'allow_bulk_updates' => false,
        ],
    ],

    // Finding #2 — optional anonymous public demo. Disabled by default. Unsigned requests are
    // served ONLY for the interfaces explicitly defined here; everything else is denied.
    'public_demo' => [
        'enabled' => false,
        'rate_limit' => [
            'per_minute' => 1,
            'per_hour' => 10,
            'per_day' => 30,
        ],
        'permissions' => [
            'projects' => [
                'select' => [
                    'enabled' => true,
                    'max_limit' => 5,
                    'allowed_fields' => ['id', 'name', 'status'],
                    'allowed_filters' => ['status'],
                    // Always applied; the demo column must exist and be registry-filterable.
                    'required_where' => ['is_demo' => 1],
                ],
            ],
        ],
    ],
];
