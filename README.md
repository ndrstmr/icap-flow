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

$response = $icap->scanFile('/service', '/path/to/your/file.txt');

echo 'ICAP Status: ' . $response->statusCode;
```

## Advanced Usage: Asynchronous Requests

To take advantage of non-blocking I/O, interact with the `IcapClient` directly
within an event loop:

```php
use Revolt\EventLoop;

$icap = IcapClient::create();

EventLoop::run(function () use ($icap) {
    $future = $icap->scanFile('/service', '/path/to/your/file.txt');
    $response = $future->await();

    echo 'ICAP Status: ' . $response->statusCode . PHP_EOL;
});
```

## Konfiguration

Passen Sie die Verbindungseinstellungen mit dem `Config`-DTO an:

```php
use Ndrstmr\Icap\Config;

$config = new Config(
    host: 'icap.example.com',
    port: 1344,
    socketTimeout: 5.0,
    streamTimeout: 30.0,
);
```

Dieses Objekt kann an die Factory-Methoden der Clients übergeben werden.

## Erweiterbarkeit (Cookbook)

Die Behandlung von Preview-Antworten erfolgt über das Strategy Pattern. Eigene
Strategien implementieren das `PreviewStrategyInterface` und können beim
Client hinterlegt werden. Ausführliche Beispiele finden Sie im Verzeichnis
[`examples/cookbook/`](examples/cookbook/).

## Für Entwickler

Tests führen Sie mit dem folgenden Befehl aus:

```bash
composer test
```

Weitere Hinweise zum Ablauf von Pull Requests finden Sie in der
[CONTRIBUTING.md](CONTRIBUTING.md).

## License

This project is licensed under the EUPL-1.2 License. See the LICENSE file for details.

## Changelog

Eine Liste aller Veränderungen finden Sie in der [CHANGELOG.md](CHANGELOG.md).

