<?php

declare(strict_types=1);

namespace App\Core;

/** Immutable HTTP input plus middleware attributes. */
final class Request
{
    /** @var array<string, mixed> */
    private array $attributes = [];
    /** @var array<string, mixed>|null */
    private ?array $json = null;

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $rawBody,
        private readonly array $headers,
        public readonly ?string $ipAddress,
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $body = file_get_contents('php://input');

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/',
            $body === false ? '' : $body,
            $headers,
            isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        $decoded = json_decode($this->rawBody, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ApiException('REQUEST_INVALID_JSON', 'Request body must be a JSON object');
        }

        return $this->json = $decoded;
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function attribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }
}
