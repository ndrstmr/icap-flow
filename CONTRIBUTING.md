# Contributing Guide

We welcome community contributions and bug reports. Please read the instructions
below before opening an issue or submitting a pull request.

## How to Report a Bug

1. Open an issue on GitHub describing the problem in as much detail as possible.
2. Provide a short code snippet that reproduces the issue if possible.
3. Include information about your PHP version and operating system.

## How to Suggest a Feature

1. Open an issue on GitHub outlining your idea.
2. Describe the benefit for other users.

## Pull Request Process

1. Fork the repository and create a branch for your changes.
2. Implement your code and run all quality gates:
   ```bash
   composer cs-fix             # apply PSR-12 formatting
   composer stan               # PHPStan Level 9 + bleedingEdge — must be clean
   composer test               # Pest unit suite — must be green
   ```
3. Open a pull request against the `main` branch and reference any related issues.

## Commit Convention

This repository follows [Conventional Commits 1.0](https://www.conventionalcommits.org/en/v1.0.0/).

### Format

```
<type>(<scope>): <subject>

<body>

<footer>
```

### Types

`feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`, `revert` — do not invent custom types.

### Scopes

Use when it adds clarity. Common scopes: `transport`, `wire`, `preview`, `pool`, `cache`, `security`, `IcapClient`.

### Rules

- **Subject:** imperative mood, lowercase, no trailing period, max ~70 characters.
- **Body:** mandatory. Explain *why*, not *what* — the diff shows the what.
- **Footer:** use `Closes #XX` (not `Refs`) to auto-close GitHub issues. `BREAKING CHANGE:` for any BC-break.

### Examples

```
feat(cache): add PSR-16 OPTIONS-cache adapter

Production deployments with Redis or APCu can now plug their existing
PSR-16 CacheInterface into the OPTIONS cache without implementing
OptionsCacheInterface from scratch.

Closes #81
```

```
fix(transport): use per-IO timeout instead of session-lifetime timer

The session-lifetime timer accumulated across preview-continue phases,
causing spurious CancelledException on slow but legitimate scans.
```

## Coding Standards

- [PSR-12](https://www.php-fig.org/psr/psr-12/) enforced via `composer cs-fix` (php-cs-fixer).
- PHP 8.4 minimum. `final class` on all classes in `src/`. `#[\Override]` on every interface implementation.
- PHPStan Level 9 + bleedingEdge — no baseline, no `@phpstan-ignore`.
- EUPL-1.2 license header required in every PHP file (applied automatically by cs-fix).
