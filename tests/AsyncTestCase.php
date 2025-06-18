<?php

namespace Ndrstmr\Icap\Tests;

use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

abstract class AsyncTestCase extends TestCase
{
    public function runAsyncTest(\Closure $test): void
    {
        EventLoop::queue($test);
        EventLoop::run();
    }
}
