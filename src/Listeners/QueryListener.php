<?php

namespace RiviumTrace\Laravel\Listeners;

use Illuminate\Database\Events\QueryExecuted;
use RiviumTrace\Laravel\Models\Breadcrumb;
use RiviumTrace\Laravel\Performance\PerformanceSpan;
use RiviumTrace\Laravel\RiviumTrace;

class QueryListener
{
    private RiviumTrace $sdk;

    public function __construct(RiviumTrace $sdk)
    {
        $this->sdk = $sdk;
    }

    public function handle(QueryExecuted $event): void
    {
        $this->sdk->addBreadcrumb(
            Breadcrumb::database(query: $event->sql, ms: $event->time)
        );

        $threshold = config('riviumtrace.performance.slow_query_threshold_ms', 500);

        if ($event->time >= $threshold && config('riviumtrace.performance.enabled', true)) {
            $this->sdk->reportPerformanceSpan(
                PerformanceSpan::forDbQuery([
                    'queryType' => $this->parseQueryType($event->sql),
                    'tableName' => $this->parseTableName($event->sql),
                    'durationMs' => $event->time,
                    'startTime' => now()->subMilliseconds((int) $event->time),
                ])
            );
        }
    }

    private function parseQueryType(string $sql): string
    {
        $first = strtoupper(strtok(ltrim($sql), ' ') ?: '');
        $known = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP'];
        return in_array($first, $known, true) ? $first : 'OTHER';
    }

    private function parseTableName(string $sql): string
    {
        if (preg_match('/\b(?:FROM|INTO|UPDATE|TABLE)\s+[`"\']?(\w+)[`"\']?/i', $sql, $m)) {
            return $m[1];
        }
        return 'unknown';
    }
}
