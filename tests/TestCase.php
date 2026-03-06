<?php

namespace RiviumTrace\Laravel\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use RiviumTrace\Laravel\RiviumTraceServiceProvider;

class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            RiviumTraceServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'RiviumTrace' => \RiviumTrace\Laravel\RiviumTraceFacade::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('riviumtrace.api_key', 'rv_test_abc123');
        $app['config']->set('riviumtrace.server_secret', 'rv_srv_secret123');
        $app['config']->set('riviumtrace.enabled', true);
        $app['config']->set('riviumtrace.debug', false);
        $app['config']->set('riviumtrace.environment', 'testing');
    }
}
