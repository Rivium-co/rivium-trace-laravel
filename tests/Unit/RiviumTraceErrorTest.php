<?php

namespace RiviumTrace\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RiviumTrace\Laravel\Models\RiviumTraceError;

class RiviumTraceErrorTest extends TestCase
{
    public function test_creates_from_throwable(): void
    {
        $exception = new \RuntimeException('Test error');
        $error = RiviumTraceError::fromThrowable($exception, [
            'environment' => 'testing',
            'release' => '0.1.0',
        ]);

        $this->assertEquals('Test error', $error->message);
        $this->assertEquals('laravel', $error->platform);
        $this->assertEquals('testing', $error->environment);
        $this->assertEquals('0.1.0', $error->release);
        $this->assertNotEmpty($error->stackTrace);
        $this->assertNotEmpty($error->timestamp);
    }

    public function test_creates_from_message(): void
    {
        $error = RiviumTraceError::fromMessage('Something happened', [
            'environment' => 'production',
        ]);

        $this->assertEquals('Something happened', $error->message);
        $this->assertEquals('laravel', $error->platform);
        $this->assertEquals('production', $error->environment);
    }

    public function test_truncates_long_messages(): void
    {
        $longMessage = str_repeat('a', 2000);
        $error = RiviumTraceError::fromMessage($longMessage);

        $this->assertEquals(1000, mb_strlen($error->message));
    }

    public function test_to_array_format(): void
    {
        $error = RiviumTraceError::fromMessage('Test', [
            'environment' => 'testing',
            'release' => '0.1.0',
        ]);

        $array = $error->toArray();

        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('stack_trace', $array);
        $this->assertArrayHasKey('platform', $array);
        $this->assertArrayHasKey('environment', $array);
        $this->assertArrayHasKey('release_version', $array);
        $this->assertArrayHasKey('timestamp', $array);
        $this->assertArrayHasKey('user_agent', $array);
        $this->assertEquals('laravel', $array['platform']);
    }

    public function test_breadcrumbs_extracted_to_root(): void
    {
        $error = RiviumTraceError::fromMessage('Test', [
            'extra' => [
                'breadcrumbs' => [['message' => 'crumb1']],
                'other_data' => 'value',
            ],
        ]);

        $array = $error->toArray();

        $this->assertArrayHasKey('breadcrumbs', $array);
        $this->assertEquals([['message' => 'crumb1']], $array['breadcrumbs']);
        $this->assertArrayHasKey('extra', $array);
        $this->assertArrayNotHasKey('breadcrumbs', $array['extra']);
        $this->assertEquals('value', $array['extra']['other_data']);
    }

    public function test_set_extra(): void
    {
        $error = RiviumTraceError::fromMessage('Test');
        $error->setExtra('key', 'value');
        $error->setExtras(['a' => 1, 'b' => 2]);

        $this->assertEquals('value', $error->extra['key']);
        $this->assertEquals(1, $error->extra['a']);
        $this->assertEquals(2, $error->extra['b']);
    }

    public function test_user_agent_contains_sdk_info(): void
    {
        $error = RiviumTraceError::fromMessage('Test');

        $this->assertStringContainsString('RiviumTrace-SDK/', $error->userAgent);
        $this->assertStringContainsString('laravel', $error->userAgent);
    }

    public function test_uses_exception_class_name_when_message_empty(): void
    {
        $exception = new \RuntimeException('');
        $error = RiviumTraceError::fromThrowable($exception);

        $this->assertEquals('RuntimeException', $error->message);
    }
}
