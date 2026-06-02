<?php

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
