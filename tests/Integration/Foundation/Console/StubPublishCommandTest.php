<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Console\StubPublishCommand;
use Hypervel\Foundation\Events\PublishingStubs;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

class StubPublishCommandTest extends TestCase
{
    protected Filesystem $filesystem;

    protected string $stubsPath;

    protected string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->stubsPath = $this->app->basePath('stubs');
        $this->source = dirname((new ReflectionClass(StubPublishCommand::class))->getFileName()) . '/stubs/class.stub';
        $this->removePublishedStubsPath();
        $this->publishOnly($this->source, 'class.stub');
    }

    protected function tearDown(): void
    {
        $this->removePublishedStubsPath();

        parent::tearDown();
    }

    public function testItPublishesStubs(): void
    {
        $this->artisan('stub:publish')
            ->expectsOutputToContain('Stubs published successfully.')
            ->assertSuccessful();

        $this->assertSame(
            $this->filesystem->get($this->source),
            $this->filesystem->get($this->stubsPath . '/class.stub')
        );
    }

    public function testItDoesNotOverwriteExistingStubsWithoutForce(): void
    {
        $this->filesystem->ensureDirectoryExists($this->stubsPath);
        $destination = $this->stubsPath . '/class.stub';
        $this->filesystem->put($destination, 'existing contents');

        $this->artisan('stub:publish')->assertSuccessful();

        $this->assertSame('existing contents', $this->filesystem->get($destination));
    }

    public function testForcedPublicationPreservesExistingPermissions(): void
    {
        $this->filesystem->ensureDirectoryExists($this->stubsPath);
        $destination = $this->stubsPath . '/class.stub';
        $this->filesystem->put($destination, 'existing contents');
        chmod($destination, 0640);

        $this->artisan('stub:publish', ['--force' => true])->assertSuccessful();

        $this->assertNotSame('existing contents', $this->filesystem->get($destination));
        $this->assertSame(0640, fileperms($destination) & 0777);
    }

    public function testDirectoryCreationFailureSurfacesNamedFilesystemError(): void
    {
        $this->filesystem->put($this->stubsPath, 'not a directory');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to create directory [{$this->stubsPath}].");

        $this->artisan('stub:publish');
    }

    public function testSourceReadFailurePreservesExistingDestinationAndReportsNoSuccess(): void
    {
        $this->filesystem->ensureDirectoryExists($this->stubsPath);
        $destination = $this->stubsPath . '/class.stub';
        $this->filesystem->put($destination, 'existing contents');

        $files = m::mock(Filesystem::class)->makePartial();
        $readException = new FileNotFoundException("File does not exist at path [{$this->source}].");
        $files->shouldReceive('get')->once()->with($this->source)->andThrow($readException);
        $this->app->instance('files', $files);
        $tester = $this->commandTester();

        try {
            $tester->execute(['--force' => true]);
            $this->fail('Expected stub source reading to fail.');
        } catch (FileNotFoundException $exception) {
            $this->assertSame($readException, $exception);
        }

        $this->assertSame('existing contents', $this->filesystem->get($destination));
        $this->assertStringNotContainsString('Stubs published successfully.', $tester->getDisplay());
    }

    public function testReplacementFailurePreservesExistingDestinationAndReportsNoSuccess(): void
    {
        $this->filesystem->ensureDirectoryExists($this->stubsPath);
        $destination = $this->stubsPath . '/class.stub';
        $this->filesystem->put($destination, 'existing contents');
        chmod($destination, 0640);

        $files = m::mock(Filesystem::class)->makePartial();
        $publicationException = new RuntimeException('Unable to publish stub.');
        $files->shouldReceive('replace')
            ->once()
            ->with($destination, m::type('string'), 0640)
            ->andThrow($publicationException);
        $this->app->instance('files', $files);
        $tester = $this->commandTester();

        try {
            $tester->execute(['--force' => true]);
            $this->fail('Expected stub publication to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($publicationException, $exception);
        }

        $this->assertSame('existing contents', $this->filesystem->get($destination));
        $this->assertSame(0640, fileperms($destination) & 0777);
        $this->assertStringNotContainsString('Stubs published successfully.', $tester->getDisplay());
    }

    /**
     * Limit publishing to one stub.
     */
    protected function publishOnly(string $source, string $name): void
    {
        $this->app->make('events')->listen(
            PublishingStubs::class,
            static function (PublishingStubs $event) use ($source, $name): void {
                $event->stubs = [$source => $name];
            }
        );
    }

    /**
     * Create a tester for the stub publisher.
     */
    protected function commandTester(): CommandTester
    {
        $command = new StubPublishCommand;
        $command->setHypervel($this->app);
        $application = new ConsoleApplication;
        $application->addCommand($command);

        return new CommandTester($command);
    }

    /**
     * Remove the published stubs path.
     */
    protected function removePublishedStubsPath(): void
    {
        if ($this->filesystem->isFile($this->stubsPath)) {
            $this->filesystem->delete($this->stubsPath);

            return;
        }

        $this->filesystem->deleteDirectory($this->stubsPath);
    }
}
