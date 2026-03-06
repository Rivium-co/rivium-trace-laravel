<?php

namespace RiviumTrace\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RiviumTrace\Laravel\Logging\LogEntry;
use RiviumTrace\Laravel\Logging\LogLevel;

class LogEntryTest extends TestCase
{
    public function test_creates_log_entry(): void
    {
        $timestamp = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $entry = new LogEntry([
            'message' => 'Test log',
            'level' => LogLevel::INFO,
            'timestamp' => $timestamp,
            'metadata' => ['key' => 'value'],
            'userId' => 'user123',
        ]);

        $this->assertEquals('Test log', $entry->message);
        $this->assertEquals('info', $entry->level);
        $this->assertEquals($timestamp, $entry->timestamp);
        $this->assertEquals(['key' => 'value'], $entry->metadata);
        $this->assertEquals('user123', $entry->userId);
    }

    public function test_default_values(): void
    {
        $entry = new LogEntry(['message' => 'Test']);

        $this->assertEquals('info', $entry->level);
        $this->assertNull($entry->metadata);
        $this->assertNull($entry->userId);
    }

    public function test_to_array(): void
    {
        $timestamp = new \DateTimeImmutable('2026-01-01T12:00:00+00:00');
        $entry = new LogEntry([
            'message' => 'Test log',
            'level' => LogLevel::ERROR,
            'timestamp' => $timestamp,
            'metadata' => ['key' => 'value'],
            'userId' => 'user123',
        ]);

        $array = $entry->toArray();

        $this->assertEquals('Test log', $array['message']);
        $this->assertEquals('error', $array['level']);
        $this->assertArrayHasKey('timestamp', $array);
        $this->assertEquals(['key' => 'value'], $array['metadata']);
        $this->assertEquals('user123', $array['userId']);
    }

    public function test_to_array_excludes_null_fields(): void
    {
        $entry = new LogEntry([
            'message' => 'Test',
            'level' => LogLevel::INFO,
        ]);

        $array = $entry->toArray();

        $this->assertArrayNotHasKey('metadata', $array);
        $this->assertArrayNotHasKey('userId', $array);
    }

    public function test_log_level_constants(): void
    {
        $this->assertEquals('trace', LogLevel::TRACE);
        $this->assertEquals('debug', LogLevel::DEBUG);
        $this->assertEquals('info', LogLevel::INFO);
        $this->assertEquals('warn', LogLevel::WARN);
        $this->assertEquals('error', LogLevel::ERROR);
        $this->assertEquals('fatal', LogLevel::FATAL);
    }
}
