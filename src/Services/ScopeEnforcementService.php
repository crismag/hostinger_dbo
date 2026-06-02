<?php

/**
 * @file ScopeEnforcementService.php
 *
 * Injects mandatory server-controlled filters, such as tenant identifiers, into validated operations.
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

use App\Core\ApiException;

/** Applies mandatory, server-controlled scope filters (e.g. tenant_id) to every query. */
final class ScopeEnforcementService
{
    /**
     * @param array<string, array{enforced_filters?:array<string, scalar>}> $clientConfig keyed by client_id
     * @param 'reject'|'override' $onViolation
     */
    public function __construct(
        private readonly array $clientConfig,
        private readonly string $onViolation = 'reject',
    ) {
    }

    /**
     * Returns the validated request with the client's enforced scope merged into where/data.
     *
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    public function apply(string $clientId, string $action, array $validated): array
    {
        $client = $this->clientConfig[$clientId] ?? [];
        $filters = $client['enforced_filters'] ?? [];
        if (!is_array($filters) || $filters === []) {
            return $validated;
        }
        $target = match ($action) {
            'select', 'update', 'delete' => 'where',
            'insert' => 'data',
            default => null,
        };
        if ($target === null) {
            return $validated;
        }
        $bag = is_array($validated[$target] ?? null) ? $validated[$target] : [];
        foreach ($filters as $field => $value) {
            if (array_key_exists($field, $bag) && (string) $bag[$field] !== (string) $value && $this->onViolation === 'reject') {
                throw new ApiException('TENANT_SCOPE_VIOLATION', 'Request violates the enforced scope', 403);
            }
            $bag[$field] = $value;
        }
        $validated[$target] = $bag;

        return $validated;
    }
}
