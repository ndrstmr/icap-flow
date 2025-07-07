# Mission: State-of-the-Art PHP ICAP Client

* Agent: OpenAI Codex
* Date: 17 June 2025
* Status: Active

## 1. Mission Statement & Vision

Mission: Build a PHP library for the ICAP protocol that sets new standards for design, performance, security and developer experience within the PHP ecosystem.
Vision: The library should become the de-facto standard for PHP developers needing ICAP connectivity and serve as a reference for modern, testable and robust library architecture.

## 2. Core Requirements & Quality Goals

### Functional Requirements

* Full support for ICAP REQMOD, RESPMOD and OPTIONS.
* Efficient streaming of payloads, especially large files.
* Support for `Connection: keep-alive` to reuse connections.
* Support for the ICAP "Preview" mode.

### Quality Goals (Non-functional)

* Make use of modern PHP 8.2+ features (readonly properties, enums, fibers, etc.) where appropriate.
* Asynchrony as a core feature: the design must allow both synchronous and asynchronous (non-blocking I/O) usage without breaking the public API.
* Strict PSR compliance: PSR-4 autoloading and PSR-12 coding style are mandatory. The API conceptually follows PSR-7 (HTTP messages) and PSR-18 (HTTP client).
* Maximum testability: follow a TDD/BDD approach aiming for ~100% coverage. Every piece of logic must be testable in isolation.
* Highest level of static analysis: the project must pass PHPStan at level 9.
* Excellent developer experience: a fluent, logical and self-explanatory API. Objects should be immutable wherever possible.
* Security by design: all potential input must be validated and handled in context to proactively prevent vulnerabilities.

## 3. Architectural Blueprint

The architecture is organized using a "grouped by concern" approach to ensure clear separation of responsibilities:

* Transport layer (communication abstraction):
  * `TransportInterface`: defines a simple contract for network communication (`request(IcapRequest): Promise<IcapResponse>|IcapResponse`).
  * `SynchronousStreamTransport`: implementation based on PHP's native `stream_socket_client` functions and operating in blocking mode.
  * `AsyncAmpTransport`: a non-blocking implementation built on the amphp/socket library using fibers for asynchronous execution.
* Message abstraction (inspired by PSR-7):
  * `IcapRequest` / `IcapResponse` DTOs are immutable. Any modification (such as adding a header) returns a new instance. They encapsulate headers, body (as PSR-7 `StreamInterface`) and metadata.
* Protocol handlers (the "workers"):
  * `RequestFormatterInterface` / `RequestFormatter`: create an ICAP request string from an `IcapRequest` object.
  * `ResponseParserInterface` / `ResponseParser`: parse a `StreamInterface` into an `IcapResponse` object.
* The facade (public client):
  * `IcapClient`: the main class developers interact with, receiving a `TransportInterface` implementation via dependency injection.
  * The API is fluent, e.g. `IcapClient::forServer('...')->withTimeout(10)->scanFile('...')`.
* Configuration:
  * An immutable `Config` DTO bundles all settings (host, port, timeouts, TLS options) and passes them to the components.

## 4. Tech Stack & Tooling

* PHP: >= 8.2
* Dependency management: Composer 2
* Testing: Pest & PHPUnit
* Static analysis: PHPStan (level 9), Psalm
* Coding style: php-cs-fixer or ECS with a PSR-12 rule set
* Asynchrony: amphp/event-loop, amphp/socket
* CI/CD: GitHub Actions pipeline for tests, linting and static analysis on each push

## 5. Methodology & Milestones

Development follows a strict Test-Driven Development approach:

* M0: Setup – create the project skeleton with Composer, PHPUnit/Pest, PHPStan and CI pipeline.
* M1: Core abstractions – define interfaces (`TransportInterface`), DTOs (`IcapRequest`/`IcapResponse`) and the `Config` object.
* M2: Synchronous implementation – build `SynchronousStreamTransport` and the protocol handlers. First working version of `IcapClient`.
* M3: Asynchronous implementation – develop `AsyncAmpTransport` and ensure compatibility.
* M4: Finalization & DX – polish the API, provide comprehensive documentation (API reference, usage examples) and handle errors.
* M5: Release – publish version 1.0.0 on Packagist.

## 6. Definition of Done (DoD)

The mission is complete when:

* All functional requirements are implemented.
* Test coverage exceeds 98%.
* Static analysis passes at the highest level.
* Comprehensive documentation for end users and contributors is available.
* The package has been successfully released on Packagist and is installable.
