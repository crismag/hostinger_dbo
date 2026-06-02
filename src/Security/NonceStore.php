<?php

declare(strict_types=1);

namespace App\Security;

use DateTimeImmutable;
use PDO;
use PDOException;

/** Stores accepted nonces; the unique key provides atomic replay rejection. */
final class NonceStore
{
    public function __construct(private readonly PDO $database, private readonly int $windowSeconds = 300)
    {
    }

    public function claim(int $clientId, string $nonce, DateTimeImmutable $timestamp): bool
    {
        $statement = $this->database->prepare(
            'INSERT INTO `api_nonces` (`client_id`, `nonce`, `expires_at`) VALUES (:client_id, :nonce, :expires_at)'
        );
        try {
            return $statement->execute([
                'client_id' => $clientId,
                'nonce' => $nonce,
                'expires_at' => $timestamp->modify('+' . $this->windowSeconds . ' seconds')->format('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return false;
            }
            throw $exception;
        }
    }
}
