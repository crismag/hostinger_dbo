<?php

/**
 * @file AuditLogService.php
 *
 * Stores request audit metadata according to the configured audit mode without persisting secrets or bodies.
 *
 * Creation Date: 2026-06-02
 * Inputs: Constructor dependencies and typed method arguments supplied by the application.
 * Outputs: Typed return values, domain exceptions, or persisted side effects documented by each method.
 * Usage: Loaded through the App\ namespace autoloader and instantiated by the gateway composition root.
 * Author: Cris Magalang
 * Code Assistants and generators: Codex and Claude code
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\Request;
use PDO;
use Throwable;

/** Writes request metadata and hashes without storing secrets or full bodies. */
final class AuditLogService
{
    /** @param array{mode?:string,sample_rate?:int} $config */
    public function __construct(
        private readonly PDO $database,
        private readonly array $config = ['mode' => 'authenticated_only'],
    ) {
    }

    public function write(Request $request, int $status, bool $success, ?string $errorCode, int $durationMs): void
    {
        if (!$this->shouldWrite($request, $success)) {
            return;
        }
        try {
            $client = $request->attribute('client');
            $statement = $this->database->prepare(
                'INSERT INTO `api_audit_logs` (`request_id`, `client_id`, `entity_name`, `action_name`, `request_method`, '
                . '`request_path`, `request_hash`, `ip_address`, `status_code`, `success`, `error_code`, `duration_ms`) '
                . 'VALUES (:request_id, :client_id, :entity, :action, :method, :path, :hash, :ip, :status, :success, :error, :duration)'
            );
            $statement->execute([
                'request_id' => $request->attribute('request_id'),
                'client_id' => is_array($client) ? $client['id'] : null,
                'entity' => $request->attribute('entity'),
                'action' => $request->attribute('action'),
                'method' => $request->method,
                'path' => $request->path,
                'hash' => hash('sha256', $request->rawBody),
                'ip' => $request->ipAddress,
                'status' => $status,
                'success' => $success ? 1 : 0,
                'error' => $errorCode,
                'duration' => $durationMs,
            ]);
        } catch (Throwable $exception) {
            error_log('Unable to write API audit log: ' . $exception->getMessage());
        }
    }

    private function shouldWrite(Request $request, bool $success): bool
    {
        $identified = is_array($request->attribute('client'));

        try {
            return match ($this->config['mode'] ?? 'all') {
                'authenticated_only' => $identified,
                'critical_only' => !$success,
                'sampled' => $success || random_int(1, max(1, (int) ($this->config['sample_rate'] ?? 10))) === 1,
                default => true,
            };
        } catch (Throwable) {
            // If randomness fails, avoid breaking the request pipeline.
            return !$success;
        }
    }
}
