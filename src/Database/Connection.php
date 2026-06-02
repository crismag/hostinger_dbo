<?php

/**
 * @file Connection.php
 *
 * Creates the shared PDO connection from runtime database configuration.
 *
 * Creation Date: 2026-06-02
 * Inputs: Constructor dependencies and typed method arguments supplied by the application.
 * Outputs: Typed return values, domain exceptions, or persisted side effects documented by each method.
 * Usage: Loaded through the App\ namespace autoloader and instantiated by the gateway composition root.
 * Author: Cris Magalang
 * Code Assistants and generators: Codex and Claude code
 */
declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Creates and reuses the PDO connection for the current PHP request.
 */
final class Connection
{
    private static ?PDO $instance = null;

    private function __construct()
    {
    }

    /**
     * Return the shared PDO connection.
     */
    public static function getInstance(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $configFile = dirname(__DIR__, 2) . '/config/database.php';

        if (!is_readable($configFile)) {
            throw new RuntimeException(
                'Database configuration not found. Copy config/database.example.php to config/database.php.'
            );
        }

        /** @var array{host:string,port:string,database:string,username:string,password:string,charset:string} $config */
        $config = require $configFile;
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        try {
            self::$instance = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('Unable to connect to the database.', 0, $exception);
        }

        return self::$instance;
    }

    /**
     * Forget the shared connection, primarily for tests or configuration changes.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
