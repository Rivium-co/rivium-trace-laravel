<?php

namespace RiviumTrace\Laravel;

use Illuminate\Support\Facades\Facade;
use RiviumTrace\Laravel\Models\Breadcrumb;
use RiviumTrace\Laravel\Performance\PerformanceSpan;

/**
 * @method static void captureException(\Throwable $exception, array $options = [])
 * @method static void captureMessage(string $message, array $options = [])
 * @method static void addBreadcrumb(array|Breadcrumb $breadcrumb)
 * @method static void setUser(array $user)
 * @method static void setRequestContext(array $context)
 * @method static mixed withScope(callable $callback)
 * @method static void log(string $message, string $level = 'info', ?array $metadata = null)
 * @method static void trace(string $msg, ?array $meta = null)
 * @method static void logDebug(string $msg, ?array $meta = null)
 * @method static void info(string $msg, ?array $meta = null)
 * @method static void warn(string $msg, ?array $meta = null)
 * @method static void logError(string $msg, ?array $meta = null)
 * @method static void fatal(string $msg, ?array $meta = null)
 * @method static int pendingLogCount()
 * @method static void reportPerformanceSpan(PerformanceSpan $span)
 * @method static void reportPerformanceSpanBatch(array $spans)
 * @method static mixed trackOperation(string $operation, callable $fn, array $options = [])
 * @method static void flush()
 * @method static bool isEnabled()
 * @method static \RiviumTrace\Laravel\Config\RiviumTraceConfig getConfig()
 * @method static array|null getStats()
 *
 * @see \RiviumTrace\Laravel\RiviumTrace
 */
class RiviumTraceFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RiviumTrace::class;
    }
}
