<?php

declare(strict_types=1);

/**
 * Environment profiles. Selected by the APP_ENV environment variable and
 * deep-merged over config/security.php at bootstrap. Profiles are opt-in:
 * with no APP_ENV set, config/security.php is used unchanged.
 *
 * Override only the keys a profile needs to change; everything else is
 * inherited from config/security.php. These values are policy, not secrets,
 * so this file is safe to commit.
 */
return [
    // Fail-secure defaults for production.
    'prod' => [
        'require_https' => true,
        'dev_mode' => false,
        'audit' => ['mode' => 'authenticated_only'],
        'public_demo' => ['enabled' => false],
    ],

    // Public showcase: HTTPS on, but anonymous demo access + sampled auditing.
    'demo' => [
        'require_https' => true,
        'dev_mode' => false,
        'audit' => ['mode' => 'sampled'],
        'public_demo' => ['enabled' => true],
    ],

    // Local development: relaxed transport, full auditing for visibility.
    'dev' => [
        'require_https' => false,
        'dev_mode' => true,
        'audit' => ['mode' => 'all'],
    ],
];
