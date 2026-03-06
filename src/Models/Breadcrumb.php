<?php

namespace RiviumTrace\Laravel\Models;

class Breadcrumb
{
    public string $timestamp;
    public string $message;
    public string $category;
    public string $level;
    public array $data;

    public function __construct(array $opts = [])
    {
        $this->timestamp = $opts['timestamp'] ?? now()->toISOString();
        $this->message = $opts['message'] ?? '';
        $this->category = $opts['category'] ?? 'manual';
        $this->level = $opts['level'] ?? 'info';
        $this->data = $opts['data'] ?? [];
    }

    public static function http(string $method, string $url, ?int $status = null, ?float $ms = null): self
    {
        $info = ['method' => $method, 'url' => $url];
        if ($status !== null) {
            $info['status_code'] = $status;
        }
        if ($ms !== null) {
            $info['duration_ms'] = round($ms, 2);
        }

        return new self([
            'message' => "{$method} {$url}",
            'category' => 'http',
            'level' => ($status !== null && $status >= 400) ? 'error' : 'info',
            'data' => $info,
        ]);
    }

    public static function database(string $query, float $ms, ?\Throwable $err = null): self
    {
        $truncated = mb_substr($query, 0, 500);
        $preview = mb_substr($query, 0, 100) . (mb_strlen($query) > 100 ? '...' : '');

        return new self([
            'message' => "Database query: {$preview}",
            'category' => 'database',
            'level' => $err ? 'error' : 'info',
            'data' => array_filter([
                'query' => $truncated,
                'duration_ms' => round($ms, 2),
                'error' => $err?->getMessage(),
            ], fn ($v) => $v !== null),
        ]);
    }

    public static function navigation(string $from, string $to, string $method = 'GET'): self
    {
        return new self([
            'message' => "{$method} {$to}",
            'category' => 'navigation',
            'level' => 'info',
            'data' => ['from' => $from, 'to' => $to, 'method' => $method],
        ]);
    }

    public static function user(string $action, array $data = []): self
    {
        return new self([
            'message' => "User action: {$action}",
            'category' => 'user',
            'level' => 'info',
            'data' => $data,
        ]);
    }

    public static function custom(string $message, string $category, array $data = []): self
    {
        return new self([
            'message' => $message,
            'category' => $category,
            'level' => 'info',
            'data' => $data,
        ]);
    }

    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'message' => $this->message,
            'category' => $this->category,
            'level' => $this->level,
            'data' => $this->data,
        ];
    }
}
