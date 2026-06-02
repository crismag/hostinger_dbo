<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Request;
use PDO;
use Throwable;

/** Writes request metadata and hashes without storing secrets or full bodies. */
final class AuditLogService
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function write(Request $request, int $status, bool $success, ?string $errorCode, int $durationMs): void
    {
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
}
