<?php

namespace RiviumTrace\Laravel\Tests\Unit;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use RiviumTrace\Laravel\Http\HttpClient;
use RiviumTrace\Laravel\Http\Middleware\RiviumTraceMiddleware;
use RiviumTrace\Laravel\Logging\LogService;
use RiviumTrace\Laravel\Logging\RiviumTraceLogChannel;
use RiviumTrace\Laravel\RiviumTrace;
use RiviumTrace\Laravel\Tests\TestCase;

/**
 * One exception is one error event, however many times it reaches the SDK
 * (request middleware, Laravel's exception handler, a manual capture before a
 * rethrow), and reporting it to Rivium Trace never stops Laravel from writing
 * it to the application's own log.
 */
class ExceptionReportingTest extends TestCase
{
    /** @var array<int, \Psr\Http\Message\RequestInterface> */
    private array $sent = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('riviumtrace.api_url', 'https://trace.example.test');
        // Spans are buffered until shutdown; keep them out of these tests.
        $app['config']->set('riviumtrace.performance.enabled', false);

        $app['config']->set('logging.default', 'spy');
        $app['config']->set('logging.channels.spy', [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
        ]);
        $app['config']->set('logging.channels.riviumtrace', [
            'driver' => 'custom',
            'via' => RiviumTraceLogChannel::class,
        ]);
        $app['config']->set('logging.channels.both', [
            'driver' => 'stack',
            'channels' => ['spy', 'riviumtrace'],
        ]);
    }

    protected function defineRoutes($router): void
    {
        // Named explicitly: Testbench rebuilds the middleware groups after the
        // package has pushed itself into them.
        $router->middleware(['web', RiviumTraceMiddleware::class])->get('/boom', function () {
            throw new \RuntimeException('boom in a controller');
        });
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
    }

    /** The app's own SDK instance, with every request it sends recorded instead of sent. */
    private function sdk(): RiviumTrace
    {
        $trace = $this->app->make(RiviumTrace::class);

        $mock = new MockHandler(array_fill(0, 50, new Response(201, [], '{"status":"created"}')));
        $handler = function ($request, $options) use ($mock) {
            $this->sent[] = $request;
            return $mock($request, $options);
        };
        $http = new HttpClient($trace->getConfig(), $handler);

        foreach (['http' => $http, 'logger' => new LogService($trace->getConfig(), $http)] as $name => $value) {
            $prop = new \ReflectionProperty($trace, $name);
            $prop->setAccessible(true);
            $prop->setValue($trace, $value);
        }

        return $trace;
    }

    /** @return array<int, array> decoded bodies of the requests sent to $path */
    private function sentTo(string $path): array
    {
        $bodies = [];
        foreach ($this->sent as $request) {
            if ($request->getUri()->getPath() === $path) {
                $bodies[] = json_decode((string) $request->getBody(), true);
            }
        }
        return $bodies;
    }

    /** @return array<int, \Monolog\LogRecord> what the application's own log received */
    private function appLog(): array
    {
        return Log::channel('spy')->getLogger()->getHandlers()[0]->getRecords();
    }

    /** Runs the middleware around a controller that throws, as a caller that lets it propagate. */
    private function throughMiddleware(\Throwable $e): void
    {
        $middleware = $this->app->make(RiviumTraceMiddleware::class);

        try {
            $middleware->handle(Request::create('/orders/42?page=2', 'GET'), function () use ($e) {
                throw $e;
            });
            $this->fail('The middleware must rethrow.');
        } catch (\Throwable $caught) {
            $this->assertSame($e, $caught);
        }
    }

    public function test_an_exception_seen_by_the_middleware_and_the_handler_is_one_event(): void
    {
        $this->sdk();
        $e = new \RuntimeException('boom');

        $this->throughMiddleware($e);
        $this->app->make(ExceptionHandler::class)->report($e);

        $errors = $this->sentTo('/api/errors');
        $this->assertCount(1, $errors);
        $this->assertSame('boom', $errors[0]['message']);
    }

    public function test_the_single_event_keeps_the_request_context(): void
    {
        $this->sdk();
        $e = new \RuntimeException('boom');

        $this->throughMiddleware($e);
        $this->app->make(ExceptionHandler::class)->report($e);

        $extra = $this->sentTo('/api/errors')[0]['extra'];
        $this->assertSame('GET', $extra['request']['method']);
        $this->assertSame('/orders/42', $extra['request_context']['path']);
        $this->assertSame('GET', $extra['request_context']['method']);
    }

    public function test_a_failing_route_is_one_event_with_its_request_context(): void
    {
        $this->sdk();

        $this->get('/boom')->assertStatus(500);

        $errors = $this->sentTo('/api/errors');
        $this->assertCount(1, $errors);
        $this->assertSame('boom in a controller', $errors[0]['message']);
        $this->assertSame('/boom', $errors[0]['extra']['request_context']['path']);
    }

    public function test_capturing_by_hand_before_rethrowing_is_one_event(): void
    {
        $trace = $this->sdk();
        $e = new \RuntimeException('caught, captured, rethrown');

        $trace->captureException($e, ['extra' => ['order_id' => 7]]);
        $this->app->make(ExceptionHandler::class)->report($e);

        $errors = $this->sentTo('/api/errors');
        $this->assertCount(1, $errors);
        $this->assertSame(7, $errors[0]['extra']['order_id']);
    }

    public function test_two_different_exceptions_are_two_events(): void
    {
        $trace = $this->sdk();

        $trace->captureException(new \RuntimeException('same text'));
        $trace->captureException(new \RuntimeException('same text'));

        $this->assertCount(2, $this->sentTo('/api/errors'));
    }

    public function test_two_different_exceptions_through_the_handler_are_two_events(): void
    {
        $this->sdk();
        $handler = $this->app->make(ExceptionHandler::class);

        $handler->report(new \RuntimeException('first'));
        $handler->report(new \LogicException('second'));

        $this->assertSame(['first', 'second'], array_column($this->sentTo('/api/errors'), 'message'));
    }

    public function test_an_exception_dropped_once_is_not_sent_by_a_later_capture(): void
    {
        $calls = 0;
        $this->app['config']->set('riviumtrace.before_send', function ($event) use (&$calls) {
            $calls++;
            return null;
        });
        $trace = $this->sdk();
        $e = new \RuntimeException('dropped');

        $trace->captureException($e);
        $trace->captureException($e);

        $this->assertSame(1, $calls);
        $this->assertCount(0, $this->sentTo('/api/errors'));
    }

    public function test_remembering_captured_exceptions_does_not_keep_them_alive(): void
    {
        $trace = $this->sdk();

        $e = new \RuntimeException('short lived');
        $ref = \WeakReference::create($e);
        $trace->captureException($e);
        unset($e);
        gc_collect_cycles();

        $this->assertNull($ref->get());
    }

    public function test_laravel_still_logs_a_reported_exception(): void
    {
        $this->sdk();
        $e = new \RuntimeException('must reach the app log');

        $this->app->make(ExceptionHandler::class)->report($e);

        $this->assertCount(1, $this->sentTo('/api/errors'));

        $records = $this->appLog();
        $this->assertCount(1, $records);
        $this->assertSame('must reach the app log', $records[0]->message);
        $this->assertSame($e, $records[0]->context['exception']);
    }

    public function test_laravel_still_logs_an_exception_from_a_failing_route(): void
    {
        $this->sdk();

        $this->get('/boom')->assertStatus(500);

        $records = $this->appLog();
        $this->assertCount(1, $records);
        $this->assertSame('boom in a controller', $records[0]->message);
    }

    public function test_the_reportable_callback_does_not_stop_laravels_reporting(): void
    {
        $this->sdk();
        $handler = $this->app->make(ExceptionHandler::class);

        $prop = new \ReflectionProperty(\Illuminate\Foundation\Exceptions\Handler::class, 'reportCallbacks');
        $prop->setAccessible(true);
        $callbacks = $prop->getValue($handler);

        $this->assertNotEmpty($callbacks);
        foreach ($callbacks as $callback) {
            $this->assertNotFalse($callback(new \RuntimeException('checked')));
        }
    }

    public function test_with_the_log_channel_in_the_stack_an_exception_is_one_event_and_one_log_line(): void
    {
        $this->app['config']->set('logging.default', 'both');
        $trace = $this->sdk();

        $this->app->make(ExceptionHandler::class)->report(new \RuntimeException('logged and tracked'));
        $trace->flush();

        $this->assertCount(1, $this->sentTo('/api/errors'));
        $this->assertCount(1, $this->appLog());

        $logs = $this->sentTo('/api/logs/ingest');
        $this->assertCount(1, $logs);
        $this->assertSame('logged and tracked', $logs[0]['message']);
        $this->assertSame('error', $logs[0]['level']);

        // Nothing else: sending never feeds back into the log or the handler.
        $this->assertCount(2, $this->sent);
    }

    public function test_a_failing_send_does_not_loop_back_through_the_log_channel(): void
    {
        $this->app['config']->set('logging.default', 'both');
        $trace = $this->app->make(RiviumTrace::class);

        $attempts = 0;
        $failing = function ($request) use (&$attempts) {
            $attempts++;
            return \GuzzleHttp\Promise\Create::promiseFor(new Response(400, [], '{"error":"rejected"}'));
        };
        $http = new HttpClient($trace->getConfig(), $failing);
        foreach (['http' => $http, 'logger' => new LogService($trace->getConfig(), $http)] as $name => $value) {
            $prop = new \ReflectionProperty($trace, $name);
            $prop->setAccessible(true);
            $prop->setValue($trace, $value);
        }

        $this->app->make(ExceptionHandler::class)->report(new \RuntimeException('api is rejecting'));
        $trace->flush();
        $trace->flush();

        // One error event and one log line were tried once each; the failures
        // were not logged, reported or queued again.
        $this->assertSame(2, $attempts);
        $this->assertCount(1, $this->appLog());
        $this->assertSame(0, $trace->pendingLogCount());
    }
}
