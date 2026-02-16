<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\ClearStatCache;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ClearStatCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ClearStatCache::reset();
        TrackingClearStatCache::reset();
        TrackingClearStatCache::$clearCount = 0;
    }

    public function testGetAndSetInterval()
    {
        $this->assertSame(1, ClearStatCache::getInterval());

        ClearStatCache::setInterval(5);
        $this->assertSame(5, ClearStatCache::getInterval());
    }

    public function testResetRestoresDefaults()
    {
        ClearStatCache::setInterval(99);
        ClearStatCache::reset();

        $this->assertSame(1, ClearStatCache::getInterval());
    }

    public function testClearAlwaysClearsWhenIntervalBelowOne()
    {
        TrackingClearStatCache::setInterval(0);

        TrackingClearStatCache::clear();
        TrackingClearStatCache::clear();
        TrackingClearStatCache::clear();

        $this->assertSame(3, TrackingClearStatCache::$clearCount);
    }

    public function testClearClearsOnFirstCallWhenNeverCleared()
    {
        TrackingClearStatCache::setInterval(3600);

        TrackingClearStatCache::clear();

        $this->assertSame(1, TrackingClearStatCache::$clearCount);
    }

    public function testClearSkipsWhenIntervalHasNotElapsed()
    {
        TrackingClearStatCache::setInterval(3600);

        // First call clears (never cleared before)
        TrackingClearStatCache::clear();
        $this->assertSame(1, TrackingClearStatCache::$clearCount);

        // Second call should be skipped — interval hasn't elapsed
        TrackingClearStatCache::clear();
        $this->assertSame(1, TrackingClearStatCache::$clearCount);
    }

    public function testForceClearWithFilename()
    {
        $file = tempnam(sys_get_temp_dir(), 'clearstat');
        file_put_contents($file, 'test');

        // This should not throw — just verifying it accepts a filename
        ClearStatCache::forceClear($file);

        unlink($file);
        $this->assertTrue(true);
    }

    public function testForceClearWithoutFilename()
    {
        // This should not throw — just verifying it works without a filename
        ClearStatCache::forceClear();

        $this->assertTrue(true);
    }
}

/**
 * Testable subclass that tracks whether forceClear was called.
 */
class TrackingClearStatCache extends ClearStatCache
{
    public static int $clearCount = 0;

    public static function forceClear(?string $filename = null): void
    {
        ++static::$clearCount;
        parent::forceClear($filename);
    }
}
