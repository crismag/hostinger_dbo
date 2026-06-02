<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditLogService;
use Closure;
use Throwable;

/** Audits successful and failed pipeline executions without logging body data. */
final class AuditMiddleware
{
    public function __construct(private readonly AuditLogService $auditLogs)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        try {
            $response = $next($request);
            $this->write($request, $response->statusCode, true, null, $started);

            return $response;
        } catch (Throwable $exception) {
            $status = $exception instanceof ApiException ? $exception->statusCode : 500;
            $code = $exception instanceof ApiException ? $exception->errorCode : 'INTERNAL_ERROR';
            $this->write($request, $status, false, $code, $started);
            throw $exception;
        }
    }

    private function write(Request $request, int $status, bool $success, ?string $error, int $started): void
    {
        $this->auditLogs->write($request, $status, $success, $error, (int) ((hrtime(true) - $started) / 1_000_000));
    }
}
