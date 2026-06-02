<?php

/**
 * @file BaseRepository.php
 *
 * Supplies the shared PDO dependency used by concrete data repositories.
 *
 * Creation Date: 2026-06-02
 * Inputs: Constructor dependencies and typed method arguments supplied by the application.
 * Outputs: Typed return values, domain exceptions, or persisted side effects documented by each method.
 * Usage: Loaded through the App\ namespace autoloader and instantiated by the gateway composition root.
 * Author: Cris Magalang
 * Code Assistants and generators: Codex and Claude code
 */
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Shared PDO holder for explicit gateway repositories. */
abstract class BaseRepository
{
    public function __construct(protected readonly PDO $database)
    {
    }
}
