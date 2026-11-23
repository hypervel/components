<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Console\LangPublishCommand;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

class LangPublishCommandTest extends TestCase
{
    use VerifiesDoubles;

    protected Filesystem $filesystem;

    protected string $langPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->langPath = $this->app->basePath('lang/en');
        $this->removePublishedLanguagePath();
    }

    protected function tearDown(): void
    {
        $this->removePublishedLanguagePath();

        parent::tearDown();
    }

    public function testItPublishesLanguageFiles(): void
    {
        $this->artisan('lang:publish')
            ->expectsOutputToContain('Language files published successfully.')
            ->assertSuccessful();

        foreach (['auth.php', 'pagination.php', 'passwords.php', 'validation.php'] as $file) {
            $this->assertFileExists($this->langPath . "/{$file}");
        }
    }

    public function testItDoesNotOverwriteExistingFilesWithoutForce(): void
    {
        $this->filesystem->ensureDirectoryExists($this->langPath);
        $destination = $this->langPath . '/auth.php';
        $this->filesystem->put($destination, 'existing contents');

        $this->artisan('lang:publish')->assertSuccessful();

        $this->assertSame('existing contents', $this->filesystem->get($destination));
    }

    public function testForcedPublicationPreservesExistingPermissions(): void
    {
        $this->filesystem->ensureDirectoryExists($this->langPath);
        $destination = $this->langPath . '/auth.php';
        $this->filesystem->put($destination, 'existing contents');
        chmod($destination, 0640);

        $this->artisan('lang:publish', ['--force' => true])->assertSuccessful();

        $this->assertNotSame('existing contents', $this->filesystem->get($destination));
        $this->assertSame(0640, fileperms($destination) & 0777);
    }

    public function testDirectoryCreationFailureSurfacesNamedFilesystemError(): void
    {
        $this->filesystem->put($this->langPath, 'not a directory');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs("Unable to create directory [{$this->langPath}].");

        $this->artisan('lang:publish');
    }

    public function testSourceReadFailurePreservesExistingDestinationAndReportsNoSuccess(): void
    {
        $this->filesystem->ensureDirectoryExists($this->langPath);
        $destination = $this->langPath . '/auth.php';
        $this->filesystem->put($destination, 'existing contents');
        $source = dirname((new ReflectionClass(LangPublishCommand::class))->getFileName())
            . '/../../../translation/lang/en/auth.php';

        $files = Double::for(new Filesystem)->passthru();
        $readException = new FileNotFoundException("File does not exist at path [{$source}].");
        $files->expects('get')->with($source)->throws($readException);
        $this->app->instance('files', $files);
        $tester = $this->commandTester();

        try {
            $tester->execute(['--force' => true]);
            $this->fail('Expected language source reading to fail.');
        } catch (FileNotFoundException $exception) {
            $this->assertSame($readException, $exception);
        }

        $this->assertSame('existing contents', $this->filesystem->get($destination));
        $this->assertStringNotContainsString('Language files published successfully.', $tester->getDisplay());
    }

    public function testReplacementFailurePreservesExistingDestinationAndReportsNoSuccess(): void
    {
        $this->filesystem->ensureDirectoryExists($this->langPath);
        $destination = $this->langPath . '/auth.php';
        $this->filesystem->put($destination, 'existing contents');
        chmod($destination, 0640);

        $files = Double::for(new Filesystem)->passthru();
        $publicationException = new RuntimeException('Unable to publish language file.');
        $files->expects('replace')
            ->with($destination, Argument::type('string'), 0640)
            ->throws($publicationException);
        $this->app->instance('files', $files);
        $tester = $this->commandTester();

        try {
            $tester->execute(['--force' => true]);
            $this->fail('Expected language publication to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($publicationException, $exception);
        }

        $this->assertSame('existing contents', $this->filesystem->get($destination));
        $this->assertSame(0640, fileperms($destination) & 0777);
        $this->assertStringNotContainsString('Language files published successfully.', $tester->getDisplay());
    }

    /**
     * Create a tester for the language publisher.
     */
    protected function commandTester(): CommandTester
    {
        $command = new LangPublishCommand;
        $command->setHypervel($this->app);
        $application = new ConsoleApplication;
        $application->addCommand($command);

        return new CommandTester($command);
    }

    /**
     * Remove the published language path.
     */
    protected function removePublishedLanguagePath(): void
    {
        if ($this->filesystem->isFile($this->langPath)) {
            $this->filesystem->delete($this->langPath);

            return;
        }

        $this->filesystem->deleteDirectory($this->langPath);
    }
}
