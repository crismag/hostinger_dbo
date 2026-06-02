<?php

/**
 * @file SignatureVerifier.php
 *
 * Builds canonical request strings and performs timing-safe HMAC-SHA256 signature verification.
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

/** Builds and verifies the documented HMAC-SHA256 request signature. */
final class SignatureVerifier
{
    /** Produces the newline-delimited value covered by the client signature. */
    public function canonical(string $method, string $path, string $timestamp, string $nonce, string $rawBody): string
    {
        return implode("\n", [$method, $path, $timestamp, $nonce, hash('sha256', $rawBody)]);
    }

    /** Compares a provided hexadecimal signature with the expected HMAC in constant time. */
    public function verify(string $signature, string $secret, string $canonical): bool
    {
        return preg_match('/^[a-f0-9]{64}$/i', $signature) === 1
            && hash_equals(hash_hmac('sha256', $canonical, $secret), strtolower($signature));
    }
}
