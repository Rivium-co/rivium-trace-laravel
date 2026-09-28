<?php

namespace RiviumTrace\Laravel\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use RiviumTrace\Laravel\Config\RiviumTraceConfig;
use RiviumTrace\Laravel\Http\Middleware\RiviumTraceMiddleware;
use RiviumTrace\Laravel\Models\RiviumTraceError;
use RiviumTrace\Laravel\RiviumTrace;
use RiviumTrace\Laravel\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * What the Console's issue Context card reads for a Laravel error: the SDK
 * user agent, laravel_context and request_context (method, path, route,
 * status). Nothing here may add bodies, query values or headers.
 */
class RequestContextTest extends TestCase
{
    private function request(string $uri = 'https://shop.test/users/42?tab=orders', string $method = 'GET'): Request
    {
        $request = Request::create($uri, $method, [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/126.0 Safari/537.36',
        ]);

        return $request;
    }

    private function bindRoute(Request $request, string $uri, ?string $name): void
    {
        $route = new Route(['GET'], $uri, fn () => 'ok');
        if ($name !== null) {
            $route->name($name);
        }
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
    }

    public function test_request_context_has_method_path_url_and_route(): void
    {
        $request = $this->request();
        $this->bindRoute($request, 'users/{user}', 'users.show');

        $ctx = RiviumTraceMiddleware::describeRequest($request);

        $this->assertSame('GET', $ctx['method']);
        $this->assertSame('/users/42', $ctx['path']);
        $this->assertSame('https://shop.test/users/42?tab=orders', $ctx['url']);
        $this->assertSame('/users/{user}', $ctx['route']);
        $this->assertSame('users.show', $ctx['route_name']);
        $this->assertStringContainsString('Chrome/126.0', $ctx['user_agent']);
    }

    public function test_root_path_is_a_single_slash(): void
    {
        $ctx = RiviumTraceMiddleware::describeRequest($this->request('https://shop.test/'));
        $this->assertSame('/', $ctx['path']);
    }

    public function test_unnamed_and_generated_route_names_are_left_out(): void
    {
        $request = $this->request();
        $this->bindRoute($request, 'users/{user}', null);
        $this->assertArrayNotHasKey('route_name', RiviumTraceMiddleware::describeRequest($request));

        $request = $this->request();
        $this->bindRoute($request, 'users/{user}', 'generated::a1B2c3');
        $ctx = RiviumTraceMiddleware::describeRequest($request);
        $this->assertArrayNotHasKey('route_name', $ctx);
        $this->assertSame('/users/{user}', $ctx['route']);
    }

    public function test_no_route_means_no_route_keys(): void
    {
        $ctx = RiviumTraceMiddleware::describeRequest($this->request());
        $this->assertArrayNotHasKey('route', $ctx);
        $this->assertArrayNotHasKey('route_name', $ctx);
    }

    public function test_request_context_carries_no_body_query_or_headers(): void
    {
        $request = Request::create('https://shop.test/login', 'POST', ['password' => 'hunter2']);
        $ctx = RiviumTraceMiddleware::describeRequest($request);

        $this->assertEqualsCanonicalizing(['method', 'url', 'path', 'ip', 'user_agent'], array_keys($ctx));
        $this->assertStringNotContainsString('hunter2', json_encode($ctx));
    }

    public function test_http_exceptions_add_their_status_code(): void
    {
        $trace = new RiviumTrace(new RiviumTraceConfig([
            'api_key' => 'rv_test_abc123',
            'server_secret' => 'rv_srv_secret123',
            'enabled' => false,
        ]));
        $trace->setRequestContext(['method' => 'GET', 'path' => '/users/42']);

        $method = new \ReflectionMethod($trace, 'requestContextFor');
        $method->setAccessible(true);

        $this->assertSame(503, $method->invoke($trace, new HttpException(503))['status_code']);
        $this->assertArrayNotHasKey('status_code', $method->invoke($trace, new \RuntimeException('x')));
    }

    public function test_no_request_context_outside_a_request(): void
    {
        $trace = new RiviumTrace(new RiviumTraceConfig([
            'api_key' => 'rv_test_abc123',
            'server_secret' => 'rv_srv_secret123',
            'enabled' => false,
        ]));
        $method = new \ReflectionMethod($trace, 'requestContextFor');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($trace, new HttpException(500)));
    }

    public function test_error_user_agent_is_the_sdk_one_and_laravel_context_is_complete(): void
    {
        $error = RiviumTraceError::fromThrowable(new \RuntimeException('boom'))->addLaravelContext();
        $payload = $error->toArray();

        $this->assertMatchesRegularExpression(
            '#^RiviumTrace-SDK/' . preg_quote(RiviumTraceConfig::SDK_VERSION, '#') . ' \(laravel; [^;]+; PHP [\d.]+#',
            $payload['user_agent']
        );
        $this->assertEqualsCanonicalizing(
            ['php_version', 'platform', 'server_software', 'memory_peak', 'sapi', 'laravel_version'],
            array_keys($payload['extra']['laravel_context'])
        );
    }

    public function test_request_bound_error_keeps_the_sdk_user_agent(): void
    {
        $error = RiviumTraceError::fromThrowable(new \RuntimeException('boom'))
            ->setRequestContext($this->request());
        $payload = $error->toArray();

        $this->assertStringStartsWith('RiviumTrace-SDK/', $payload['user_agent']);
        $this->assertStringContainsString('Chrome/126.0', $payload['extra']['request']['user_agent']);
        $this->assertSame('https://shop.test/users/42?tab=orders', $payload['url']);
    }
}
