<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\ApiException;
use PDO;

/** Resolves active API clients and isolates MVP secret storage behind one class. */
final class ApiClientResolver
{
    /** @param array<string, string> $configuredSecrets */
    public function __construct(
        private readonly PDO $database,
        private readonly array $configuredSecrets,
        private readonly bool $allowDatabaseSecrets = false,
    ) {
    }

    /** @return array{id:int,client_id:string,secret:string} */
    public function resolve(string $clientId, ?string $ipAddress): array
    {
        $statement = $this->database->prepare(
            'SELECT `id`, `client_id`, `status`, `secret_hash` AS `secret_value`, `allowed_ips` FROM `api_clients` WHERE `client_id` = :client_id LIMIT 1'
        );
        $statement->execute(['client_id' => $clientId]);
        $client = $statement->fetch();
        if (!is_array($client) || $client['status'] !== 'active') {
            throw new ApiException('AUTH_CLIENT_INVALID', 'Unknown or inactive API client', 401);
        }
        $this->assertAllowedIp($client['allowed_ips'] ?? null, $ipAddress);
        $secret = $this->configuredSecrets[$clientId] ?? null;
        if ($secret === null && $this->allowDatabaseSecrets) {
            $secret = (string) $client['secret_value'];
        }
        if (!is_string($secret) || $secret === '') {
            throw new ApiException('AUTH_SECRET_UNAVAILABLE', 'API client secret is unavailable', 401);
        }

        return ['id' => (int) $client['id'], 'client_id' => (string) $client['client_id'], 'secret' => $secret];
    }

    private function assertAllowedIp(mixed $allowedIps, ?string $ipAddress): void
    {
        if (!is_string($allowedIps) || trim($allowedIps) === '') {
            return;
        }
        $ips = preg_split('/[\s,]+/', trim($allowedIps)) ?: [];
        if ($ipAddress === null || !in_array($ipAddress, $ips, true)) {
            throw new ApiException('AUTH_IP_NOT_ALLOWED', 'Client IP address is not allowed', 403);
        }
    }
}
