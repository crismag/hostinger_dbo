<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use Closure;

/** Requires JSON and rejects oversized bodies before decoding. */
final class JsonBodyLimitMiddleware
{
    public function __construct(private readonly int $maxBytes = 65536)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $declaredLength = $request->header('content-length');
        if (($declaredLength !== null && (int) $declaredLength > $this->maxBytes)
            || strlen($request->rawBody) > $this->maxBytes) {
            throw new ApiException('REQUEST_BODY_TOO_LARGE', 'Request body exceeds the configured limit', 413);
        }
        $contentType = strtolower((string) $request->header('content-type'));
        if (!str_starts_with($contentType, 'application/json')) {
            throw new ApiException('REQUEST_CONTENT_TYPE_INVALID', 'Content-Type must be application/json', 400);
        }
        $request->json();

        return $next($request);
    }
}
