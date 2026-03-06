<?php

namespace RiviumTrace\Laravel\Models;

use RiviumTrace\Laravel\Config\RiviumTraceConfig;

class RiviumTraceError
{
    public string $message;
    public string $stackTrace;
    public string $platform = 'laravel';
    public string $environment;
    public string $release;
    public string $timestamp;
    public array $extra;
    public string $userAgent;
    public string $url;

    public static function fromThrowable(\Throwable $e, array $opts = []): self
    {
        $instance = new self();
        $instance->message = mb_substr($e->getMessage() ?: get_class($e), 0, 1000);
        $instance->stackTrace = $e->getTraceAsString();
        $instance->environment = $opts['environment'] ?? 'production';
        $instance->release = $opts['release'] ?? '0.1.0';
        $instance->timestamp = self::now();
        $instance->extra = $opts['extra'] ?? [];
        $instance->userAgent = self::buildUserAgent();
        $instance->url = $opts['url'] ?? self::resolveUrl();

        return $instance;
    }

    public static function fromMessage(string $msg, array $opts = []): self
    {
        $instance = new self();
        $instance->message = mb_substr($msg, 0, 1000);
        $instance->stackTrace = (new \Exception())->getTraceAsString();
        $instance->environment = $opts['environment'] ?? 'production';
        $instance->release = $opts['release'] ?? '0.1.0';
        $instance->timestamp = self::now();
        $instance->extra = $opts['extra'] ?? [];
        $instance->userAgent = self::buildUserAgent();
        $instance->url = $opts['url'] ?? self::resolveUrl();

        return $instance;
    }

    public function addLaravelContext(): self
    {
        $ctx = [
            'php_version' => PHP_VERSION,
            'platform' => PHP_OS,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'cli',
            'memory_peak' => memory_get_peak_usage(true),
            'sapi' => PHP_SAPI,
        ];

        try {
            $ctx['laravel_version'] = app()->version();
        } catch (\Throwable) {
        }

        $this->extra['laravel_context'] = $ctx;
        return $this;
    }

    public function setRequestContext(\Illuminate\Http\Request $request): self
    {
        $this->extra['request'] = [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'query' => $request->query(),
            'headers' => $this->filterHeaders($request->headers->all()),
        ];
        $this->url = $request->fullUrl();
        $this->userAgent = $request->userAgent() ?: self::buildUserAgent();

        return $this;
    }

    public function setExtra(string $key, mixed $value): self
    {
        $this->extra[$key] = $value;
        return $this;
    }

    public function setExtras(array $extras): self
    {
        $this->extra = array_merge($this->extra, $extras);
        return $this;
    }

    public function toArray(): array
    {
        $data = $this->extra;
        $crumbs = $data['breadcrumbs'] ?? null;
        unset($data['breadcrumbs']);

        return array_filter([
            'message' => $this->message,
            'stack_trace' => $this->stackTrace,
            'platform' => $this->platform,
            'environment' => $this->environment,
            'release_version' => $this->release,
            'timestamp' => $this->timestamp,
            'breadcrumbs' => $crumbs,
            'extra' => ! empty($data) ? $data : null,
            'user_agent' => $this->userAgent,
            'url' => $this->url,
        ], fn ($val) => $val !== null);
    }

    private static function now(): string
    {
        try {
            return now()->toISOString();
        } catch (\Throwable) {
            return (new \DateTimeImmutable())->format('c');
        }
    }

    private static function resolveUrl(): string
    {
        try {
            $req = request();
            return $req ? $req->fullUrl() : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private static function buildUserAgent(): string
    {
        return sprintf(
            'RiviumTrace-SDK/%s (laravel; %s; PHP %s)',
            RiviumTraceConfig::SDK_VERSION,
            PHP_OS,
            PHP_VERSION
        );
    }

    private function filterHeaders(array $headers): array
    {
        $blocked = ['authorization', 'cookie', 'x-api-key', 'x-server-secret'];
        $out = [];

        foreach ($headers as $name => $vals) {
            $out[$name] = in_array(strtolower($name), $blocked, true)
                ? ['[REDACTED]']
                : $vals;
        }

        return $out;
    }
}
