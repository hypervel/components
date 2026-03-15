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
        $composerLockPath = dirname(__DIR__, 2) . '/src/testbench/workbench/composer.lock';

        // The workbench composer.lock is created by Bootstrapper::generateComposerLock()
        // during test suite bootstrap. Verify it exists before the remote command runs.
        $this->assertFileExists($composerLockPath);

        $result = remote('list')->mustRun();

        $this->assertSame(0, $result->getExitCode());
        $this->assertFileExists($composerLockPath);
    }
}
