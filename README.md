# icap-flow
A modern, robust, and async-ready ICAP (Internet Content Adaptation Protocol) client for PHP 8.3+.

![GitHub Workflow Status](https://img.shields.io/github/actions/workflow/status/ndrstmr/icap-flow/ci.yml?branch=main)
![License](https://img.shields.io/github/license/ndrstmr/icap-flow)

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

## Contributing

Contributions are welcome! Please refer to the project's mission and development guidelines.

## License

This project is licensed under the EUPL-1.2 License. See the LICENSE file for details.

