<?php

namespace RiviumTrace\Laravel\Logging;

use RiviumTrace\Laravel\Config\RiviumTraceConfig;
use RiviumTrace\Laravel\Http\HttpClient;

class LogService
{
    private array $queue = [];
    private int $batchSize;
    private HttpClient $http;
    private RiviumTraceConfig $config;

    public function __construct(RiviumTraceConfig $config, HttpClient $http)
    {
        $this->config = $config;
        $this->http = $http;
        $this->batchSize = $config->logging['batch_size'] ?? 50;
    }

    public function log(string $message, string $level = LogLevel::INFO, ?array $metadata = null, ?string $userId = null): void
    {
        $this->queue[] = new LogEntry([
            'message' => $message,
            'level' => $level,
            'timestamp' => now(),
            'metadata' => $metadata,
            'userId' => $userId,
        ]);

        if (count($this->queue) >= $this->batchSize) {
            $this->flush();
        }
    }

    public function flush(): bool
    {
        if (empty($this->queue)) {
            return true;
        }

        $pending = $this->queue;
        $this->queue = [];

        $entries = array_map(fn (LogEntry $e) => array_merge(
            $e->toArray(),
            [
                'platform' => RiviumTraceConfig::PLATFORM,
                'environment' => $this->config->environment,
            ],
            $this->config->release ? ['release' => $this->config->release] : [],
        ), $pending);

        $srcId = $this->config->logging['source_id'] ?? null;

        if ($srcId) {
            $batch = [
                'sourceId' => $srcId,
                'sourceType' => 'sdk',
                'logs' => $entries,
            ];

            $srcName = $this->config->logging['source_name'] ?? null;
            if ($srcName) {
                $batch['sourceName'] = $srcName;
            }

            $ok = $this->http->sendLogBatch($batch);
        } else {
            $ok = true;
            foreach ($entries as $entry) {
                $entry['sourceType'] = 'sdk';
                if (! $this->http->sendLog($entry)) {
                    $ok = false;
                }
            }
        }

        if (! $ok && $this->config->debug) {
            error_log('[RiviumTrace] Failed to flush logs');
        }

        return $ok;
    }

    public function bufferSize(): int
    {
        return count($this->queue);
    }
}
