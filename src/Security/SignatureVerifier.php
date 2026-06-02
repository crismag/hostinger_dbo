<?php

declare(strict_types=1);

namespace App\Security;

/** Builds and verifies the documented HMAC-SHA256 request signature. */
final class SignatureVerifier
{
    public function canonical(string $method, string $path, string $timestamp, string $nonce, string $rawBody): string
    {
        return implode("\n", [$method, $path, $timestamp, $nonce, hash('sha256', $rawBody)]);
    }

    public function verify(string $signature, string $secret, string $canonical): bool
    {
        return preg_match('/^[a-f0-9]{64}$/i', $signature) === 1
            && hash_equals(hash_hmac('sha256', $canonical, $secret), strtolower($signature));
    }
}
