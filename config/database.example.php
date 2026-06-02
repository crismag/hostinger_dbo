<?php

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

return [
    'host' => $getEnv('DB_HOST', 'localhost'),
    'port' => $getEnv('DB_PORT', '3306'),
    'database' => $getEnv('DB_DATABASE', 'dbo_gateway'),
    'username' => $getEnv('DB_USERNAME', 'root'),
    'password' => $getEnv('DB_PASSWORD'),
    'charset' => $getEnv('DB_CHARSET', 'utf8mb4'),
];
