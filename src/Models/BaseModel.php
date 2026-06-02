<?php

declare(strict_types=1);

namespace App\Models;

use JsonSerializable;

/** Small immutable JSON-serializable database row object. */
final class BaseModel implements JsonSerializable
{
    /** @param array<string, mixed> $attributes */
    public function __construct(private readonly array $attributes)
    {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->attributes;
    }
}
