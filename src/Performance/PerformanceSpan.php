<?php

namespace RiviumTrace\Laravel\Performance;

use RiviumTrace\Laravel\Config\RiviumTraceConfig;

class PerformanceSpan
{
    public string $operation;
    public string $operationType;
    public string $traceId;
    public string $spanId;
    public ?string $parentSpanId;
    public ?string $httpMethod;
    public ?string $httpUrl;
    public ?int $httpStatusCode;
    public ?string $httpHost;
    public float $durationMs;
    public string $startTime;
    public ?string $endTime;
    public string $platform;
    public ?string $environment;
    public ?string $releaseVersion;
    public string $status;
    public ?string $errorMessage;
    public array $tags;
    public array $metadata;

    public function __construct(array $opts = [])
    {
        $this->operation = $opts['operation'] ?? '';
        $this->operationType = $opts['operationType'] ?? 'custom';
        $this->traceId = $opts['traceId'] ?? self::newTraceId();
        $this->spanId = $opts['spanId'] ?? self::newSpanId();
        $this->parentSpanId = $opts['parentSpanId'] ?? null;
        $this->httpMethod = $opts['httpMethod'] ?? null;
        $this->httpUrl = $opts['httpUrl'] ?? null;
        $this->httpStatusCode = $opts['httpStatusCode'] ?? null;
        $this->httpHost = $opts['httpHost'] ?? null;
        $this->durationMs = $opts['durationMs'] ?? 0;
        $this->startTime = $opts['startTime'] ?? now()->toISOString();
        $this->endTime = $opts['endTime'] ?? null;
        $this->platform = RiviumTraceConfig::PLATFORM;
        $this->environment = $opts['environment'] ?? null;
        $this->releaseVersion = $opts['releaseVersion'] ?? null;
        $this->status = $opts['status'] ?? 'ok';
        $this->errorMessage = $opts['errorMessage'] ?? null;
        $this->tags = $opts['tags'] ?? [];
        $this->metadata = $opts['metadata'] ?? [];
    }

    public static function fromHttpRequest(array $opts): self
    {
        $host = null;
        $path = $opts['url'] ?? '';

        try {
            $parts = parse_url($opts['url'] ?? '');
            $host = $parts['host'] ?? null;
            $path = $parts['path'] ?? $path;
        } catch (\Throwable) {
            $path = mb_substr($opts['url'] ?? '', 0, 50);
        }

        $method = $opts['method'] ?? 'GET';
        $code = $opts['statusCode'] ?? null;
        $ms = $opts['durationMs'] ?? 0;

        $start = $opts['startTime'] ?? now();
        if ($start instanceof \DateTimeInterface) {
            $startStr = $start->format('c');
            $startMs = (int) ($start->format('Uv'));
        } else {
            $startStr = $start;
            $startMs = (int) (strtotime($start) * 1000);
        }

        $hasError = ($opts['errorMessage'] ?? null) || ($code !== null && $code >= 400);

        return new self([
            'operation' => "{$method} {$path}",
            'operationType' => 'http',
            'httpMethod' => $method,
            'httpUrl' => $opts['url'] ?? '',
            'httpStatusCode' => $code,
            'httpHost' => $host,
            'durationMs' => $ms,
            'startTime' => $startStr,
            'endTime' => (new \DateTimeImmutable())->setTimestamp((int) ($startMs / 1000))->modify("+{$ms} milliseconds")->format('c'),
            'environment' => $opts['environment'] ?? null,
            'releaseVersion' => $opts['releaseVersion'] ?? null,
            'status' => $hasError ? 'error' : 'ok',
            'errorMessage' => $opts['errorMessage'] ?? null,
            'tags' => $opts['tags'] ?? [],
        ]);
    }

    public static function forDbQuery(array $opts): self
    {
        $type = $opts['queryType'] ?? 'QUERY';
        $table = $opts['tableName'] ?? 'unknown';
        $ms = $opts['durationMs'] ?? 0;

        $tags = $opts['tags'] ?? [];
        $tags['db_table'] = $table;
        $tags['query_type'] = $type;
        if (isset($opts['rowsAffected'])) {
            $tags['rows_affected'] = (string) $opts['rowsAffected'];
        }

        $start = $opts['startTime'] ?? now();
        $startStr = $start instanceof \DateTimeInterface ? $start->format('c') : $start;

        return new self([
            'operation' => "{$type} {$table}",
            'operationType' => 'db',
            'durationMs' => $ms,
            'startTime' => $startStr,
            'environment' => $opts['environment'] ?? null,
            'releaseVersion' => $opts['releaseVersion'] ?? null,
            'status' => isset($opts['errorMessage']) ? 'error' : 'ok',
            'errorMessage' => $opts['errorMessage'] ?? null,
            'tags' => $tags,
        ]);
    }

    public static function custom(array $opts): self
    {
        $start = $opts['startTime'] ?? now();
        $startStr = $start instanceof \DateTimeInterface ? $start->format('c') : $start;

        return new self([
            'operation' => $opts['operation'] ?? '',
            'operationType' => $opts['operationType'] ?? 'custom',
            'durationMs' => $opts['durationMs'] ?? 0,
            'startTime' => $startStr,
            'environment' => $opts['environment'] ?? null,
            'releaseVersion' => $opts['releaseVersion'] ?? null,
            'status' => $opts['status'] ?? (isset($opts['errorMessage']) ? 'error' : 'ok'),
            'errorMessage' => $opts['errorMessage'] ?? null,
            'tags' => $opts['tags'] ?? [],
        ]);
    }

    public function toArray(): array
    {
        $out = [
            'operation' => $this->operation,
            'operation_type' => $this->operationType,
            'duration_ms' => $this->durationMs,
            'start_time' => $this->startTime,
            'platform' => $this->platform,
            'status' => $this->status,
        ];

        if ($this->traceId) $out['trace_id'] = $this->traceId;
        if ($this->spanId) $out['span_id'] = $this->spanId;
        if ($this->parentSpanId) $out['parent_span_id'] = $this->parentSpanId;
        if ($this->endTime) $out['end_time'] = $this->endTime;
        if ($this->httpMethod) $out['http_method'] = $this->httpMethod;
        if ($this->httpUrl) $out['http_url'] = $this->httpUrl;
        if ($this->httpStatusCode !== null) $out['http_status_code'] = $this->httpStatusCode;
        if ($this->httpHost) $out['http_host'] = $this->httpHost;
        if ($this->environment) $out['environment'] = $this->environment;
        if ($this->releaseVersion) $out['release_version'] = $this->releaseVersion;
        if ($this->errorMessage) $out['error_message'] = $this->errorMessage;
        if (! empty($this->tags)) $out['tags'] = $this->tags;
        if (! empty($this->metadata)) $out['metadata'] = $this->metadata;

        return $out;
    }

    public static function newTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function newSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
