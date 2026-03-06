<?php

namespace RiviumTrace\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RiviumTrace\Laravel\Performance\PerformanceSpan;

class PerformanceSpanTest extends TestCase
{
    public function test_creates_span_with_defaults(): void
    {
        $span = new PerformanceSpan([
            'operation' => 'test-op',
            'durationMs' => 100,
        ]);

        $this->assertEquals('test-op', $span->operation);
        $this->assertEquals('custom', $span->operationType);
        $this->assertEquals(100, $span->durationMs);
        $this->assertEquals('ok', $span->status);
        $this->assertEquals('laravel', $span->platform);
        $this->assertNotEmpty($span->traceId);
        $this->assertNotEmpty($span->spanId);
        $this->assertEquals(32, strlen($span->traceId));
        $this->assertEquals(16, strlen($span->spanId));
    }

    public function test_from_http_request(): void
    {
        $span = PerformanceSpan::fromHttpRequest([
            'method' => 'GET',
            'url' => 'https://example.com/api/users',
            'statusCode' => 200,
            'durationMs' => 150,
            'startTime' => new \DateTimeImmutable('2026-01-01T00:00:00Z'),
        ]);

        $this->assertEquals('GET /api/users', $span->operation);
        $this->assertEquals('http', $span->operationType);
        $this->assertEquals('GET', $span->httpMethod);
        $this->assertEquals('https://example.com/api/users', $span->httpUrl);
        $this->assertEquals(200, $span->httpStatusCode);
        $this->assertEquals('example.com', $span->httpHost);
        $this->assertEquals(150, $span->durationMs);
        $this->assertEquals('ok', $span->status);
    }

    public function test_from_http_request_error_status(): void
    {
        $span = PerformanceSpan::fromHttpRequest([
            'method' => 'POST',
            'url' => '/api/orders',
            'statusCode' => 500,
            'durationMs' => 200,
        ]);

        $this->assertEquals('error', $span->status);
    }

    public function test_for_db_query(): void
    {
        $span = PerformanceSpan::forDbQuery([
            'queryType' => 'SELECT',
            'tableName' => 'users',
            'durationMs' => 50,
            'startTime' => new \DateTimeImmutable(),
        ]);

        $this->assertEquals('SELECT users', $span->operation);
        $this->assertEquals('db', $span->operationType);
        $this->assertEquals('users', $span->tags['db_table']);
        $this->assertEquals('SELECT', $span->tags['query_type']);
        $this->assertEquals('ok', $span->status);
    }

    public function test_for_db_query_with_error(): void
    {
        $span = PerformanceSpan::forDbQuery([
            'queryType' => 'INSERT',
            'tableName' => 'orders',
            'durationMs' => 100,
            'errorMessage' => 'Duplicate key',
        ]);

        $this->assertEquals('error', $span->status);
        $this->assertEquals('Duplicate key', $span->errorMessage);
    }

    public function test_custom_span(): void
    {
        $span = PerformanceSpan::custom([
            'operation' => 'process_payment',
            'durationMs' => 500,
            'tags' => ['provider' => 'stripe'],
        ]);

        $this->assertEquals('process_payment', $span->operation);
        $this->assertEquals('custom', $span->operationType);
        $this->assertEquals(['provider' => 'stripe'], $span->tags);
    }

    public function test_to_array_snake_case(): void
    {
        $span = PerformanceSpan::fromHttpRequest([
            'method' => 'GET',
            'url' => 'https://example.com/test',
            'statusCode' => 200,
            'durationMs' => 100,
            'environment' => 'production',
            'releaseVersion' => '0.1.0',
        ]);

        $array = $span->toArray();

        $this->assertArrayHasKey('operation', $array);
        $this->assertArrayHasKey('operation_type', $array);
        $this->assertArrayHasKey('duration_ms', $array);
        $this->assertArrayHasKey('start_time', $array);
        $this->assertArrayHasKey('platform', $array);
        $this->assertArrayHasKey('status', $array);
        $this->assertArrayHasKey('trace_id', $array);
        $this->assertArrayHasKey('span_id', $array);
        $this->assertArrayHasKey('http_method', $array);
        $this->assertArrayHasKey('http_url', $array);
        $this->assertArrayHasKey('http_status_code', $array);
        $this->assertArrayHasKey('http_host', $array);
        $this->assertArrayHasKey('environment', $array);
        $this->assertArrayHasKey('release_version', $array);
        $this->assertEquals('laravel', $array['platform']);
    }

    public function test_to_array_omits_null_fields(): void
    {
        $span = new PerformanceSpan([
            'operation' => 'test',
            'durationMs' => 50,
        ]);

        $array = $span->toArray();

        $this->assertArrayNotHasKey('http_method', $array);
        $this->assertArrayNotHasKey('http_url', $array);
        $this->assertArrayNotHasKey('error_message', $array);
        $this->assertArrayNotHasKey('tags', $array);
        $this->assertArrayNotHasKey('metadata', $array);
    }

    public function test_trace_id_uniqueness(): void
    {
        $id1 = PerformanceSpan::newTraceId();
        $id2 = PerformanceSpan::newTraceId();

        $this->assertNotEquals($id1, $id2);
        $this->assertEquals(32, strlen($id1));
    }

    public function test_span_id_uniqueness(): void
    {
        $id1 = PerformanceSpan::newSpanId();
        $id2 = PerformanceSpan::newSpanId();

        $this->assertNotEquals($id1, $id2);
        $this->assertEquals(16, strlen($id1));
    }
}
