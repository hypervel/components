<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan;

use Hypervel\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Large;

/**
 * The in-process suite boots a package, whose analysis leaves the values packages merge
 * unknown, so an application's own analysis checks how merged package files compose.
 */
#[Large]
class ConfigCompositionTest extends ResultCacheTestCase
{
    protected string $fixture = 'ConfigComposition';

    public function testApplicationAnalysisTypesMergedPackageFiles(): void
    {
        $applicationPath = $this->copyApplication();
        (new Filesystem)->copyDirectory(__DIR__ . '/Fixtures/Config/application', $applicationPath . '/config');

        // Each assertType() call that does not hold is reported.
        $this->assertSame([], $this->diagnostics($this->analyse($applicationPath), 0));
    }
}
