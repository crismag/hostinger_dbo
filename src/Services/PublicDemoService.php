<?php

/**
 * @file PublicDemoService.php
 *
 * Constrains optional unauthenticated demo reads to explicitly configured entities, fields, filters, and limits.
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
use App\Security\FilesystemRateLimiter;

/** Constrained, unauthenticated access to explicitly defined demo interfaces; disabled by default. */
final class PublicDemoService
{
    /** @param array<string, mixed> $config the public_demo configuration block */
    public function __construct(
        private readonly array $config,
        private readonly FilesystemRateLimiter $limiter,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    public function rateLimit(?string $ip): void
    {
        $limits = [];
        foreach (['minute' => 'per_minute', 'hour' => 'per_hour', 'day' => 'per_day'] as $window => $key) {
            if (isset($this->config['rate_limit'][$key])) {
                $limits[$window] = (int) $this->config['rate_limit'][$key];
            }
        }
        if ($limits !== [] && !$this->limiter->allow('demo:' . ($ip ?? 'unknown'), $limits)) {
            throw new ApiException('RATE_LIMITED', 'Too many requests', 429);
        }
    }

    /**
     * Builds the hard-constrained request body for a demo interface, or denies access.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed> body to hand to RequestValidator
     */
    public function constrain(string $entity, string $action, array $body): array
    {
        $rules = $this->config['permissions'][$entity][$action] ?? null;
        if (!is_array($rules) || !($rules['enabled'] ?? false)) {
            throw new ApiException('PERMISSION_DENIED', 'Demo access is not available for this operation', 403);
        }
        if ($action !== 'select') {
            // Demo callers are read-only; mutations are never exposed anonymously.
            throw new ApiException('PERMISSION_DENIED', 'Demo access is limited to select', 403);
        }

        $allowedFields = $rules['allowed_fields'] ?? null;
        if (!is_array($allowedFields) || $allowedFields === []) {
            // Fail closed: demo interfaces must explicitly declare what can be exposed.
            throw new ApiException('PERMISSION_DENIED', 'Demo access is not available for this operation', 403);
        }
        $fields = (isset($body['fields']) && is_array($body['fields'])) ? $body['fields'] : $allowedFields;
        foreach ($fields as $field) {
            if (!in_array($field, $allowedFields, true)) {
                throw new ApiException('REQUEST_FIELD_NOT_ALLOWED', 'Demo field is not allowed: ' . (string) $field, 403);
            }
        }

        $where = (isset($body['where']) && is_array($body['where'])) ? $body['where'] : [];
        $allowedFilters = $rules['allowed_filters'] ?? null;
        if (!is_array($allowedFilters)) {
            $allowedFilters = [];
        }
        foreach (array_keys($where) as $field) {
            if (!in_array($field, $allowedFilters, true)) {
                throw new ApiException('REQUEST_FIELD_NOT_ALLOWED', 'Demo filter is not allowed: ' . (string) $field, 403);
            }
        }
        // Server-mandated filters always win; the caller cannot remove or override them.
        if (isset($rules['required_where']) && is_array($rules['required_where'])) {
            foreach ($rules['required_where'] as $field => $value) {
                $where[$field] = $value;
            }
        }

        $maxLimit = (int) ($rules['max_limit'] ?? 5);
        $requested = filter_var($body['limit'] ?? $maxLimit, FILTER_VALIDATE_INT);
        $limit = ($requested === false || $requested < 1) ? $maxLimit : min($requested, $maxLimit);

        $constrained = ['where' => $where, 'limit' => $limit, 'offset' => 0];
        if ($fields !== []) {
            $constrained['fields'] = $fields;
        }

        return $constrained;
    }
}
