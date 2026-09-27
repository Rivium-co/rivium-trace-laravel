# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.1] - 2026-09-28

### Fixed
- `captureMessage()` messages now go to the Messages list instead of becoming issues.
  Options: `level` (`debug`, `info`, `warning`, `error`), `extra`, `tags`.

### Changed
- `before_send` receives a `RiviumTraceMessage` for messages. Type-hint
  `RiviumTraceError|RiviumTraceMessage` if your hook handles both.

## [0.2.0] - 2026-09-25

### Added
- `before_send`: a hook that receives each error before it is sent. Return it
  to send, return a changed one, or return `null` to drop it — for scrubbing
  sensitive values or filtering noise.

### Fixed
- The SDK version reported to Rivium Trace was stale.

## [0.1.2] - 2026-08-15

### Added
- Self-hosted setup documentation.

## [0.1.0] - 2026-08-10

- First release: error reporting, logging, breadcrumbs and performance spans
  for Laravel.
