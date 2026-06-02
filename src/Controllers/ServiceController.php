<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Operations\OperationRegistry;
use App\Services\Operations\ServiceContext;
use PDO;

/**
 * Terminal handler for named service operations (`/api/v1/services/{service}/{operation}`).
 * Runs behind the same HMAC/nonce/rate-limit/audit pipeline as object operations,
 * but resolves an allowlisted handler, checks the client's service grant, and
 * validates input against the handler's spec before executing developer code.
 */
final class ServiceController
{
    /**
     * @param array<string, array<string, array{handler:string}>> $servicesConfig config/services.php
     * @param array<string, array<string, mixed>> $clientsConfig security['clients']
     */
    public function __construct(
        private readonly OperationRegistry $registry,
        private readonly array $servicesConfig,
        private readonly array $clientsConfig,
        private readonly PDO $database,
    ) {
    }

    public function handle(Request $request): Response
    {
        $service = (string) $request->attribute('service');
        $operation = (string) $request->attribute('operation');
        /** @var array{id:int,client_id:string,secret:string} $client */
        $client = $request->attribute('client');

        $ops = $this->servicesConfig[$service] ?? null;
        if (!is_array($ops)) {
            throw new ApiException('SERVICE_NOT_FOUND', 'No such service', 404);
        }
        $opDef = $ops[$operation] ?? null;
        if (!is_array($opDef) || !isset($opDef['handler'])) {
            throw new ApiException('SERVICE_OPERATION_NOT_FOUND', 'No such service operation', 404);
        }
        $operationKey = (string) $opDef['handler'];

        // Authorization: the client must be granted this operation key.
        $granted = (array) ($this->clientsConfig[$client['client_id']]['services'] ?? []);
        if (!in_array($operationKey, $granted, true)) {
            throw new ApiException('PERMISSION_DENIED', 'Client is not allowed to perform this operation', 403);
        }

        // Resolve the handler from the fixed allowlist and validate caller input.
        $handler = $this->registry->resolve($operationKey);
        $input = $this->validateInput($request->json(), $handler->inputSpec());

        $enforced = (array) ($this->clientsConfig[$client['client_id']]['enforced_filters'] ?? []);
        $data = $handler->execute($input, new ServiceContext($this->database, $client, $enforced));

        $count = is_array($data) && array_is_list($data) ? count($data) : 1;
        return Response::success($data, (string) $request->attribute('request_id'), 200, [
            'operation' => $service . '/' . $operation,
            'service' => $service,
            'count' => $count,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, array{type:string,required?:bool,min?:int,max?:int}> $spec
     * @return array<string, mixed>
     */
    private function validateInput(array $input, array $spec): array
    {
        foreach (array_keys($input) as $key) {
            if (!isset($spec[$key])) {
                throw new ApiException('SERVICE_INPUT_INVALID', 'Unknown input field: ' . (string) $key, 400);
            }
        }
        $out = [];
        foreach ($spec as $field => $rule) {
            if (!array_key_exists($field, $input)) {
                if ($rule['required'] ?? false) {
                    throw new ApiException('SERVICE_INPUT_INVALID', 'Missing required field: ' . $field, 400);
                }
                continue;
            }
            $value = $input[$field];
            if (is_array($value)) {
                throw new ApiException('SERVICE_INPUT_INVALID', $field . ' must be a scalar', 400);
            }
            $type = $rule['type'] ?? 'string';
            if ($type === 'int') {
                $int = filter_var($value, FILTER_VALIDATE_INT);
                if ($int === false) {
                    throw new ApiException('SERVICE_INPUT_INVALID', $field . ' must be an integer', 400);
                }
                if (isset($rule['min']) && $int < $rule['min']) {
                    throw new ApiException('SERVICE_INPUT_INVALID', $field . ' is below the minimum', 400);
                }
                if (isset($rule['max']) && $int > $rule['max']) {
                    throw new ApiException('SERVICE_INPUT_INVALID', $field . ' is above the maximum', 400);
                }
                $out[$field] = $int;
            } elseif ($type === 'bool') {
                $out[$field] = (bool) $value;
            } else {
                $out[$field] = (string) $value;
            }
        }
        return $out;
    }
}
