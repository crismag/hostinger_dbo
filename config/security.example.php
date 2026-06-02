<?php

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
];
