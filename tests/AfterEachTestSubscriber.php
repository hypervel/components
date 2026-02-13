<?php

declare(strict_types=1);

namespace Hypervel\Tests;

use Mockery;
use PHPUnit\Event\Test\AfterTestMethodFinished;
use PHPUnit\Event\Test\AfterTestMethodFinishedSubscriber;

/**
 * PHPUnit subscriber that runs Mockery cleanup after each test method.
 *
 * This centralizes Mockery teardown, ensuring cleanup happens even when
 * tests exit early or throw exceptions. Individual tests no longer need
 * explicit m::close() calls in their tearDown methods.
 */
final class AfterEachTestSubscriber implements AfterTestMethodFinishedSubscriber
{
    public function notify(AfterTestMethodFinished $event): void
    {
        if (class_exists(Mockery::class)) {
            Mockery::close();
        }
    }
}
