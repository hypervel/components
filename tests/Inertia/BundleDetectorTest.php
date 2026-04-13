<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Inertia\Ssr\BundleDetector;

/**
 * @internal
 * @coversNothing
 */
class BundleDetectorTest extends TestCase
{
    public function testDetectCachesResultForWorkerLifetime()
    {
        config()->set('inertia.ssr.bundle', __FILE__);

        $detector = new BundleDetector;

        $first = $detector->detect();
        $this->assertSame(__FILE__, $first);

        // Change config — cached result should still be returned
        config()->set('inertia.ssr.bundle', '/nonexistent/path');

        $second = $detector->detect();
        $this->assertSame(__FILE__, $second);
    }

    public function testFlushStateResetsCache()
    {
        config()->set('inertia.ssr.bundle', __FILE__);

        $detector = new BundleDetector;
        $detector->detect();

        BundleDetector::flushState();

        // After flush, config change is picked up
        config()->set('inertia.ssr.bundle', null);

        $this->assertNull($detector->detect());
    }
}
