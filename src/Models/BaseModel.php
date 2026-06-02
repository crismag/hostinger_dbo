<?php

/**
 * @file BaseModel.php
 *
 * Represents an immutable database result row that can be serialized into a JSON response.
 *
 * Creation Date: 2026-06-02
 * Inputs: Constructor dependencies and typed method arguments supplied by the application.
 * Outputs: Typed return values, domain exceptions, or persisted side effects documented by each method.
 * Usage: Loaded through the App\ namespace autoloader and instantiated by the gateway composition root.
 * Author: Cris Magalang
 * Code Assistants and generators: Codex and Claude code
 */
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
