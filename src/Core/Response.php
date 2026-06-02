<?php

declare(strict_types=1);

namespace App\Core;

/** JSON response envelope shared by successful and failed operations. */
final class Response
{
    /** @param array<string, mixed> $payload */
    public function __construct(public readonly array $payload, public readonly int $statusCode = 200)
    {
    }

    public static function success(mixed $data, string $requestId, int $status = 200): self
    {
        return new self(['ok' => true, 'data' => $data, 'meta' => ['request_id' => $requestId]], $status);
    }

    public static function error(ApiException $error, string $requestId): self
    {
        return new self([
            'ok' => false,
            'error' => ['code' => $error->errorCode, 'message' => $error->getMessage()],
            'meta' => ['request_id' => $requestId],
        ], $error->statusCode);
    }

    public function emit(): never
    {
        http_response_code($this->statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
