<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Facades\ParallelTesting;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\TestingServiceProvider;

class TestWithoutDatabaseParallelTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [TestingServiceProvider::class];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app['config']->set('database.default', null);

        $serverKeys = [
            'HYPERVEL_PARALLEL_TESTING',
            'HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES',
            'TEST_TOKEN',
        ];
        $serverValues = array_intersect_key($_SERVER, array_flip($serverKeys));

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = 1;
        $_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES'] = 1;
        $_SERVER['TEST_TOKEN'] = '1';

        $this->beforeApplicationDestroyed(function () use ($serverValues): void {
            unset(
                $_SERVER['HYPERVEL_PARALLEL_TESTING'],
                $_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES'],
                $_SERVER['TEST_TOKEN'],
            );

            foreach ($serverValues as $key => $value) {
                $_SERVER[$key] = $value;
            }
        });
    }

    public function testRunningParallelTestWithoutDatabaseShouldNotCrashOnDefaultConnection(): void
    {
        ParallelTesting::callSetUpProcessCallbacks();
        $this->assertTrue(true);
    }
}
