<?php

namespace RiviumTrace\Laravel\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use RiviumTrace\Laravel\RiviumTrace;

class RiviumTraceLogChannel
{
    public function __invoke(array $config): Logger
    {
        return new Logger('riviumtrace', [new RiviumTraceMonologHandler()]);
    }
}

class RiviumTraceMonologHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        try {
            $sdk = app(RiviumTrace::class);
            $lvl = $this->translateLevel($record->level);
            $meta = ! empty($record->context) ? $record->context : null;
            $sdk->log($record->message, $lvl, $meta);
        } catch (\Throwable) {
        }
    }

    private function translateLevel(Level $lvl): string
    {
        return match ($lvl) {
            Level::Debug => LogLevel::DEBUG,
            Level::Info, Level::Notice => LogLevel::INFO,
            Level::Warning => LogLevel::WARN,
            Level::Error => LogLevel::ERROR,
            Level::Critical, Level::Alert, Level::Emergency => LogLevel::FATAL,
        };
    }
}
