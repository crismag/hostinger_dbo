<?php

/**
 * Demo BFF endpoint. The browser calls this same-origin; it maps UI intents to
 * signed php-dbo-gateway requests and returns both the data and the exact
 * gateway exchange (for the teaching panel). No HMAC secret ever leaves here.
 */

declare(strict_types=1);

header('Content-Type: application/json');

use Demo\TicketDesk\GatewayClient;

/** @var GatewayClient $gateway */
$gateway = require dirname(__DIR__) . '/src/bootstrap.php';

$op = (string) ($_GET['op'] ?? '');
$input = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($input)) {
    $input = [];
}

/** @param array<string,mixed> $exchange */
function respond(array $data, array $exchange): never
{
    echo json_encode(['data' => $data, 'gateway' => $exchange], JSON_UNESCAPED_SLASHES);
    exit;
}
/** @param array<string,mixed> $exchange @return list<array<string,mixed>> */
function rows(array $exchange): array
{
    return $exchange['response']['data'] ?? [];
}

try {
    switch ($op) {
        case 'lookups':
            $customers = $gateway->object('customers', 'select', ['fields' => ['id', 'name'], 'limit' => 200, 'order_by' => 'name', 'order_dir' => 'asc']);
            $agents = $gateway->object('agents', 'select', ['fields' => ['id', 'name'], 'limit' => 200, 'order_by' => 'name', 'order_dir' => 'asc']);
            respond(['customers' => rows($customers), 'agents' => rows($agents)], $agents);
            // no break (respond exits)

        case 'dashboard':
            $byStatus = $gateway->object('tickets', 'select', ['group_by' => ['status'], 'aggregates' => [['fn' => 'count', 'field' => 'id', 'as' => 'n']], 'order_by' => 'status']);
            $byPriority = $gateway->object('tickets', 'select', ['group_by' => ['priority'], 'aggregates' => [['fn' => 'count', 'field' => 'id', 'as' => 'n']]]);
            respond(['by_status' => rows($byStatus), 'by_priority' => rows($byPriority)], $byStatus);

        case 'list':
            $body = [
                'fields' => ['id', 'customer_id', 'agent_id', 'subject', 'body', 'status', 'priority', 'created_at'],
                'limit' => max(1, min(50, (int) ($input['limit'] ?? 10))),
                'offset' => max(0, (int) ($input['offset'] ?? 0)),
            ];
            $where = [];
            if (!empty($input['status'])) {
                $where['status'] = (string) $input['status'];
            }
            if (!empty($input['priority'])) {
                $where['priority'] = (string) $input['priority'];
            }
            if ($where !== []) {
                $body['where'] = $where;
            }
            if (!empty($input['search'])) {
                $body['filters'] = [['field' => 'subject', 'op' => 'like', 'value' => '%' . $input['search'] . '%']];
            }
            $body['order_by'] = in_array($input['order_by'] ?? '', ['id', 'created_at', 'priority'], true) ? $input['order_by'] : 'created_at';
            $body['order_dir'] = ($input['order_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            $exchange = $gateway->object('tickets', 'select', $body);
            respond(['tickets' => rows($exchange)], $exchange);

        case 'create':
            $data = [];
            foreach (['subject', 'body', 'status', 'priority'] as $k) {
                if (isset($input[$k]) && $input[$k] !== '') {
                    $data[$k] = (string) $input[$k];
                }
            }
            foreach (['customer_id', 'agent_id'] as $k) {
                if (!empty($input[$k])) {
                    $data[$k] = (int) $input[$k];
                }
            }
            $exchange = $gateway->object('tickets', 'insert', ['data' => $data]);
            respond(['result' => $exchange['response']['data'] ?? null], $exchange);

        case 'update':
            $data = [];
            foreach (['subject', 'body', 'status', 'priority'] as $k) {
                if (isset($input[$k]) && $input[$k] !== '') {
                    $data[$k] = (string) $input[$k];
                }
            }
            if (!empty($input['agent_id'])) {
                $data['agent_id'] = (int) $input['agent_id'];
            }
            $exchange = $gateway->object('tickets', 'update', ['where' => ['id' => (int) ($input['id'] ?? 0)], 'data' => $data]);
            respond(['result' => $exchange['response']['data'] ?? null], $exchange);

        case 'delete':
            $exchange = $gateway->object('tickets', 'delete', ['where' => ['id' => (int) ($input['id'] ?? 0)]]);
            respond(['result' => $exchange['response']['data'] ?? null], $exchange);

        case 'agent_workload':
            // 4b: a JOIN+aggregate report via a service operation.
            $exchange = $gateway->service('tickets', 'agent_workload', []);
            respond(['agents' => rows($exchange)], $exchange);

        case 'create_with_comment':
            // 4b: transactional create (ticket + first comment) via a service operation.
            $payload = [];
            foreach (['subject', 'body', 'priority', 'comment'] as $k) {
                if (isset($input[$k]) && $input[$k] !== '') {
                    $payload[$k] = (string) $input[$k];
                }
            }
            foreach (['customer_id', 'agent_id'] as $k) {
                if (!empty($input[$k])) {
                    $payload[$k] = (int) $input[$k];
                }
            }
            $exchange = $gateway->service('tickets', 'create_with_comment', $payload);
            respond(['result' => $exchange['response']['data'] ?? null], $exchange);

        default:
            http_response_code(404);
            echo json_encode(['error' => 'unknown op']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
