<?php

namespace RiviumTrace\Laravel\Config;

use InvalidArgumentException;

class RiviumTraceConfig
{
    const SDK_VERSION = '0.1.0';
    const PLATFORM = 'laravel';

    public readonly string $apiKey;
    public readonly string $serverSecret;
    public readonly string $apiUrl;
    public readonly string $environment;
    public readonly string $release;
    public readonly bool $enabled;
    public readonly bool $debug;
    public readonly int $timeout;
    public readonly int $maxBreadcrumbs;
    public readonly float $sampleRate;
    public readonly array $rateLimiting;
    public readonly array $logging;
    public readonly array $performance;
    public readonly array $middleware;
    public readonly array $exceptionHandler;

    public function __construct(array $config)
    {
        $this->apiKey = $config['api_key'] ?? '';
        $this->serverSecret = $config['server_secret'] ?? '';
        $this->apiUrl = rtrim($config['api_url'] ?? 'https://trace.rivium.co', '/');
        $this->environment = $config['environment'] ?? 'production';
        $this->release = $config['release'] ?? '0.1.0';
        $this->enabled = (bool) ($config['enabled'] ?? true);
        $this->debug = (bool) ($config['debug'] ?? false);
        $this->timeout = max(1, min(30, (int) ($config['timeout'] ?? 5)));
        $this->maxBreadcrumbs = max(0, min(100, (int) ($config['max_breadcrumbs'] ?? 50)));
        $this->sampleRate = max(0.0, min(1.0, (float) ($config['sample_rate'] ?? 1.0)));
        $this->rateLimiting = $config['rate_limiting'] ?? [];
        $this->logging = $config['logging'] ?? [];
        $this->performance = $config['performance'] ?? [];
        $this->middleware = $config['middleware'] ?? [];
        $this->exceptionHandler = $config['exception_handler'] ?? [];

        if ($this->enabled && $this->apiKey !== '' && $this->serverSecret !== '') {
            $this->validate();
        }
    }

    public function validate(): void
    {
        if (! str_starts_with($this->apiKey, 'rv_live_') && ! str_starts_with($this->apiKey, 'rv_test_')) {
            throw new InvalidArgumentException('API key must start with rv_live_ or rv_test_');
        }

        if (! str_starts_with($this->serverSecret, 'rv_srv_')) {
            throw new InvalidArgumentException('Server secret must start with rv_srv_');
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->apiKey !== '' && $this->serverSecret !== '';
    }

    public function getErrorEndpoint(): string
    {
        return $this->apiUrl . '/api/errors';
    }

    public function getLogIngestEndpoint(): string
    {
        return $this->apiUrl . '/api/logs/ingest';
    }

    public function getLogBatchEndpoint(): string
    {
        return $this->apiUrl . '/api/logs/ingest/batch';
    }

    public function getPerformanceBatchEndpoint(): string
    {
        return $this->apiUrl . '/api/performance/spans/batch';
    }

    public function getUserAgent(): string
    {
        return sprintf(
            'RiviumTrace-SDK/%s (laravel; %s; PHP %s)',
            self::SDK_VERSION,
            PHP_OS,
            PHP_VERSION
        );
    }
}
