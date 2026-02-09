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
        $originalContent = is_file($composerLockPath) ? file_get_contents($composerLockPath) : null;

        file_put_contents($composerLockPath, json_encode(['packages' => [], 'packages-dev' => []]));

        try {
            $result = remote('list')->mustRun();

            $this->assertSame(0, $result->getExitCode());
            $this->assertFileExists($composerLockPath);
        } finally {
            if ($originalContent === null) {
                @unlink($composerLockPath);
            } else {
                file_put_contents($composerLockPath, $originalContent);
            }
        }
    }
}
