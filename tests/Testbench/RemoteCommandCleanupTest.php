<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Tests\TestCase;

use function Hypervel\Testbench\remote;

/**
 * @internal
 * @coversNothing
 */
class RemoteCommandCleanupTest extends TestCase
{
    public function testRemoteCommandDoesNotDeleteWorkbenchComposerLock(): void
    {
        $composerLockPath = BASE_PATH . '/composer.lock';

        // The workbench composer.lock is created by Bootstrapper::generateComposerLock()
        // during test suite bootstrap in the runtime copy. Verify it exists before the
        // remote command runs.
        $this->assertFileExists($composerLockPath);

        $result = remote('list')->mustRun();

        $this->assertSame(0, $result->getExitCode());
        $this->assertFileExists($composerLockPath);
    }
}
