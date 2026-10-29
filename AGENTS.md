# Repository Guidelines

## Project Structure & Module Organization

This is a PHP 8.4 Composer library for an async-ready ICAP client. Production code lives in `src/` under the `Ndrstmr\Icap\` PSR-4 namespace. Key areas are `src/DTO/`, `src/Exception/`, `src/Transport/`, and `src/Cache/`. Tests live in `tests/`; integration tests are isolated in `tests/Integration/`. Examples are in `examples/` and `examples/cookbook/`. Design notes, audits, and roadmap material are in `docs/`; treat review task lists as historical unless confirmed by `CHANGELOG.md` and Git tags. Current local tags include `v2.1.2`.

## Agent Worktrees

Every DEV-agent must work in its own assigned Git worktree under `.worktrees/<task-name>/` in the main checkout's repository root. This directory is gitignored via `/.worktrees/`. Do not create agent worktrees outside that directory or inside another worktree.

Name branches by purpose, such as `fix/`, `refactor/`, `docs/` or `feat/`. Never use `codex/` or other agent prefixes.

The main checkout retains the untracked task lists, audits, and evidence. Copy required context into the assigned worktree; preserve the originals and do not stage, move, delete, stash, reset, or clean them. Keep supplied context copies out of implementation changes. Install dependencies in the assigned worktree; do not use a writable `vendor` symlink to the main checkout.

Before editing, verify `pwd`, `git rev-parse --show-toplevel`, `git worktree list --porcelain`, and `git status --short`. Report the worktree path, HEAD, and initial working-tree status with the review submission.

## Engineering Standard

- Follow the current authorised task and confirmed plan; historical roadmaps do not add work. Deliver the requested ICAP-client behaviour completely with the simplest robust design. The scan engine and application policy stay outside this library.
- Use clear, idiomatic modern PHP and the existing AMPHP/Revolt stack. Optimise for correctness, bounded resource use, performance and maintainability; preserve required security checks and public API contracts. Explicitly assess required API breaks against the authorised release scope.
- Apply SOLID, separation of concerns, DRY, KISS and YAGNI together: cohesive responsibilities, extension through established contracts, substitutable implementations, narrow interfaces and dependency inversion at component boundaries. Simplicity must preserve sound design; extra layers alone do not establish compliance.
- Add abstractions, configuration, dependencies and helpers only when they simplify a concrete requirement. Avoid speculative extensibility, parallel frameworks and automatic interface/DTO/exception layers.
- Test observable behaviour and relevant failure paths. Reuse existing tests and fixtures where practical; introduce helpers only for a concrete regression. Keep the evidence needed to detect failures.
- Keep comments and documentation concise. Explain non-obvious decisions, constraints and public contracts; omit code narration, work-order history and repeated explanations. Create reports only when requested.
- Exercise engineering judgement independently within the authorised scope. Acceptance includes design simplicity and proportional complexity; counts of lines, classes, tests, assertions or mutants are not quality targets.

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

The project uses Pest on PHPUnit 11. PHPUnit fails on risky tests, warnings, and unexpected output, so avoid debug output and add explicit assertions. Pest 3 still exits with 0 for risky and skipped tests; CI checks PHPUnit's own verdict (`.github/phpunit-verdict.sh`). Add focused tests for parser, transport, retry, cancellation, and security-sensitive behavior when touching those areas. Keep external-service checks in `tests/Integration/`.

## Commit & Pull Request Guidelines

Recent history uses Conventional Commit-style subjects such as `fix(v2.1): ...` and `docs(review): ...`. Keep subjects imperative, scoped when useful, and concise. Before opening a PR, run `composer test`, `composer stan`, and `composer cs-check`. PRs should describe behavior changes, link issues, mention integration-test needs, and update examples or docs for public API changes.

## Security & Configuration Tips

Do not commit credentials, private ICAP endpoints, or generated coverage/build artifacts. Security-sensitive changes should review `SECURITY.md`, include negative-path tests, and preserve fail-secure handling for malformed responses, timeouts, parser limits, and streaming large files.

## Product Scope & Delivery

Build a small PHP ICAP client for document virus scanning. The ICAP server owns the scan engine; the calling application owns quarantine, access control, and release policy. Keep client responsibilities focused on protocol correctness, scan-result interpretation, bounded I/O, cancellation, and reliable failures.

Judge a work package by the client defect it fixes and a regression test that fails before the fix and passes afterward. Start with existing tests; add helpers only when a concrete client regression needs them. Use real sockets, TLS, or isolated subprocesses when the affected behavior requires them. Do not build a general security or test framework in advance. Keep submissions brief and limited to behavior, relevant evidence, and remaining limits.
