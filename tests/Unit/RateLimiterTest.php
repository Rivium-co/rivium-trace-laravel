<?php

namespace RiviumTrace\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RiviumTrace\Laravel\Models\RiviumTraceError;
use RiviumTrace\Laravel\Utils\RateLimiter;

class RateLimiterTest extends TestCase
{
    public function test_allows_first_error(): void
    {
        $limiter = new RateLimiter(60, 10, 100);
        $error = RiviumTraceError::fromMessage('Test error', ['environment' => 'testing']);

        $result = $limiter->shouldSendError($error);

        $this->assertTrue($result['allowed']);
    }

    public function test_blocks_when_per_error_limit_exceeded(): void
    {
        $limiter = new RateLimiter(60, 3, 100);
        $error = RiviumTraceError::fromMessage('Same error', ['environment' => 'testing']);

        for ($i = 0; $i < 3; $i++) {
            $result = $limiter->shouldSendError($error);
            $this->assertTrue($result['allowed']);
        }

        $result = $limiter->shouldSendError($error);
        $this->assertFalse($result['allowed']);
        $this->assertEquals('error_limit_exceeded', $result['reason']);
    }

    public function test_blocks_when_total_limit_exceeded(): void
    {
        $limiter = new RateLimiter(60, 100, 5);

        for ($i = 0; $i < 5; $i++) {
            $error = RiviumTraceError::fromMessage("Error {$i}", ['environment' => 'testing']);
            $result = $limiter->shouldSendError($error);
            $this->assertTrue($result['allowed']);
        }

        $error = RiviumTraceError::fromMessage('One more', ['environment' => 'testing']);
        $result = $limiter->shouldSendError($error);
        $this->assertFalse($result['allowed']);
        $this->assertEquals('total_limit_exceeded', $result['reason']);
    }

    public function test_reset_clears_limits(): void
    {
        $limiter = new RateLimiter(60, 1, 100);
        $error = RiviumTraceError::fromMessage('Test', ['environment' => 'testing']);

        $limiter->shouldSendError($error);
        $limiter->shouldSendError($error);

        $limiter->reset();

        $result = $limiter->shouldSendError($error);
        $this->assertTrue($result['allowed']);
    }

    public function test_get_stats(): void
    {
        $limiter = new RateLimiter(60, 10, 100);
        $stats = $limiter->getStats();

        $this->assertArrayHasKey('in_memory_total', $stats);
        $this->assertArrayHasKey('in_memory_unique_errors', $stats);
        $this->assertArrayHasKey('total_limit', $stats);
        $this->assertArrayHasKey('error_limit', $stats);
        $this->assertArrayHasKey('window_seconds', $stats);
    }
}
