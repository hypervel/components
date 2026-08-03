<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Contracts\Redis\Factory;
use Hypervel\Horizon\Lock;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;

class LockTest extends IntegrationTestCase
{
    public function testExpiredOwnerCannotDeleteAReplacementLock(): void
    {
        $connection = $this->app->make(Factory::class)->connection('horizon');
        $lock = $this->app->make(Lock::class);

        $lock->with('owned-lock', static function () use ($connection): void {
            $connection->set('owned-lock', 'replacement-owner', 'EX', 60);
        });

        $this->assertSame('replacement-owner', $connection->get('owned-lock'));
    }

    public function testReleaseRemainsAnExplicitForceRelease(): void
    {
        $connection = $this->app->make(Factory::class)->connection('horizon');
        $connection->set('owned-lock', 'another-owner', 'EX', 60);

        $this->app->make(Lock::class)->release('owned-lock');

        $this->assertNull($connection->get('owned-lock'));
    }
}
