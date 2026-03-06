<?php

namespace RiviumTrace\Laravel\Logging;

class LogEntry
{
    public string $message;
    public string $level;
    public \DateTimeInterface $timestamp;
    public ?array $metadata;
    public ?string $userId;

    public function __construct(array $data)
    {
        $this->message = $data['message'] ?? '';
        $this->level = $data['level'] ?? LogLevel::INFO;
        $this->timestamp = $data['timestamp'] ?? now();
        $this->metadata = $data['metadata'] ?? null;
        $this->userId = $data['userId'] ?? null;
    }

    public function toArray(): array
    {
        return array_filter([
            'message' => $this->message,
            'level' => $this->level,
            'timestamp' => $this->timestamp->format('c'),
            'metadata' => $this->metadata,
            'userId' => $this->userId,
        ], fn ($v) => $v !== null);
    }
}
