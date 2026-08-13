<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__, 2) . '/src/passkeys/scripts/sync-aaguids.php';

class AaguidSyncScriptTest extends TestCase
{
    protected string $tempDirectory;

    protected Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->tempDirectory = ParallelTesting::tempDir('PasskeysAaguidSyncScriptTest');
        $this->filesystem->deleteDirectory($this->tempDirectory);
        mkdir($this->tempDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->tempDirectory);

        parent::tearDown();
    }

    public function testItReplacesThePublishedCatalogueExactly(): void
    {
        $destination = $this->tempDirectory . '/aaguids.php';

        \publishAaguids($destination, 'first catalogue');
        $this->assertSame('first catalogue', file_get_contents($destination));
        $this->assertSame(0644, fileperms($destination) & 0777);

        \publishAaguids($destination, 'replacement catalogue');
        $this->assertSame('replacement catalogue', file_get_contents($destination));
        $this->assertSame(0644, fileperms($destination) & 0777);
        $this->assertSame([], glob($this->tempDirectory . '/.aaguids-*'));
    }

    public function testItReportsPublicationFailureAndCleansTheTemporaryFile(): void
    {
        $destination = $this->tempDirectory . '/aaguids.php';
        mkdir($destination);
        file_put_contents($destination . '/existing', 'existing catalogue');

        try {
            \publishAaguids($destination, 'replacement catalogue');
            $this->fail('Expected AAGUID publication to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to publish the AAGUID catalogue.', $exception->getMessage());
        }

        $this->assertSame('existing catalogue', file_get_contents($destination . '/existing'));
        $this->assertSame([], glob($this->tempDirectory . '/.aaguids-*'));
    }

    public function testTheSynchronizationWorkflowTargetsTheDefaultBranch(): void
    {
        $workflow = Yaml::parseFile(
            dirname(__DIR__, 2) . '/.github/workflows/sync-passkeys-aaguids.yml',
        );
        $steps = array_column($workflow['jobs']['synchronize']['steps'], null, 'name');
        $defaultBranch = '${{ github.event.repository.default_branch }}';
        $script = 'src/passkeys/scripts/sync-aaguids.php';

        $this->assertArrayHasKey('schedule', $workflow['on']);
        $this->assertArrayHasKey('workflow_dispatch', $workflow['on']);
        $this->assertSame(['contents' => 'write', 'pull-requests' => 'write'], $workflow['permissions']);
        $this->assertSame(
            'ghcr.io/hypervel/components-ci:php8.4-swoole6.2.2',
            $workflow['jobs']['synchronize']['container']['image'],
        );
        $this->assertSame($defaultBranch, $steps['Checkout default branch']['with']['ref']);
        $this->assertFalse($steps['Checkout default branch']['with']['persist-credentials']);
        $this->assertSame('git config --global --add safe.directory "$GITHUB_WORKSPACE"', $steps['Trust checkout directory']['run']);
        $this->assertSame("php {$script}", $steps['Synchronize Passkeys AAGUIDs']['run']);
        $this->assertFileExists(dirname(__DIR__, 2) . '/' . $script);
        $this->assertSame($defaultBranch, $steps['Open update pull request']['with']['base']);
    }
}
