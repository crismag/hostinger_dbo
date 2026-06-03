<?php

declare(strict_types=1);

namespace Demo\TicketDesk;

/**
 * Signs HMAC requests to php-dbo-gateway and forwards them over HTTP. This runs
 * server-side in the demo's BFF — the HMAC secret never reaches the browser.
 * Each call returns the signed request and the gateway's response so the UI can
 * display the exact exchange.
 */
final class GatewayClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $secret,
    ) {
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function object(string $entity, string $action, array $body): array
    {
        return $this->send('/api/v1/' . $entity . '/' . $action, $body);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function service(string $service, string $operation, array $body): array
    {
        return $this->send('/api/v1/services/' . $service . '/' . $operation, $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{request:array<string,mixed>,status:int,response:mixed}
     */
    private function send(string $path, array $body): array
    {
        // Cast to object so an empty body serializes as {} (a JSON object), not [].
        $raw = json_encode((object) $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $nonce = bin2hex(random_bytes(12));
        $canonical = implode("\n", ['POST', $path, $timestamp, $nonce, hash('sha256', (string) $raw)]);
        $signature = hash_hmac('sha256', $canonical, $this->secret);

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $raw,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Client-Id: ' . $this->clientId,
                'X-Timestamp: ' . $timestamp,
                'X-Nonce: ' . $nonce,
                'X-Signature: ' . $signature,
            ],
        ]);
        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $decoded = is_string($responseBody) ? json_decode($responseBody, true) : null;
        if ($decoded === null) {
            $decoded = ['ok' => false, 'error' => ['code' => 'GATEWAY_UNREACHABLE', 'message' => $error !== '' ? $error : 'No response from gateway']];
            $status = $status ?: 502;
        }

        return [
            // The signed request, shown in the UI's teaching panel (no secret here).
            'request' => ['method' => 'POST', 'path' => $path, 'body' => $body],
            'status' => $status,
            'response' => $decoded,
        ];
    }
}
