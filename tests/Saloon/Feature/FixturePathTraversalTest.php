<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Saloon\Data\RecordedResponse;
use Hypervel\Saloon\Exceptions\FixtureException;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Faking\Fixture;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;

// Fixtures read and write through the framework Filesystem rather than upstream's internal Storage helper, so the
// fixture name is the only path input to validate.
// REMOVED: Unit/StorageTest - the Storage helper is not ported; these cases and Unit/FixtureTest cover its path rules.
class FixturePathTraversalTest extends TestCase
{
    protected Filesystem $files;

    protected string $directory;

    protected string $fixtureBaseDir;

    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->directory = ParallelTesting::tempDir('SaloonFixturePathTraversalTest');
        $this->fixtureBaseDir = $this->directory . '/fixtures';
        $this->files->deleteDirectory($this->directory);
        $this->files->ensureDirectoryExists($this->fixtureBaseDir);
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function testFixtureNameWithPathTraversalThrowsWhenGettingMockResponseAndDoesNotReadOutsideBase(): void
    {
        Saloon::fixturePath($this->fixtureBaseDir);

        $externalPath = $this->directory . '/traversal_read_target.json';
        $secretContent = 'read_from_outside';
        file_put_contents($externalPath, json_encode([
            'statusCode' => 200,
            'headers' => [],
            'data' => '{"secret":"' . $secretContent . '"}',
            'context' => [],
        ]));

        $fixture = new Fixture('../traversal_read_target', $this->files);

        try {
            $fixture->getMockResponse();
            $this->fail('A traversing fixture name was accepted.');
        } catch (FixtureException $exception) {
            $this->assertSame('Fixture names must contain only portable path segments separated by forward slashes.', $exception->getMessage());
        }

        $this->assertStringContainsString($secretContent, file_get_contents($externalPath));
    }

    public function testFixtureNameWithPathTraversalThrowsWhenStoringAndDoesNotWriteOutsideBase(): void
    {
        Saloon::fixturePath($this->fixtureBaseDir);

        $fixture = new Fixture('../traversal_write_test', $this->files);

        $recordedResponse = new RecordedResponse(200, [], '{"pwned":true}');

        try {
            $fixture->store($recordedResponse);
            $this->fail('A traversing fixture name was accepted.');
        } catch (FixtureException $exception) {
            $this->assertSame('Fixture names must contain only portable path segments separated by forward slashes.', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($this->directory . '/traversal_write_test.json');
    }
}
