<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/**
 * Loads and validates an application-definition manifest (`app.json`).
 *
 * The manifest is an orchestration *index*: it names the driver, database, and
 * the entities/services an app uses. Full entity policies live in a referenced
 * `data/registry.json`; service names map to the service OperationRegistry.
 * This class validates structure only and never executes anything.
 */
final class AppDefinition
{
    /** @param array<string, mixed> $data */
    private function __construct(
        private readonly array $data,
        private readonly string $dir,
    ) {
    }

    public static function load(string $path): self
    {
        if (!is_readable($path)) {
            throw new RuntimeException('App manifest not readable: ' . $path);
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('App manifest is not a JSON object: ' . $path);
        }
        return self::fromArray($decoded, dirname($path));
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $dir): self
    {
        $def = new self($data, $dir);
        $def->validate();
        return $def;
    }

    private function validate(): void
    {
        $app = (string) ($this->data['app'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $app)) {
            throw new RuntimeException('manifest "app" must match ^[a-z][a-z0-9_-]{0,63}$');
        }
        $driver = (string) ($this->data['driver'] ?? '');
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('manifest "driver" must be mysql or sqlite');
        }
        if ($driver === 'sqlite' && (string) ($this->data['database'] ?? '') === '') {
            throw new RuntimeException('sqlite manifest requires a "database" path');
        }
        foreach ($this->entities() as $entity) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $entity)) {
                throw new RuntimeException('manifest entity name is invalid: ' . $entity);
            }
        }
        foreach ($this->services() as $service) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $service)) {
                throw new RuntimeException('manifest service name is invalid: ' . $service);
            }
        }
    }

    public function app(): string
    {
        return (string) $this->data['app'];
    }

    public function driver(): string
    {
        return (string) $this->data['driver'];
    }

    public function database(): string
    {
        return (string) ($this->data['database'] ?? '');
    }

    /** @return list<string> */
    public function entities(): array
    {
        $list = $this->data['entities'] ?? [];
        return is_array($list) ? array_values(array_map('strval', $list)) : [];
    }

    /** @return list<string> */
    public function services(): array
    {
        $list = $this->data['services'] ?? [];
        return is_array($list) ? array_values(array_map('strval', $list)) : [];
    }

    /** Directory the manifest lives in (for resolving data/registry.json, data/schema.sql). */
    public function dir(): string
    {
        return $this->dir;
    }
}
