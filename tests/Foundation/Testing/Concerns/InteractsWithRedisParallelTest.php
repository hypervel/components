<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Testbench\TestCase;

/**
 * Tests for the parallel testing helpers in InteractsWithRedis.
 *
 * These test the DB number computation logic, not actual Redis connections.
 *
 * @internal
 * @coversNothing
 */
class InteractsWithRedisParallelTest extends TestCase
{
    use InteractsWithRedis;

    public function testGetBaseRedisDbReturnsEnvValue()
    {
        // Default matches database.php: env('REDIS_DB', 0)
        $this->assertSame((int) env('REDIS_DB', 0), $this->getBaseRedisDb());
    }

    public function testGetParallelRedisDbReturnsBaseWhenNoToken()
    {
        // Without TEST_TOKEN, should return the base DB
        if (env('TEST_TOKEN') !== null) {
            $this->markTestSkipped('Cannot test sequential behavior when TEST_TOKEN is set');
        }

        $this->assertSame($this->getBaseRedisDb(), $this->getParallelRedisDb());
    }

    public function testGetSecondaryRedisDbDiffersFromPrimaryInSequentialMode()
    {
        if (env('TEST_TOKEN') !== null) {
            $this->markTestSkipped('Cannot test sequential behavior when TEST_TOKEN is set');
        }

        $primary = $this->getParallelRedisDb();
        $secondary = $this->getSecondaryRedisDb();

        $this->assertNotSame($primary, $secondary);
    }

    public function testGetSecondaryRedisDbIsBasePlusOneInSequentialMode()
    {
        if (env('TEST_TOKEN') !== null) {
            $this->markTestSkipped('Cannot test sequential behavior when TEST_TOKEN is set');
        }

        $base = $this->getBaseRedisDb();

        $this->assertSame($base + 1, $this->getSecondaryRedisDb());
    }
}
