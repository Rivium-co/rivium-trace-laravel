<?php

namespace RiviumTrace\Laravel;

use RiviumTrace\Laravel\Config\RiviumTraceConfig;
use RiviumTrace\Laravel\Http\HttpClient;
use RiviumTrace\Laravel\Logging\LogLevel;
use RiviumTrace\Laravel\Logging\LogService;
use RiviumTrace\Laravel\Models\Breadcrumb;
use RiviumTrace\Laravel\Models\BreadcrumbManager;
use RiviumTrace\Laravel\Models\RiviumTraceError;
use RiviumTrace\Laravel\Performance\PerformanceClient;
use RiviumTrace\Laravel\Performance\PerformanceSpan;
use RiviumTrace\Laravel\Utils\RateLimiter;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RiviumTrace
{
    private RiviumTraceConfig $config;
    private ?HttpClient $http = null;
    private ?BreadcrumbManager $breadcrumbs = null;
    private ?RateLimiter $limiter = null;
    private ?LogService $logger = null;
    private ?PerformanceClient $apm = null;
    private ?array $reqCtx = null;
    private ?array $usrCtx = null;
    private bool $ready = false;

    public function __construct(RiviumTraceConfig $config)
    {
        $this->config = $config;

        if ($config->isEnabled()) {
            $this->boot();
        }
    }

    public function captureException(\Throwable $exception, array $options = []): void
    {
        if (! $this->canCapture() || $this->shouldIgnore($exception)) {
            return;
        }

        try {
            $err = RiviumTraceError::fromThrowable($exception, [
                'environment' => $this->config->environment,
                'release' => $this->config->release,
                'extra' => array_merge($options['extra'] ?? [], [
                    'breadcrumbs' => $this->breadcrumbs?->getRecent(10),
                    'request_context' => $this->reqCtx,
                    'user_context' => $this->usrCtx,
                ]),
            ]);
            $err->addLaravelContext();
            $this->dispatch($err);
        } catch (\Throwable $e) {
            $this->debugLog('Error capturing exception: ' . $e->getMessage());
        }
    }

    public function captureMessage(string $message, array $options = []): void
    {
        if (! $this->canCapture()) {
            return;
        }

        try {
            $err = RiviumTraceError::fromMessage($message, [
                'environment' => $this->config->environment,
                'release' => $this->config->release,
                'extra' => array_merge($options['extra'] ?? [], [
                    'breadcrumbs' => $this->breadcrumbs?->getRecent(10),
                    'request_context' => $this->reqCtx,
                    'user_context' => $this->usrCtx,
                ]),
            ]);
            $err->addLaravelContext();
            $this->dispatch($err);
        } catch (\Throwable $e) {
            $this->debugLog('Error capturing message: ' . $e->getMessage());
        }
    }

    public function addBreadcrumb(array|Breadcrumb $breadcrumb): void
    {
        if ($this->ready) {
            $this->breadcrumbs?->add($breadcrumb);
        }
    }

    public function setUser(array $user): void
    {
        $this->usrCtx = $user;
    }

    public function setRequestContext(array $context): void
    {
        $this->reqCtx = $context;
    }

    public function withScope(callable $callback): mixed
    {
        $prevReq = $this->reqCtx;
        $prevUsr = $this->usrCtx;

        try {
            return $callback($this);
        } finally {
            $this->reqCtx = $prevReq;
            $this->usrCtx = $prevUsr;
        }
    }

    public function log(string $message, string $level = LogLevel::INFO, ?array $metadata = null): void
    {
        if (! $this->ready) {
            return;
        }
        $this->initLogService();
        $this->logger?->log($message, $level, $metadata, $this->usrCtx['id'] ?? null);
    }

    public function trace(string $msg, ?array $meta = null): void
    {
        $this->log($msg, LogLevel::TRACE, $meta);
    }

    public function logDebug(string $msg, ?array $meta = null): void
    {
        $this->log($msg, LogLevel::DEBUG, $meta);
    }

    public function info(string $msg, ?array $meta = null): void
    {
        $this->log($msg, LogLevel::INFO, $meta);
    }

    public function warn(string $msg, ?array $meta = null): void
    {
        $this->log($msg, LogLevel::WARN, $meta);
    }

    public function logError(string $msg, ?array $meta = null): void
    {
        $this->log($msg, LogLevel::ERROR, $meta);
    }

    public function fatal(string $msg, ?array $meta = null): void
    {
        $this->log($msg, LogLevel::FATAL, $meta);
    }

    public function pendingLogCount(): int
    {
        return $this->logger?->bufferSize() ?? 0;
    }

    public function reportPerformanceSpan(PerformanceSpan $span): void
    {
        if ($this->ready) {
            $this->apm?->reportSpan($span);
        }
    }

    public function reportPerformanceSpanBatch(array $spans): void
    {
        if (! $this->ready) {
            return;
        }
        foreach ($spans as $s) {
            $this->apm?->reportSpan($s);
        }
    }

    public function trackOperation(string $operation, callable $fn, array $options = []): mixed
    {
        if (! $this->ready || ! $this->apm) {
            return $fn();
        }
        return $this->apm->trackOperation($operation, $fn, $options);
    }

    public function flush(): void
    {
        try {
            $this->logger?->flush();
        } catch (\Throwable $e) {
            $this->debugLog('Error flushing logs: ' . $e->getMessage());
        }

        try {
            $this->apm?->flush();
        } catch (\Throwable $e) {
            $this->debugLog('Error flushing performance spans: ' . $e->getMessage());
        }
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function getConfig(): RiviumTraceConfig
    {
        return $this->config;
    }

    public function getStats(): ?array
    {
        if (! $this->ready) {
            return null;
        }

        return [
            'isEnabled' => $this->config->isEnabled(),
            'breadcrumbCount' => $this->breadcrumbs?->count() ?? 0,
            'pendingLogs' => $this->logger?->bufferSize() ?? 0,
            'pendingSpans' => $this->apm?->bufferSize() ?? 0,
            'rateLimiter' => $this->limiter?->getStats(),
            'config' => [
                'environment' => $this->config->environment,
                'release' => $this->config->release,
                'apiKey' => mb_substr($this->config->apiKey, 0, 10) . '***',
            ],
        ];
    }

    private function boot(): void
    {
        $this->http = new HttpClient($this->config);
        $this->breadcrumbs = new BreadcrumbManager($this->config->maxBreadcrumbs);

        $rlConfig = $this->config->rateLimiting;
        $this->limiter = new RateLimiter(
            windowSeconds: $rlConfig['window_seconds'] ?? 60,
            maxErrorsPerKey: $rlConfig['max_errors_per_key'] ?? 10,
            maxTotal: $rlConfig['max_total'] ?? 100,
        );

        if ($this->config->logging['enabled'] ?? false) {
            $this->logger = new LogService($this->config, $this->http);
        }

        if ($this->config->performance['enabled'] ?? false) {
            $this->apm = new PerformanceClient($this->config, $this->http);
        }

        $this->ready = true;

        $this->debugLog(sprintf(
            'Initialized for Laravel %s (PHP %s)',
            app()->version(),
            PHP_VERSION
        ));
    }

    private function canCapture(): bool
    {
        if (! $this->ready) {
            return false;
        }

        $rate = $this->config->sampleRate;
        if ($rate < 1.0 && (mt_rand() / mt_getrandmax()) > $rate) {
            $this->debugLog('Event dropped due to sample rate');
            return false;
        }

        return true;
    }

    private function dispatch(RiviumTraceError $error): void
    {
        if (! $this->config->isEnabled()) {
            return;
        }

        $check = $this->limiter->shouldSendError($error);
        if (! $check['allowed']) {
            $this->debugLog("Rate limited: {$check['reason']}");
            return;
        }

        $resp = $this->http->sendError($error->toArray());

        if (! $resp['success']) {
            $this->debugLog('Failed to send error: ' . ($resp['error'] ?? 'unknown'));
        }
    }

    private function shouldIgnore(\Throwable $e): bool
    {
        $ignoredClasses = $this->config->exceptionHandler['ignored_exceptions'] ?? [];
        foreach ($ignoredClasses as $cls) {
            if ($e instanceof $cls) {
                return true;
            }
        }

        $report4xx = $this->config->exceptionHandler['report_4xx'] ?? false;
        if (! $report4xx && $e instanceof HttpException) {
            $code = $e->getStatusCode();
            if ($code >= 400 && $code < 500) {
                return true;
            }
        }

        return false;
    }

    private function initLogService(): void
    {
        if ($this->logger === null && $this->http !== null) {
            $this->logger = new LogService($this->config, $this->http);
        }
    }

    private function debugLog(string $msg): void
    {
        if ($this->config->debug) {
            error_log('[RiviumTrace] ' . $msg);
        }
    }
}
