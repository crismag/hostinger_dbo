<?php

/**
 * @file HmacAuth.php
 *
 * Authenticates API requests by validating signed headers, timestamp freshness, client configuration, and the HMAC signature.
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

use App\Core\ApiException;
use App\Core\Request;
use DateTimeImmutable;
use DateTimeZone;

/** Validates required headers, timestamp skew, client status, IP, and signature. */
final class HmacAuth
{
    public function __construct(
        private readonly ApiClientResolver $clients,
        private readonly SignatureVerifier $signatures,
        private readonly int $timestampWindowSeconds = 300,
    ) {
    }

    /**
     * Authenticates one signed request and stores replay-protection attributes on it.
     *
     * @return array{id:int,client_id:string,secret:string}
     */
    public function authenticate(Request $request): array
    {
        $clientId = $this->required($request, 'x-client-id');
        $timestamp = $this->required($request, 'x-timestamp');
        $nonce = $this->required($request, 'x-nonce');
        $signature = $this->required($request, 'x-signature');
        if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/', $nonce)) {
            throw new ApiException('AUTH_NONCE_INVALID', 'Nonce format is invalid', 401);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $timestamp, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $timestamp) {
            throw new ApiException('AUTH_TIMESTAMP_INVALID', 'Timestamp must use UTC ISO-8601 format', 401);
        }
        if (abs(time() - $date->getTimestamp()) > $this->timestampWindowSeconds) {
            throw new ApiException('AUTH_TIMESTAMP_EXPIRED', 'Timestamp is outside the allowed window', 401);
        }
        $client = $this->clients->resolve($clientId, $request->ipAddress);
        $canonical = $this->signatures->canonical($request->method, $request->path, $timestamp, $nonce, $request->rawBody);
        if (!$this->signatures->verify($signature, $client['secret'], $canonical)) {
            throw new ApiException('AUTH_INVALID_SIGNATURE', 'Invalid request signature', 401);
        }
        $request->setAttribute('nonce', $nonce);
        $request->setAttribute('timestamp', $date);

        return $client;
    }

    private function required(Request $request, string $header): string
    {
        $value = $request->header($header);
        if ($value === null || trim($value) === '') {
            throw new ApiException('AUTH_HEADER_MISSING', 'Missing required header: ' . $header, 401);
        }

        return trim($value);
    }
}
