<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Config;

/**
 * Audit service endpoint and producer identity.
 */
final readonly class HttpConfig
{
    public string $baseUrl;

    /**
     * @param string $baseUrl     audit service base URL, e.g. "https://audit.example.com"
     * @param string $serviceName producer service name sent in every event, e.g. "getnative"
     */
    public function __construct(string $baseUrl, public string $serviceName)
    {
        if ($baseUrl === '') {
            throw new \InvalidArgumentException('audit http: base URL is required');
        }

        $parsed = parse_url($baseUrl);
        if ($parsed === false) {
            throw new \InvalidArgumentException('audit http: invalid base URL');
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException(
                sprintf('audit http: base URL scheme must be http or https, got "%s"', $scheme),
            );
        }
        if (($parsed['host'] ?? '') === '') {
            throw new \InvalidArgumentException('audit http: base URL must include a host');
        }

        if ($serviceName === '') {
            throw new \InvalidArgumentException('audit http: service name is required');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function batchUrl(): string
    {
        return $this->baseUrl . '/api/v1/events/batch';
    }

    public function healthUrl(): string
    {
        return $this->baseUrl . '/health/liveness';
    }

    public function isPlaintext(): bool
    {
        return str_starts_with(strtolower($this->baseUrl), 'http://');
    }
}
