<?php

declare(strict_types=1);

namespace App\Core;

/** Immutable HTTP input plus middleware attributes. */
final class Request
{
    /** @var array<string, mixed> */
    private array $attributes = [];
    /** @var array<string, mixed>|null */
    private ?array $json = null;

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $rawBody,
        private readonly array $headers,
        public readonly ?string $ipAddress,
        public readonly bool $secure = false,
    ) {
    }

    /** @param list<string> $trustedProxies */
    public static function fromGlobals(int $maxBodyBytes = 65536, array $trustedProxies = []): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }
        $declaredLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null;
        if ($declaredLength !== null && $declaredLength > $maxBodyBytes) {
            $body = '';
        } else {
            $stream = fopen('php://input', 'rb');
            $body = $stream !== false ? (string) fread($stream, $maxBodyBytes + 1) : '';
            if ($stream !== false) {
                fclose($stream);
            }
        }
        $peerAddress = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null;

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/',
            $body,
            $headers,
            self::resolveClientIpAddress($headers, $peerAddress, $trustedProxies),
            self::detectHttps($headers, $peerAddress, $trustedProxies),
        );
    }

    /**
     * @param array<string, string> $headers
     * @param list<string> $trustedProxies
     */
    private static function detectHttps(array $headers, ?string $peerAddress, array $trustedProxies): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        if (strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https') {
            return true;
        }

        if (!self::isTrustedProxy($peerAddress, $trustedProxies)) {
            return false;
        }

        // Proxy/load-balancer TLS termination, common on shared hosting.
        return strtolower(trim(explode(',', $headers['x-forwarded-proto'] ?? '')[0])) === 'https';
    }

    /**
     * @param array<string, string> $headers
     * @param list<string> $trustedProxies
     */
    private static function resolveClientIpAddress(array $headers, ?string $peerAddress, array $trustedProxies): ?string
    {
        if (!self::isTrustedProxy($peerAddress, $trustedProxies)) {
            return $peerAddress;
        }

        $forwardedFor = array_values(array_filter(
            array_map('trim', explode(',', $headers['x-forwarded-for'] ?? '')),
            static fn (string $ip): bool => $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false
        ));
        for ($index = count($forwardedFor) - 1; $index >= 0; $index--) {
            if (!self::isTrustedProxy($forwardedFor[$index], $trustedProxies)) {
                return $forwardedFor[$index];
            }
        }

        return $peerAddress;
    }

    /**
     * @param list<string> $trustedProxies
     */
    private static function isTrustedProxy(?string $ipAddress, array $trustedProxies): bool
    {
        if ($ipAddress === null || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        foreach ($trustedProxies as $proxy) {
            if (!is_string($proxy) || $proxy === '') {
                continue;
            }
            if (!str_contains($proxy, '/')) {
                if ($ipAddress === $proxy) {
                    return true;
                }
                continue;
            }
            if (self::ipMatchesCidr($ipAddress, $proxy)) {
                return true;
            }
        }

        return false;
    }

    private static function ipMatchesCidr(string $ipAddress, string $cidr): bool
    {
        [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        if ($network === null || $prefix === null || !ctype_digit($prefix)) {
            return false;
        }

        $ip = inet_pton($ipAddress);
        $subnet = inet_pton($network);
        if ($ip === false || $subnet === false || strlen($ip) !== strlen($subnet)) {
            return false;
        }

        $bits = (int) $prefix;
        $maxBits = strlen($ip) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && substr($ip, 0, $fullBytes) !== substr($subnet, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ip[$fullBytes]) & $mask) === (ord($subnet[$fullBytes]) & $mask);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        $decoded = json_decode($this->rawBody, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ApiException('REQUEST_INVALID_JSON', 'Request body must be a JSON object');
        }

        return $this->json = $decoded;
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function attribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }
}
