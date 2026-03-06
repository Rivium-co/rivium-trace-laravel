<?php

namespace RiviumTrace\Laravel\Utils;

use Illuminate\Support\Facades\Cache;
use RiviumTrace\Laravel\Models\RiviumTraceError;

class RateLimiter
{
    private int $windowSeconds;
    private int $maxPerKey;
    private int $maxTotal;
    private array $localErrors = [];
    private int $localTotal = 0;

    public function __construct(int $windowSeconds = 60, int $maxErrorsPerKey = 10, int $maxTotal = 100)
    {
        $this->windowSeconds = $windowSeconds;
        $this->maxPerKey = $maxErrorsPerKey;
        $this->maxTotal = $maxTotal;
    }

    public function shouldSendError(RiviumTraceError $error): array
    {
        $key = $this->fingerprint($error);

        try {
            return $this->evaluateWithCache($key);
        } catch (\Throwable) {
            return $this->evaluateLocally($key);
        }
    }

    public function reset(): void
    {
        $this->localErrors = [];
        $this->localTotal = 0;
    }

    public function getStats(): array
    {
        return [
            'in_memory_total' => $this->localTotal,
            'in_memory_unique_errors' => count($this->localErrors),
            'total_limit' => $this->maxTotal,
            'error_limit' => $this->maxPerKey,
            'window_seconds' => $this->windowSeconds,
        ];
    }

    private function evaluateWithCache(string $key): array
    {
        $totalKey = 'riviumtrace:rate:total';
        $errKey = "riviumtrace:rate:error:{$key}";

        $total = (int) Cache::get($totalKey, 0);
        if ($total >= $this->maxTotal) {
            return ['allowed' => false, 'reason' => 'total_limit_exceeded'];
        }

        $count = (int) Cache::get($errKey, 0);
        if ($count >= $this->maxPerKey) {
            return ['allowed' => false, 'reason' => 'error_limit_exceeded'];
        }

        Cache::put($totalKey, $total + 1, $this->windowSeconds);
        Cache::put($errKey, $count + 1, $this->windowSeconds);

        return ['allowed' => true];
    }

    private function evaluateLocally(string $key): array
    {
        if ($this->localTotal >= $this->maxTotal) {
            return ['allowed' => false, 'reason' => 'total_limit_exceeded'];
        }

        $count = $this->localErrors[$key] ?? 0;
        if ($count >= $this->maxPerKey) {
            return ['allowed' => false, 'reason' => 'error_limit_exceeded'];
        }

        $this->localTotal++;
        $this->localErrors[$key] = $count + 1;

        return ['allowed' => true];
    }

    private function fingerprint(RiviumTraceError $error): string
    {
        return md5("{$error->message}_{$error->platform}_{$error->environment}");
    }
}
