<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\ClearStatCache;
use Hypervel\Tests\TestCase;
use ReflectionClass;

/**
 * @internal
 * @coversNothing
 */
class ClearStatCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setStaticProperty('interval', 1);
        $this->setStaticProperty('lastCleared', 0);
    }

    public function testGetAndSetInterval()
    {
        $this->assertSame(1, ClearStatCache::getInterval());

        ClearStatCache::setInterval(5);
        $this->assertSame(5, ClearStatCache::getInterval());
    }

    public function testClearAlwaysClearsWhenIntervalBelowOne()
    {
        ClearStatCache::setInterval(0);

        ClearStatCache::clear();
        $firstCleared = $this->getStaticProperty('lastCleared');
        $this->assertGreaterThan(0, $firstCleared);

        // Set lastCleared to a recent timestamp — should still clear because interval < 1
        $this->setStaticProperty('lastCleared', time());
        ClearStatCache::clear();
        $this->assertGreaterThanOrEqual($firstCleared, $this->getStaticProperty('lastCleared'));
    }

    public function testClearClearsOnFirstCallWhenNeverCleared()
    {
        ClearStatCache::setInterval(3600);

        $this->assertSame(0, $this->getStaticProperty('lastCleared'));

        ClearStatCache::clear();

        $this->assertGreaterThan(0, $this->getStaticProperty('lastCleared'));
    }

    public function testClearSkipsWhenIntervalHasNotElapsed()
    {
        ClearStatCache::setInterval(3600);

        // Simulate a recent clear
        $recentTimestamp = time();
        $this->setStaticProperty('lastCleared', $recentTimestamp);

        ClearStatCache::clear();

        // lastCleared should be unchanged — clear was skipped
        $this->assertSame($recentTimestamp, $this->getStaticProperty('lastCleared'));
    }

    public function testClearRunsWhenIntervalHasElapsed()
    {
        ClearStatCache::setInterval(10);

        // Simulate a clear that happened 20 seconds ago
        $oldTimestamp = time() - 20;
        $this->setStaticProperty('lastCleared', $oldTimestamp);

        ClearStatCache::clear();

        // lastCleared should be updated — interval elapsed
        $this->assertGreaterThan($oldTimestamp, $this->getStaticProperty('lastCleared'));
    }

    private function getStaticProperty(string $name): mixed
    {
        $reflection = new ReflectionClass(ClearStatCache::class);
        return $reflection->getStaticPropertyValue($name);
    }

    private function setStaticProperty(string $name, mixed $value): void
    {
        $reflection = new ReflectionClass(ClearStatCache::class);
        $reflection->setStaticPropertyValue($name, $value);
    }
}
