<?php

namespace RiviumTrace\Laravel;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RiviumTrace\Laravel\Config\RiviumTraceConfig;
use RiviumTrace\Laravel\Http\Middleware\RiviumTraceMiddleware;
use RiviumTrace\Laravel\Listeners\QueryListener;

class RiviumTraceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/riviumtrace.php', 'riviumtrace');

        $this->app->singleton(RiviumTrace::class, function ($app) {
            $cfg = new RiviumTraceConfig($app['config']->get('riviumtrace', []));
            return new RiviumTrace($cfg);
        });

        $this->app->alias(RiviumTrace::class, 'riviumtrace');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/riviumtrace.php' => config_path('riviumtrace.php'),
        ], 'riviumtrace-config');

        $cfg = $this->app['config'];

        if (! $cfg->get('riviumtrace.enabled', true)) {
            return;
        }

        $key = $cfg->get('riviumtrace.api_key', '');
        $secret = $cfg->get('riviumtrace.server_secret', '');
        if (empty($key) || empty($secret)) {
            return;
        }

        if ($cfg->get('riviumtrace.middleware.enabled', true)) {
            $router = $this->app['router'];
            $router->pushMiddlewareToGroup('web', RiviumTraceMiddleware::class);
            $router->pushMiddlewareToGroup('api', RiviumTraceMiddleware::class);
        }

        if ($cfg->get('riviumtrace.exception_handler.enabled', true)) {
            $this->setupExceptionHandler();
        }

        if ($cfg->get('riviumtrace.performance.track_db_queries', true)) {
            Event::listen(QueryExecuted::class, QueryListener::class);
        }

        register_shutdown_function(function () {
            try {
                if ($this->app->resolved(RiviumTrace::class)) {
                    $this->app->make(RiviumTrace::class)->flush();
                }
            } catch (\Throwable) {
            }
        });
    }

    private function setupExceptionHandler(): void
    {
        $this->app->resolving(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            function ($handler) {
                if (! method_exists($handler, 'reportable')) {
                    return;
                }

                $handler->reportable(function (\Throwable $e) {
                    try {
                        if ($this->app->resolved(RiviumTrace::class)) {
                            $this->app->make(RiviumTrace::class)->captureException($e);
                        }
                    } catch (\Throwable) {
                    }
                    return false;
                });
            }
        );
    }
}
