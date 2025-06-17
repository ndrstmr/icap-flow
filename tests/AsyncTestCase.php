<?php

namespace Ndrstmr\Icap\Tests;

use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

abstract class AsyncTestCase extends TestCase
{
    protected function runAsyncTest(callable $test): void
    {
        EventLoop::queue($test);
        EventLoop::run();
    }
}
