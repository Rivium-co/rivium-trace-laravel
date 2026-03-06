<?php

namespace RiviumTrace\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use RiviumTrace\Laravel\Models\Breadcrumb;
use RiviumTrace\Laravel\Performance\PerformanceSpan;
use RiviumTrace\Laravel\RiviumTrace;
use Symfony\Component\HttpFoundation\Response;

class RiviumTraceMiddleware
{
    private RiviumTrace $sdk;

    public function __construct(RiviumTrace $sdk)
    {
        $this->sdk = $sdk;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->sdk->isEnabled() || $this->skipPath($request->path())) {
            return $next($request);
        }

        $t0 = microtime(true);

        $this->sdk->setRequestContext([
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $this->sdk->addBreadcrumb(
            Breadcrumb::http($request->method(), $request->path())
        );

        if (config('riviumtrace.middleware.track_user', true)) {
            try {
                $user = $request->user();
                if ($user) {
                    $this->sdk->setUser([
                        'id' => $user->getKey(),
                        'email' => $user->email ?? null,
                        'name' => $user->name ?? null,
                    ]);
                }
            } catch (\Throwable) {
            }
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $ms = (microtime(true) - $t0) * 1000;
            $this->sdk->addBreadcrumb(
                Breadcrumb::http($request->method(), $request->path(), 500, $ms)
            );
            $this->sdk->captureException($e, [
                'extra' => [
                    'request' => [
                        'method' => $request->method(),
                        'url' => $request->fullUrl(),
                        'ip' => $request->ip(),
                    ],
                ],
            ]);
            throw $e;
        }

        $ms = (microtime(true) - $t0) * 1000;

        $this->sdk->addBreadcrumb(
            Breadcrumb::http(
                $request->method(),
                $request->path(),
                $response->getStatusCode(),
                $ms
            )
        );

        if (config('riviumtrace.performance.enabled', true)) {
            $this->sdk->reportPerformanceSpan(
                PerformanceSpan::fromHttpRequest([
                    'method' => $request->method(),
                    'url' => $request->fullUrl(),
                    'statusCode' => $response->getStatusCode(),
                    'durationMs' => $ms,
                    'startTime' => now()->subMilliseconds((int) $ms),
                ])
            );
        }

        return $response;
    }

    private function skipPath(string $path): bool
    {
        $patterns = config('riviumtrace.middleware.ignored_paths', []);
        foreach ($patterns as $pat) {
            if (fnmatch($pat, $path)) {
                return true;
            }
        }
        return false;
    }
}
