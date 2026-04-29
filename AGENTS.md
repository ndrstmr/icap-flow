# Repository Guidelines

## Project Structure & Module Organization

This is a PHP 8.4 Composer library for an async-ready ICAP client. Production code lives in `src/` under the `Ndrstmr\Icap\` PSR-4 namespace. Key areas are `src/DTO/`, `src/Exception/`, `src/Transport/`, and `src/Cache/`. Tests live in `tests/`; integration tests are isolated in `tests/Integration/`. Examples are in `examples/` and `examples/cookbook/`. Design notes, audits, and roadmap material are in `docs/`; treat review task lists as historical unless confirmed by `CHANGELOG.md` and Git tags. Current local tags include `v2.1.2`.

## Build, Test, and Development Commands

- `composer install`: install runtime and development dependencies.
- `composer test`: run the Pest unit suite.
- `composer test:integration`: run integration tests; see `tests/Integration/README.md` and `docker-compose.yml` for local ICAP service setup.
- `composer test:all`: run all Pest suites.
- `composer stan`: run PHPStan with a 1 GB memory limit.
- `composer cs-check`: check formatting and EUPL headers without changes.
- `composer cs-fix`: apply php-cs-fixer formatting.
- `composer mutation`: run covered unit mutation tests with minimum score 65.

## Coding Style & Naming Conventions

Follow PSR-12 with `declare(strict_types=1);`, 4-space indentation, typed properties and parameters, and explicit return types where practical. Class names use `PascalCase`; methods and variables use `camelCase`; test files end in `Test.php`. Keep namespaces aligned with paths, for example `src/Transport/AmpConnectionPool.php` maps to `Ndrstmr\Icap\Transport\AmpConnectionPool`. New PHP files in `src/` and `tests/` must keep the EUPL-1.2 header enforced by php-cs-fixer.

## Testing Guidelines

The project uses Pest on PHPUnit 11. PHPUnit fails on risky tests, warnings, and unexpected output, so avoid debug output and add explicit assertions. Add focused tests for parser, transport, retry, cancellation, and security-sensitive behavior when touching those areas. Keep external-service checks in `tests/Integration/`.

## Commit & Pull Request Guidelines

Recent history uses Conventional Commit-style subjects such as `fix(v2.1): ...` and `docs(review): ...`. Keep subjects imperative, scoped when useful, and concise. Before opening a PR, run `composer test`, `composer stan`, and `composer cs-check`. PRs should describe behavior changes, link issues, mention integration-test needs, and update examples or docs for public API changes.

## Security & Configuration Tips

Do not commit credentials, private ICAP endpoints, or generated coverage/build artifacts. Security-sensitive changes should review `SECURITY.md`, include negative-path tests, and preserve fail-secure handling for malformed responses, timeouts, parser limits, and streaming large files.
