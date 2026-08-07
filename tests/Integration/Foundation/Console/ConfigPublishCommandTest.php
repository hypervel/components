<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Console\ConfigPublishCommand;
use Hypervel\Testbench\Concerns\InteractsWithPublishedFiles;
use Mockery as m;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Finder\Finder;

class ConfigPublishCommandTest extends \Hypervel\Testbench\TestCase
{
    use InteractsWithPublishedFiles;

    protected array $files = [];

    /**
     * The path to the framework's base configuration files.
     */
    private string $baseConfigPath;

    /**
     * Original config file contents to restore after each test.
     *
     * @var array<string, null|string>
     */
    private array $originalContents = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseConfigPath = dirname((new ReflectionClass(ConfigPublishCommand::class))->getFileName(), 3) . '/config';
    }

    protected function tearDown(): void
    {
        foreach ($this->originalContents as $destination => $contents) {
            if ($contents === null) {
                @unlink($destination);
            } else {
                file_put_contents($destination, $contents);
            }
        }

        parent::tearDown();
    }

    public function testItCanPublishSpecificConfigFile(): void
    {
        $this->preserveConfigFile('cache');

        $this->artisan('config:publish', ['name' => 'cache', '--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain("Published 'cache' configuration file.");

        $this->assertFilenameExists('config/cache.php');
    }

    public function testItPublishesAllConfigFilesWithAllFlag(): void
    {
        $expectedConfigs = $this->getExpectedConfigNames();

        foreach ($expectedConfigs as $name) {
            $this->preserveConfigFile($name);
        }

        $this->artisan('config:publish', ['--all' => true, '--force' => true])
            ->assertSuccessful();

        foreach ($expectedConfigs as $name) {
            $this->assertFilenameExists("config/{$name}.php");
        }
    }

    public function testItDiscoversCoreFrameworkConfigs(): void
    {
        $expectedConfigs = $this->getExpectedConfigNames();

        // Every core framework config should be discoverable
        foreach (['app', 'auth', 'cache', 'database', 'logging', 'session', 'view'] as $name) {
            $this->assertContains($name, $expectedConfigs, "Config '{$name}' should be discoverable by config:publish.");
        }
    }

    public function testPublishedContentMatchesSource(): void
    {
        $name = 'hashing';
        $this->preserveConfigFile($name);

        $this->artisan('config:publish', ['name' => $name, '--force' => true])
            ->assertSuccessful();

        $sourceContent = file_get_contents($this->baseConfigPath . "/{$name}.php");
        $publishedContent = file_get_contents($this->app->configPath("{$name}.php"));

        $this->assertSame($sourceContent, $publishedContent);
    }

    public function testItSkipsExistingConfigWithoutForce(): void
    {
        $name = 'app';
        $destination = $this->app->configPath("{$name}.php");

        // app.php should already exist in the test app
        if (! is_file($destination)) {
            $this->markTestSkipped('app.php does not exist in test config directory.');
        }

        $this->artisan('config:publish', ['name' => $name])
            ->expectsOutputToContain("The '{$name}' configuration file already exists.");
    }

    public function testItOverwritesExistingConfigWithForce(): void
    {
        $name = 'app';
        $destination = $this->app->configPath("{$name}.php");

        if (! is_file($destination)) {
            $this->markTestSkipped('app.php does not exist in test config directory.');
        }

        $this->preserveConfigFile($name);

        $this->artisan('config:publish', ['name' => $name, '--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain("Published '{$name}' configuration file.");
    }

    public function testItFailsWithUnrecognizedConfigFile(): void
    {
        $this->artisan('config:publish', ['name' => 'nonexistent-config-file'])
            ->expectsOutputToContain('Unrecognized configuration file.')
            ->assertExitCode(1);
    }

    public function testForcedPublicationPreservesExistingPermissions(): void
    {
        $name = 'hashing';
        $destination = $this->app->configPath("{$name}.php");
        $this->preserveConfigFile($name);
        file_put_contents($destination, 'old contents');
        chmod($destination, 0640);

        $this->artisan('config:publish', ['name' => $name, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0640, fileperms($destination) & 0777);
    }

    public function testSourceReadFailurePreservesExistingDestinationAndReportsNoSuccess(): void
    {
        $name = 'hashing';
        $source = dirname((new ReflectionClass(ConfigPublishCommand::class))->getFileName()) . "/../../config/{$name}.php";
        $sourceContents = file_get_contents($source);
        $destination = $this->app->configPath("{$name}.php");
        $this->preserveConfigFile($name);
        file_put_contents($destination, 'existing destination');

        $files = m::mock(Filesystem::class)->makePartial();
        $readException = new FileNotFoundException("File does not exist at path [{$source}].");
        $files->shouldReceive('get')->once()->with($source)->andThrow($readException);
        $this->app->instance('files', $files);
        $tester = $this->commandTester();

        try {
            $tester->execute(['name' => $name, '--force' => true]);
            $this->fail('Expected configuration source reading to fail.');
        } catch (FileNotFoundException $exception) {
            $this->assertSame($readException, $exception);
        }

        $this->assertSame('existing destination', file_get_contents($destination));
        $this->assertSame($sourceContents, file_get_contents($source));
        $this->assertStringNotContainsString("Published '{$name}' configuration file.", $tester->getDisplay());
    }

    public function testReplacementFailurePreservesExistingDestinationAndReportsNoSuccess(): void
    {
        $name = 'hashing';
        $destination = $this->app->configPath("{$name}.php");
        $this->preserveConfigFile($name);
        file_put_contents($destination, 'existing destination');
        chmod($destination, 0640);

        $files = m::mock(Filesystem::class)->makePartial();
        $publicationException = new RuntimeException('Unable to publish configuration file.');
        $files->shouldReceive('replace')
            ->once()
            ->with($destination, m::type('string'), 0640)
            ->andThrow($publicationException);
        $this->app->instance('files', $files);
        $tester = $this->commandTester();

        try {
            $tester->execute(['name' => $name, '--force' => true]);
            $this->fail('Expected configuration publication to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($publicationException, $exception);
        }

        $this->assertSame('existing destination', file_get_contents($destination));
        $this->assertSame(0640, fileperms($destination) & 0777);
        $this->assertStringNotContainsString("Published '{$name}' configuration file.", $tester->getDisplay());
    }

    /**
     * Get the expected config file names from the foundation config directory.
     *
     * @return list<string>
     */
    private function getExpectedConfigNames(): array
    {
        $names = [];

        foreach (Finder::create()->files()->name('*.php')->in($this->baseConfigPath) as $file) {
            $names[] = basename($file->getPathname(), '.php');
        }

        sort($names);

        return $names;
    }

    /**
     * Save the original contents of a config file so it can be restored after the test.
     */
    private function preserveConfigFile(string $name): void
    {
        $destination = $this->app->configPath("{$name}.php");

        $this->originalContents[$destination] = is_file($destination)
            ? file_get_contents($destination)
            : null;
    }

    /**
     * Create a tester for the configuration publisher.
     */
    private function commandTester(): CommandTester
    {
        $command = new ConfigPublishCommand;
        $command->setHypervel($this->app);
        $application = new ConsoleApplication;
        $application->addCommand($command);

        return new CommandTester($command);
    }
}
