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

        $this->sdk->setRequestContext(self::describeRequest($request));

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

    /**
     * The request_context attached to errors and messages raised during this
     * request. `path` starts with "/", `route` is the matched route's URI
     * template ("/users/{user}") and `route_name` its name, when there is one.
     * `user_agent` is the client's; the event's own user_agent stays the SDK's.
     * No bodies, no query values, no headers.
     */
    public static function describeRequest(Request $request): array
    {
        $ctx = [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => '/' . ltrim($request->path(), '/'),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];

        try {
            $route = $request->route();
            if ($route instanceof \Illuminate\Routing\Route) {
                $ctx['route'] = '/' . ltrim($route->uri(), '/');
                $name = $route->getName();
                // Laravel names unnamed routes "generated::…" when routes are cached.
                if (is_string($name) && $name !== '' && ! str_starts_with($name, 'generated::')) {
                    $ctx['route_name'] = $name;
                }
            }
        } catch (\Throwable) {
        }

        return $ctx;
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
