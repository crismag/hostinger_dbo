<?php

/**
 * @file database.example.php
 *
 * Provides the copyable database connection template consumed by the PDO connection factory.
 *
 * Creation Date: 2026-06-02
 * Inputs: Deployment-specific values edited by the operator after copying this template.
 * Outputs: Returns a PHP configuration array.
 * Usage: Copy to config/database.php and customize deployment values.
 * Author: Cris Magalang
 * Code Assistants and generators: Codex and Claude code
 */
declare(strict_types=1);

/**
 * Example database configuration.
 *
 * Copy this file to database.php. Values from the process environment take
 * precedence over values loaded from the project-root .env file.
 */
$envFile = dirname(__DIR__) . '/.env';

if (is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if ($name !== '' && getenv($name) === false) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
        }
    }
}

$getEnv = static function (string $name, string $default = ''): string {
    $value = getenv($name);

    return $value === false ? $default : $value;
};

// Read the first environment variable that is set, so both the documented
// short names (DB_NAME, DB_USER) and the longer aliases (DB_DATABASE,
// DB_USERNAME) work interchangeably.
$getEnvAny = static function (array $names, string $default = '') use ($getEnv): string {
    foreach ($names as $name) {
        if (getenv($name) !== false) {
            return $getEnv($name, $default);
        }
    }

    return $default;
};

// Select the storage driver: 'mysql' (default) or 'sqlite'. Each driver's
// settings live in its own block, so switching drivers is a one-line change.
return [
    'driver' => $getEnvAny(['DB_DRIVER'], 'mysql'),

    'mysql' => [
        'host' => $getEnvAny(['DB_HOST'], 'localhost'),
        'port' => $getEnvAny(['DB_PORT'], '3306'),
        'database' => $getEnvAny(['DB_NAME', 'DB_DATABASE'], 'dbo_gateway'),
        'username' => $getEnvAny(['DB_USER', 'DB_USERNAME'], 'root'),
        'password' => $getEnvAny(['DB_PASSWORD']),
        'charset' => $getEnvAny(['DB_CHARSET'], 'utf8mb4'),
    ],

    // For driver=sqlite. Keep the file OUTSIDE the web root and 0600.
    'sqlite' => [
        'path' => $getEnvAny(['DB_SQLITE_PATH'], dirname(__DIR__) . '/storage/gateway.sqlite'),
    ],
];
