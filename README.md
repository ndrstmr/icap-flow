# icap-flow

<!-- Badges -->
[![Build Status](https://img.shields.io/github/actions/workflow/status/ndrstmr/icap-flow/ci.yml?branch=main)](https://github.com/ndrstmr/icap-flow/actions)
[![Latest Stable Version](https://img.shields.io/packagist/v/ndrstmr/icap-flow?label=stable)](https://packagist.org/packages/ndrstmr/icap-flow)
[![Total Downloads](https://img.shields.io/packagist/dt/ndrstmr/icap-flow)](https://packagist.org/packages/ndrstmr/icap-flow)
[![License](https://img.shields.io/github/license/ndrstmr/icap-flow)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/ndrstmr/icap-flow)](https://www.php.net/)
[![Static Analysis](https://img.shields.io/badge/static%20analysis-level%207-brightgreen)](phpstan.neon)

A modern, robust, and async-ready ICAP (Internet Content Adaptation Protocol) client for PHP 8.3+.

## Project Vision

This library aims to be the de-facto standard solution for PHP developers needing ICAP connectivity, focusing on quality, performance, and an excellent developer experience. For more details, see our [mission charter](docs/agent.md).

## Installation

```bash
composer require ndrstmr/icap-flow
```

## Basic Usage

For most projects, the `SynchronousIcapClient` offers a very simple, blocking API.

```php
$icap = SynchronousIcapClient::create();

$result = $icap->scanFile('/service', '/path/to/your/file.txt');

echo $result->isInfected()
    ? 'Virus found: ' . $result->getVirusName()
    : 'File is clean';
```

## Advanced Usage: Asynchronous Requests

To take advantage of non-blocking I/O, interact with the `IcapClient` directly
within an event loop:

```php
use Revolt\EventLoop;

$icap = IcapClient::create();

EventLoop::run(function () use ($icap) {
    $future = $icap->scanFile('/service', '/path/to/your/file.txt');
    $result = $future->await();

    echo $result->isInfected()
        ? 'Virus: ' . $result->getVirusName() . PHP_EOL
        : 'File is clean' . PHP_EOL;
});
```

## Configuration

Adjust the connection settings using the `Config` DTO:

```php
use Ndrstmr\Icap\Config;

$config = new Config(
    host: 'icap.example.com',
    port: 1344,
    socketTimeout: 5.0,
    streamTimeout: 30.0,
    // Header used by the ICAP server to report infections
    virusFoundHeader: 'X-Virus-Name',
);
```

This object can be passed to the client factory methods.

## Extensibility (Cookbook)

Preview handling uses the Strategy pattern. Custom strategies implement
`PreviewStrategyInterface` and can be registered on the client. Detailed
examples can be found in the [`examples/cookbook/`](examples/cookbook/) directory.

## For Developers

Run the test suite with the following command:

```bash
composer test
```

Further details about the pull request workflow can be found in
[CONTRIBUTING.md](CONTRIBUTING.md).

## License

This project is licensed under the EUPL-1.2 License. See the LICENSE file for details.

## Changelog

A list of all changes can be found in [CHANGELOG.md](CHANGELOG.md).

