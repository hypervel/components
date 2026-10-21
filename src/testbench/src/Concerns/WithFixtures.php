<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Hypervel\Support\Str;

use function Hypervel\Testbench\filename_from_classname;

/**
 * @api
 */
trait WithFixtures
{
    /**
     * Set up test case to include fixture file using ".fixtures.php" suffix if it's available.
     */
    protected static function setupWithFixturesForTestingEnvironment(): void
    {
        // Pest-specific fixture discovery is intentionally unsupported.
        $classFileName = filename_from_classname(static::class);

        if ($classFileName === false) {
            return;
        }

        if (! is_file($fixtureFileName = Str::replaceLast('.php', '.fixtures.php', $classFileName))) {
            return;
        }

        // ParaTest may run the same test class more than once in a worker, so fixtures must only be declared once.
        require_once $fixtureFileName;
    }
}
