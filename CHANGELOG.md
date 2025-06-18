# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-06-18
### Added
- Core `IcapClient` with asynchronous API returning `Amp\Future` values.
- `SynchronousIcapClient` wrapper for blocking usage.
- Immutable `IcapRequest` and `IcapResponse` DTOs with helper methods.
- `Config` object to configure host, port and timeout settings.
- `RequestFormatter` and `ResponseParser` to convert between objects and raw strings.
- `TransportInterface` with `AsyncAmpTransport` and `SynchronousStreamTransport` implementations.
- Strategy pattern for preview handling with a default implementation.
- Factory helpers (`create()` / `forServer()`) for quick client setup.
- Convenience methods to scan files with optional preview support.
- Comprehensive test suite, static analysis and CI pipeline.

