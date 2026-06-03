<?php

/**
 * Demo BFF bootstrap: resolves the gateway base URL and the demo client's HMAC
 * secret (read server-side from the gateway's config — never exposed to the
 * browser), and returns a ready GatewayClient.
 */

declare(strict_types=1);

require __DIR__ . '/GatewayClient.php';

use Demo\TicketDesk\GatewayClient;

return (static function (): GatewayClient {
    $repoRoot = dirname(__DIR__, 3);
    $clientId = getenv('GATEWAY_CLIENT_ID') ?: 'ticketdesk-app';
    $baseUrl = rtrim(getenv('GATEWAY_URL') ?: 'http://127.0.0.1:8000', '/');

    $securityFile = $repoRoot . '/config/security.php';
    $secret = '';
    if (is_readable($securityFile)) {
        /** @var array<string, mixed> $security */
        $security = require $securityFile;
        $secret = (string) (($security['client_secrets'] ?? [])[$clientId] ?? '');
    }
    if ($secret === '') {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Demo not set up. Run: php apps/demo-ticketdesk/setup.php']);
        exit;
    }

    return new GatewayClient($baseUrl, $clientId, $secret);
})();
