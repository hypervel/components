<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

/**
 * Composes the Testbench testing concerns into a single upstream-facing trait.
 *
 * Hypervel's base testing lifecycle already owns application refresh and
 * callback execution, so this trait primarily restores the upstream public
 * surface and concern grouping.
 *
 * @api
 */
trait Testing
{
    use CreatesApplication;
    use HandlesAssertions;
    use HandlesAttributes;
    use HandlesDatabases;
    use HandlesRoutes;
    use InteractsWithMigrations;
    use InteractsWithPHPUnit;
    use InteractsWithTestCase;

    /**
     * Reload the application instance.
     *
     * @api
     */
    protected function reloadApplication(): void
    {
        $this->tearDown();
        $this->setUp();
    }
}
