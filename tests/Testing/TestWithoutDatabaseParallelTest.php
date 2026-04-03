<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Facades\ParallelTesting;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\TestingServiceProvider;

/**
 * @internal
 * @coversNothing
 */
class TestWithoutDatabaseParallelTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [TestingServiceProvider::class];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app['config']->set('database.default', null);

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = 1;
        $_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES'] = 1;
        $_SERVER['TEST_TOKEN'] = '1';

        $this->beforeApplicationDestroyed(function () {
            unset(
                $_SERVER['HYPERVEL_PARALLEL_TESTING'],
                $_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES'],
                $_SERVER['TEST_TOKEN'],
            );
        });
    }

    public function testRunningParallelTestWithoutDatabaseShouldNotCrashOnDefaultConnection()
    {
        ParallelTesting::callSetUpProcessCallbacks();
        $this->assertTrue(true);
    }
}
