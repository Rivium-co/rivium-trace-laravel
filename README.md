# RiviumTrace Laravel SDK

Official Laravel SDK for RiviumTrace — error tracking, logging, and APM.

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12
- Guzzle 7

## Installation

```bash
composer require rivium-trace/laravel-sdk
```

Auto-discovery handles service provider and facade registration.

## Configuration

Publish config:

```bash
php artisan vendor:publish --tag=riviumtrace-config
```

Add to `.env`:

```env
RIVIUMTRACE_API_KEY=rv_live_your_api_key_here
RIVIUMTRACE_SERVER_SECRET=rv_srv_your_secret_here
RIVIUMTRACE_ENVIRONMENT=production
RIVIUMTRACE_RELEASE=0.1.0
```

## Usage

### Automatic Tracking

RiviumTrace automatically captures:

- **Unhandled exceptions** through Laravel's exception handler
- **HTTP requests** through middleware (timing, status, breadcrumbs)
- **Slow DB queries** as performance spans

### Manual Error Capture

```php
use RiviumTrace\Laravel\RiviumTraceFacade as RiviumTrace;

try {
    riskyOperation();
} catch (\Throwable $e) {
    RiviumTrace::captureException($e, [
        'extra' => ['order_id' => $orderId],
    ]);
}

RiviumTrace::captureMessage('Payment processed', [
    'extra' => ['amount' => 99.99],
]);
```

### Breadcrumbs

```php
use RiviumTrace\Laravel\Models\Breadcrumb;

RiviumTrace::addBreadcrumb(
    Breadcrumb::custom('User signed up', 'auth', ['plan' => 'pro'])
);

RiviumTrace::addBreadcrumb(
    Breadcrumb::http('POST', '/api/payments', 201, 450.5)
);

RiviumTrace::addBreadcrumb(
    Breadcrumb::user('checkout_completed', ['items' => 3])
);
```

### User Context

```php
RiviumTrace::setUser([
    'id' => $user->id,
    'email' => $user->email,
    'name' => $user->name,
]);
```

### Scoped Context

```php
RiviumTrace::withScope(function ($scope) {
    $scope->setUser(['id' => 'temp-user']);
    processOrder($order);
});
```

### Logging

Add the RiviumTrace channel to `config/logging.php`:

```php
'channels' => [
    'riviumtrace' => [
        'driver' => 'custom',
        'via' => \RiviumTrace\Laravel\Logging\RiviumTraceLogChannel::class,
    ],

    'stack' => [
        'driver' => 'stack',
        'channels' => ['daily', 'riviumtrace'],
    ],
],
```

Usage:

```php
Log::channel('riviumtrace')->info('Order created', ['order_id' => 123]);
Log::channel('riviumtrace')->error('Payment failed', ['reason' => 'declined']);

RiviumTrace::info('Processing started');
RiviumTrace::warn('Rate limit approaching', ['current' => 95, 'max' => 100]);
RiviumTrace::logError('External API timeout', ['service' => 'stripe']);
```

Set a source ID for batched log delivery:

```env
RIVIUMTRACE_LOG_SOURCE_ID=my-laravel-api
RIVIUMTRACE_LOG_SOURCE_NAME=My Laravel API
```

### Performance Monitoring

```php
use RiviumTrace\Laravel\Performance\PerformanceSpan;

$result = RiviumTrace::trackOperation('process_payment', function () use ($order) {
    return PaymentGateway::charge($order);
}, ['operationType' => 'custom', 'tags' => ['provider' => 'stripe']]);

RiviumTrace::reportPerformanceSpan(
    PerformanceSpan::custom([
        'operation' => 'generate_report',
        'durationMs' => 1500,
        'startTime' => $startTime,
        'tags' => ['report_type' => 'monthly'],
    ])
);

RiviumTrace::reportPerformanceSpan(
    PerformanceSpan::fromHttpRequest([
        'method' => 'POST',
        'url' => 'https://api.stripe.com/v1/charges',
        'statusCode' => 200,
        'durationMs' => 320,
        'startTime' => $startTime,
    ])
);
```

## Config Reference

| Key | Env Var | Default | Description |
|-----|---------|---------|-------------|
| `api_key` | `RIVIUMTRACE_API_KEY` | `''` | API key (rv_live_xxx / rv_test_xxx) |
| `server_secret` | `RIVIUMTRACE_SERVER_SECRET` | `''` | Server secret (rv_srv_xxx) |
| `enabled` | `RIVIUMTRACE_ENABLED` | `true` | Enable/disable SDK |
| `environment` | `RIVIUMTRACE_ENVIRONMENT` | `APP_ENV` | Environment name |
| `release` | `RIVIUMTRACE_RELEASE` | `0.1.0` | Release version |
| `sample_rate` | `RIVIUMTRACE_SAMPLE_RATE` | `1.0` | Error sample rate (0.0-1.0) |
| `debug` | `RIVIUMTRACE_DEBUG` | `false` | Debug mode |
| `timeout` | `RIVIUMTRACE_TIMEOUT` | `5` | HTTP timeout (seconds) |
| `performance.enabled` | `RIVIUMTRACE_PERFORMANCE_ENABLED` | `true` | Enable APM |
| `logging.enabled` | `RIVIUMTRACE_LOGGING_ENABLED` | `true` | Enable logging |
| `logging.source_id` | `RIVIUMTRACE_LOG_SOURCE_ID` | `null` | Log source ID |

## Ignored Exceptions

By default these exceptions are not reported:

- `AuthenticationException`
- `ValidationException`
- `NotFoundHttpException`
- 4xx HTTP exceptions

Customize in `config/riviumtrace.php`:

```php
'exception_handler' => [
    'ignored_exceptions' => [
        \App\Exceptions\BusinessException::class,
    ],
    'report_4xx' => true,
],
```

## Middleware

Auto-added to `web` and `api` groups. Disable via config:

```php
'middleware' => ['enabled' => false],
```

Ignore specific paths:

```php
'middleware' => [
    'ignored_paths' => ['_debugbar/*', 'telescope/*', 'health'],
],
```

## License

MIT
