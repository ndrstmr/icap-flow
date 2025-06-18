# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-06-18
### Added
- Initial release of the IcapFlow library.
- User-friendly `ScanResult` DTO for easy interpretation of scan results.
- Dual API with a fully asynchronous core (`IcapClient`) and a simple `SynchronousIcapClient` wrapper.
- Extensible Preview-Handling via the `PreviewStrategyInterface`.
- Factory methods (`::create()`) for easy, dependency-free instantiation.
- Support for ICAP `REQMOD`, `RESPMOD`, and `OPTIONS` methods.
- Advanced, memory-safe streaming for large file processing via stream resources.
- Comprehensive test suite (>80% coverage), static analysis (PHPStan Level 9), and a full CI/CD pipeline.

