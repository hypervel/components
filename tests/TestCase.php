<?php

declare(strict_types=1);

namespace Hypervel\Tests;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Hypervel\Support\Sleep;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        Sleep::fake(false);
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
}
