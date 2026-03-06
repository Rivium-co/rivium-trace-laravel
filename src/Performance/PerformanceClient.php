<?php

namespace RiviumTrace\Laravel\Performance;

use RiviumTrace\Laravel\Config\RiviumTraceConfig;
use RiviumTrace\Laravel\Http\HttpClient;

class PerformanceClient
{
    private array $spans = [];
    private int $batchSize;
    private HttpClient $http;
    private RiviumTraceConfig $config;

    public function __construct(RiviumTraceConfig $config, HttpClient $http)
    {
        $this->config = $config;
        $this->http = $http;
        $this->batchSize = $config->performance['batch_size'] ?? 10;
    }

    public function reportSpan(PerformanceSpan $span): void
    {
        if (! $span->environment) {
            $span->environment = $this->config->environment;
        }
        if (! $span->releaseVersion) {
            $span->releaseVersion = $this->config->release;
        }

        $this->spans[] = $span;

        if (count($this->spans) >= $this->batchSize) {
            $this->flush();
        }
    }

    public function trackOperation(string $op, callable $fn, array $opts = []): mixed
    {
        $t0 = microtime(true);

        try {
            $result = $fn();
            $elapsed = (microtime(true) - $t0) * 1000;

            $this->reportSpan(new PerformanceSpan([
                'operation' => $op,
                'operationType' => $opts['operationType'] ?? 'custom',
                'durationMs' => $elapsed,
                'startTime' => now()->subMilliseconds((int) $elapsed)->toISOString(),
                'endTime' => now()->toISOString(),
                'environment' => $this->config->environment,
                'releaseVersion' => $this->config->release,
                'tags' => $opts['tags'] ?? [],
                'status' => 'ok',
            ]));

            return $result;
        } catch (\Throwable $e) {
            $elapsed = (microtime(true) - $t0) * 1000;

            $this->reportSpan(new PerformanceSpan([
                'operation' => $op,
                'operationType' => $opts['operationType'] ?? 'custom',
                'durationMs' => $elapsed,
                'startTime' => now()->subMilliseconds((int) $elapsed)->toISOString(),
                'endTime' => now()->toISOString(),
                'environment' => $this->config->environment,
                'releaseVersion' => $this->config->release,
                'tags' => $opts['tags'] ?? [],
                'status' => 'error',
                'errorMessage' => $e->getMessage(),
            ]));

            throw $e;
        }
    }

    public function flush(): bool
    {
        if (empty($this->spans)) {
            return true;
        }

        $batch = $this->spans;
        $this->spans = [];

        $payload = array_map(fn (PerformanceSpan $s) => $s->toArray(), $batch);
        $ok = $this->http->sendPerformanceSpanBatch($payload);

        if (! $ok && $this->config->debug) {
            error_log('[RiviumTrace] Failed to flush performance spans');
        }

        return $ok;
    }

    public function bufferSize(): int
    {
        return count($this->spans);
    }
}
