<?php

namespace RiviumTrace\Laravel\Tests\Unit;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use RiviumTrace\Laravel\Config\RiviumTraceConfig;
use RiviumTrace\Laravel\Http\HttpClient;
use RiviumTrace\Laravel\Models\Breadcrumb;
use RiviumTrace\Laravel\Models\RiviumTraceError;
use RiviumTrace\Laravel\Models\RiviumTraceMessage;
use RiviumTrace\Laravel\RiviumTrace;
use RiviumTrace\Laravel\Tests\TestCase;

/**
 * captureMessage() used to send a RiviumTraceError to /api/errors, so every
 * message became an issue. Messages belong on /api/messages, like every
 * other Rivium Trace SDK.
 */
class CaptureMessageTest extends TestCase
{
    /** @var array<int, \Psr\Http\Message\RequestInterface> */
    private array $sent = [];

    private function client(array $over = []): RiviumTrace
    {
        $config = new RiviumTraceConfig(array_merge([
            'api_key' => 'rv_test_abc123',
            'server_secret' => 'rv_srv_secret123',
            'api_url' => 'https://trace.example.test/',
            'environment' => 'testing',
            'release' => '1.4.2',
            'enabled' => true,
        ], $over));

        $trace = new RiviumTrace($config);

        $mock = new MockHandler(array_fill(0, 10, new Response(201, [], '{"status":"created"}')));
        $handler = function ($request, $options) use ($mock) {
            $this->sent[] = $request;
            return $mock($request, $options);
        };

        $prop = new \ReflectionProperty($trace, 'http');
        $prop->setAccessible(true);
        $prop->setValue($trace, new HttpClient($config, $handler));

        return $trace;
    }

    /**
     * Testbench leaves the Cache facade pointing at this test's app. The plain
     * PHPUnit RateLimiterTest that runs later expects no app (local counting).
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
    }

    private function body(int $i = 0): array
    {
        return json_decode((string) $this->sent[$i]->getBody(), true);
    }

    public function test_posts_to_the_messages_endpoint_never_the_errors_endpoint(): void
    {
        $trace = $this->client();
        $trace->captureMessage('Payment processed');

        $this->assertCount(1, $this->sent);
        $req = $this->sent[0];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('https://trace.example.test/api/messages', (string) $req->getUri());
        $this->assertStringNotContainsString('/api/errors', (string) $req->getUri());
        $this->assertSame('rv_test_abc123', $req->getHeaderLine('x-api-key'));
        $this->assertSame('application/json', $req->getHeaderLine('Content-Type'));
    }

    public function test_sends_the_message_payload(): void
    {
        $trace = $this->client();
        $trace->setUser(['id' => 42, 'email' => 'a@b.test']);
        $trace->addBreadcrumb(Breadcrumb::custom('Opened checkout', 'ui'));

        $trace->captureMessage('Payment processed', [
            'level' => 'warning',
            'extra' => ['amount' => 99.99],
            'tags' => ['plan' => 'pro'],
        ]);

        $body = $this->body();
        $this->assertSame('Payment processed', $body['message']);
        $this->assertSame('warning', $body['level']);
        $this->assertSame('laravel', $body['platform']);
        $this->assertSame('testing', $body['environment']);
        $this->assertSame('1.4.2', $body['release']);
        $this->assertSame('42', $body['user_id']);
        $this->assertSame(['plan' => 'pro'], $body['tags']);
        $this->assertSame(99.99, $body['extra']['amount']);
        $this->assertSame(42, $body['extra']['user_context']['id']);
        $this->assertArrayHasKey('laravel_context', $body['extra']);
        $this->assertStringStartsWith('RiviumTrace-SDK/' . RiviumTraceConfig::SDK_VERSION, $body['user_agent']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $body['timestamp']);

        // Breadcrumbs are a top-level field, not buried in extra.
        $this->assertCount(1, $body['breadcrumbs']);
        $this->assertSame('Opened checkout', $body['breadcrumbs'][0]['message']);
        $this->assertArrayNotHasKey('breadcrumbs', $body['extra']);

        // Nothing error-shaped.
        $this->assertArrayNotHasKey('stack_trace', $body);
        $this->assertArrayNotHasKey('release_version', $body);
    }

    public function test_level_defaults_to_info_and_is_normalised(): void
    {
        $trace = $this->client();
        $cases = [
            null => 'info',
            'warn' => 'warning',
            'fatal' => 'error',
            'critical' => 'error',
            'DEBUG' => 'debug',
            'nonsense' => 'info',
        ];

        $i = 0;
        foreach ($cases as $given => $expected) {
            $options = $given === '' ? [] : ['level' => $given];
            $trace->captureMessage("level {$i}", $options);
            $this->assertSame($expected, $this->body($i)['level'], "level '{$given}'");
            $i++;
        }
    }

    public function test_sends_at_most_ten_recent_breadcrumbs(): void
    {
        $trace = $this->client();
        for ($n = 1; $n <= 15; $n++) {
            $trace->addBreadcrumb(Breadcrumb::custom("crumb {$n}", 'test'));
        }

        $trace->captureMessage('hello');

        $crumbs = $this->body()['breadcrumbs'];
        $this->assertCount(10, $crumbs);
        $this->assertSame('crumb 15', end($crumbs)['message']);
    }

    public function test_no_user_means_no_user_id(): void
    {
        $trace = $this->client();
        $trace->captureMessage('anonymous');

        $this->assertArrayNotHasKey('user_id', $this->body());
    }

    public function test_before_send_sees_the_message_and_can_edit_it(): void
    {
        $seen = null;
        $trace = $this->client(['before_send' => function ($event) use (&$seen) {
            $seen = $event;
            $event->message = 'scrubbed';
            return $event;
        }]);

        $trace->captureMessage('token=secret');

        $this->assertInstanceOf(RiviumTraceMessage::class, $seen);
        $this->assertSame('scrubbed', $this->body()['message']);
    }

    public function test_before_send_can_drop_the_message(): void
    {
        $trace = $this->client(['before_send' => fn () => null]);
        $trace->captureMessage('noise');

        $this->assertCount(0, $this->sent);
    }

    public function test_before_send_returning_an_error_for_a_message_sends_the_message(): void
    {
        $trace = $this->client([
            'before_send' => fn () => RiviumTraceError::fromMessage('wrong type'),
        ]);
        $trace->captureMessage('original');

        $this->assertSame('https://trace.example.test/api/messages', (string) $this->sent[0]->getUri());
        $this->assertSame('original', $this->body()['message']);
    }

    public function test_sample_rate_zero_sends_nothing(): void
    {
        $trace = $this->client(['sample_rate' => 0.0]);
        $trace->captureMessage('sampled out');

        $this->assertCount(0, $this->sent);
    }

    public function test_exceptions_still_go_to_the_errors_endpoint(): void
    {
        $trace = $this->client();
        $trace->captureException(new \RuntimeException('boom'));

        $this->assertSame('https://trace.example.test/api/errors', (string) $this->sent[0]->getUri());
    }
}
