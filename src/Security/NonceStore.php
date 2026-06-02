<?php

/**
 * @file NonceStore.php
 *
 * Persists accepted client nonces so a signed request cannot be replayed inside the authentication window.
 *
 * Creation Date: 2026-06-02
 * Inputs: Constructor dependencies and typed method arguments supplied by the application.
 * Outputs: Typed return values, domain exceptions, or persisted side effects documented by each method.
 * Usage: Loaded through the App\ namespace autoloader and instantiated by the gateway composition root.
 * Author: Cris Magalang
 * Code Assistants and generators: Codex and Claude code
 */
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

    /**
     * Attempts to reserve a nonce for one client.
     *
     * @return bool True for a newly accepted nonce; false when the unique key detects a replay.
     */
    public function claim(int $clientId, string $nonce, DateTimeImmutable $timestamp): bool
    {
        try {
            $cleanup = $this->database->prepare('DELETE FROM `api_nonces` WHERE `client_id` = :client_id AND `expires_at` < :now');
            $cleanup->execute(['client_id' => $clientId, 'now' => $timestamp->format('Y-m-d H:i:s')]);
        } catch (PDOException) {
            // Best-effort cleanup; do not fail the request
        }

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
