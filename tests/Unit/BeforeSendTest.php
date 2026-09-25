<?php

namespace RiviumTrace\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RiviumTrace\Laravel\Config\RiviumTraceConfig;
use RiviumTrace\Laravel\Models\RiviumTraceError;
use RiviumTrace\Laravel\RiviumTrace;

/**
 * `before_send` is the last look at an error before it leaves the process —
 * where a token gets scrubbed out of a URL, or noise is dropped. The Node SDK
 * has had it; this is the Laravel half.
 */
class BeforeSendTest extends TestCase
{
    /**
     * Disabled, so constructing the client does not boot it: boot() reaches for
     * the Laravel container, and the hook is read from the config either way.
     */
    private function config(array $over = []): RiviumTraceConfig
    {
        return new RiviumTraceConfig(array_merge([
            'api_key' => 'rv_test_abc123',
            'server_secret' => 'rv_srv_secret123',
            'enabled' => false,
        ], $over));
    }

    /** Runs the private hook without touching the network. */
    private function apply(RiviumTraceConfig $config, RiviumTraceError $error): ?RiviumTraceError
    {
        $trace = new RiviumTrace($config);
        $method = new \ReflectionMethod($trace, 'applyBeforeSend');
        $method->setAccessible(true);
        return $method->invoke($trace, $error);
    }

    private function error(): RiviumTraceError
    {
        return RiviumTraceError::fromMessage('boom', ['environment' => 'test']);
    }

    public function test_sends_the_error_unchanged_when_no_hook_is_configured(): void
    {
        $error = $this->error();
        $this->assertSame($error, $this->apply($this->config(), $error));
    }

    public function test_drops_the_error_when_the_hook_returns_null(): void
    {
        $config = $this->config(['before_send' => fn () => null]);
        $this->assertNull($this->apply($config, $this->error()));
    }

    public function test_sends_what_the_hook_returns(): void
    {
        $replacement = RiviumTraceError::fromMessage('scrubbed', ['environment' => 'test']);
        $config = $this->config(['before_send' => fn () => $replacement]);

        $this->assertSame($replacement, $this->apply($config, $this->error()));
    }

    public function test_the_hook_can_edit_the_error_in_place(): void
    {
        $config = $this->config([
            'before_send' => function (RiviumTraceError $error) {
                $error->message = 'redacted';
                return $error;
            },
        ]);

        $result = $this->apply($config, $this->error());

        $this->assertNotNull($result);
        $this->assertEquals('redacted', $result->message);
    }

    public function test_a_hook_that_throws_never_loses_the_error(): void
    {
        $config = $this->config(['before_send' => fn () => throw new \RuntimeException('bad hook')]);
        $error = $this->error();

        $this->assertSame($error, $this->apply($config, $error));
    }

    public function test_a_hook_that_is_not_callable_is_ignored(): void
    {
        $config = $this->config(['before_send' => 'not a function']);
        $error = $this->error();

        $this->assertNull($config->beforeSend);
        $this->assertSame($error, $this->apply($config, $error));
    }
}
