<?php

namespace RiviumTrace\Laravel\Models;

use RiviumTrace\Laravel\Config\RiviumTraceConfig;

/**
 * A message sent with captureMessage().
 *
 * Messages are not errors: they go to POST /api/messages and show up in the
 * Console's Messages list, never as an issue. The payload matches the other
 * Rivium Trace SDKs (Next.js RiviumTraceMessage).
 */
class RiviumTraceMessage
{
    /** Levels the messages endpoint knows. */
    public const LEVELS = ['debug', 'info', 'warning', 'error'];

    /** The backend truncates a message to this length. */
    private const MAX_LENGTH = 2000;

    public string $message;
    public string $level = 'info';
    public string $platform = RiviumTraceConfig::PLATFORM;
    public string $environment;
    public string $release;
    public string $timestamp;
    public ?string $userId = null;
    public array $extra = [];
    /** @var array<string, string> */
    public array $tags = [];
    public array $breadcrumbs = [];
    public string $userAgent;
    /** The request URL, if any. Sent inside `extra`; kept here so a before_send hook can read it. */
    public string $url;

    public static function create(string $msg, array $opts = []): self
    {
        $instance = new self();
        $instance->message = mb_substr($msg, 0, self::MAX_LENGTH);
        $instance->level = self::normalizeLevel($opts['level'] ?? null);
        $instance->environment = $opts['environment'] ?? 'production';
        $instance->release = $opts['release'] ?? '0.1.0';
        $instance->timestamp = self::now();
        $instance->userId = isset($opts['user_id']) && $opts['user_id'] !== ''
            ? (string) $opts['user_id']
            : null;
        $instance->extra = $opts['extra'] ?? [];
        $instance->tags = array_map('strval', $opts['tags'] ?? []);
        $instance->breadcrumbs = array_values($opts['breadcrumbs'] ?? []);
        $instance->userAgent = self::buildUserAgent();
        $instance->url = $opts['url'] ?? self::resolveUrl();

        return $instance;
    }

    /**
     * Maps any level a caller might pass onto the four the endpoint stores.
     * Unknown or missing levels become 'info'.
     */
    public static function normalizeLevel(mixed $level): string
    {
        if (! is_string($level)) {
            return 'info';
        }

        $level = strtolower(trim($level));

        return match ($level) {
            'debug', 'trace' => 'debug',
            'info', 'notice', 'log' => 'info',
            'warning', 'warn' => 'warning',
            'error', 'fatal', 'critical', 'alert', 'emergency' => 'error',
            default => 'info',
        };
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

    public function toArray(): array
    {
        $extra = $this->extra;
        if ($this->url !== '' && ! isset($extra['url'])) {
            $extra['url'] = $this->url;
        }

        return array_filter([
            'message' => $this->message,
            'level' => in_array($this->level, self::LEVELS, true) ? $this->level : 'info',
            'platform' => $this->platform,
            'environment' => $this->environment,
            'release' => $this->release,
            'timestamp' => $this->timestamp,
            'user_id' => $this->userId,
            'extra' => ! empty($extra) ? $extra : null,
            'tags' => ! empty($this->tags) ? $this->tags : null,
            'breadcrumbs' => ! empty($this->breadcrumbs) ? $this->breadcrumbs : null,
            'user_agent' => $this->userAgent,
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
}
