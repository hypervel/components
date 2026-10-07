<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Saloon\Exceptions\FixtureMissingException;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

// Upstream's MockConfig maps to the saloon.fixtures configuration and the manager's tests-only overrides.
class MockConfigTest extends TestCase
{
    protected Filesystem $files;

    protected string $fixturePath;

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
        $this->fixturePath = ParallelTesting::tempDir('SaloonMockConfigTest');
        $this->files->deleteDirectory($this->fixturePath);
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->fixturePath);

        parent::tearDown();
    }

    public function testYouCanChangeTheDefaultFixturePath(): void
    {
        $this->assertSame(base_path('tests/Fixtures/Saloon'), Saloon::getFixturePath());

        Saloon::fixturePath('saloon-requests/responses');

        $this->assertSame('saloon-requests/responses', Saloon::getFixturePath());
    }

    public function testYouCanThrowAnExceptionIfTheFixtureDoesNotExist(): void
    {
        Saloon::fixturePath($this->fixturePath);

        $this->assertFalse(Saloon::throwsOnMissingFixtures());

        Saloon::throwOnMissingFixtures();

        $mockClient = new MockClient([
            MockResponse::fixture('example'),
        ]);

        $this->expectException(FixtureMissingException::class);
        $this->expectExceptionMessageIsOrContains('example.json" could not be found in storage.');

        (new TestConnector)->send(new UserRequest, $mockClient);
    }

    public function testIfTheFixturePathDoesNotExistItWillBeCreatedWhenAFixtureIsRecorded(): void
    {
        Http::fake(['*' => Http::response(['name' => 'Sam'])]);
        Saloon::fixturePath($this->fixturePath . '/OtherFixturePath');

        $mockClient = new MockClient([
            MockResponse::fixture('example'),
        ]);

        $this->assertDirectoryDoesNotExist($this->fixturePath . '/OtherFixturePath');

        (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertFileExists($this->fixturePath . '/OtherFixturePath/example.json');
    }
}
