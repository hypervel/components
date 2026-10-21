<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Console;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Sentry\Console\PublishCommand;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Sentry\TestCase;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

class PublishCommandTest extends TestCase
{
    private string $environmentPath;

    private Filesystem $filesystem;

    /**
     * Set up the test environment.
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->environmentPath = ParallelTesting::tempDir('SentryPublishCommandTest');

        $this->filesystem->deleteDirectory($this->environmentPath);
        $this->filesystem->ensureDirectoryExists($this->environmentPath);
        $this->app->useEnvironmentPath($this->environmentPath);
    }

    /**
     * Clean up the test environment.
     */
    #[Override]
    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->environmentPath);
        $this->filesystem->delete($this->app->configPath('sentry.php'));

        parent::tearDown();
    }

    public function testIsEnvKeySetTreatsRegexMetacharactersAsLiterals(): void
    {
        $command = new PublishCommand;

        $isEnvKeySetMethod = new ReflectionMethod($command, 'isEnvKeySet');
        $this->assertFalse((bool) $isEnvKeySetMethod->invoke(
            $command,
            'SENTRY.KEY',
            "SENTRYAKEY=true\n"
        ));

        $this->assertTrue((bool) $isEnvKeySetMethod->invoke(
            $command,
            'SENTRY.KEY',
            "SENTRY.KEY=true\n"
        ));
    }

    public function testSetEnvValuesTreatsRegexMetacharactersAsLiterals(): void
    {
        $environmentFile = $this->app->environmentFilePath();
        $this->filesystem->put($environmentFile, "SENTRYAKEY=old\nSENTRY.KEY=old\n");

        $command = new PublishCommand;
        $setEnvValuesMethod = new ReflectionMethod($command, 'setEnvValues');
        $setEnvValuesMethod->invoke($command, ['SENTRY.KEY' => 'new']);

        $this->assertSame("SENTRYAKEY=old\nSENTRY.KEY=new\n", $this->filesystem->get($environmentFile));
    }

    public function testSetEnvValuesOverwritesExistingVariablesThroughTheSharedWriter(): void
    {
        $environmentFile = $this->app->environmentFilePath();
        $this->filesystem->put($environmentFile, "SENTRY_HYPERVEL_DSN=old\n");

        $command = new PublishCommand;
        $method = new ReflectionMethod($command, 'setEnvValues');

        $this->assertTrue($method->invoke($command, [
            'SENTRY_HYPERVEL_DSN' => 'https://public@sentry.test/1',
            'SENTRY_SEND_DEFAULT_PII' => 'true',
        ]));
        $this->assertSame(
            "SENTRY_HYPERVEL_DSN=\"https://public@sentry.test/1\"\nSENTRY_SEND_DEFAULT_PII=true\n",
            $this->filesystem->get($environmentFile)
        );
    }

    public function testDeclinedOptionsOverwriteExportedAndSpacedAssignments(): void
    {
        $environmentFile = $this->app->environmentFilePath();
        $this->filesystem->put($environmentFile, implode("\n", [
            'SENTRY_HYPERVEL_DSN = https://public@sentry.test/1',
            'export SENTRY_SEND_DEFAULT_PII = true',
            'SENTRY_TRACES_SAMPLE_RATE = 1.0',
            '',
        ]));

        $this->artisan('sentry:publish')
            ->expectsConfirmation(
                "Do you want to include information such as request headers, IP address and the authenticated user to events collected by the SDK?\n You can read more about this on https://docs.sentry.io/platforms/php/guides/laravel/data-management/data-collected/",
                'no'
            )
            ->expectsConfirmation('Enable Performance Monitoring?', 'no')
            ->expectsConfirmation('Do you want to send a test event to Sentry?', 'no')
            ->doesntExpectOutputToContain('Sending test event')
            ->assertSuccessful();

        $this->assertSame(implode("\n", [
            'SENTRY_HYPERVEL_DSN = https://public@sentry.test/1',
            'export SENTRY_SEND_DEFAULT_PII=false',
            'SENTRY_TRACES_SAMPLE_RATE=0',
            '',
        ]), $this->filesystem->get($environmentFile));
        $this->assertFileExists($this->app->configPath('sentry.php'));
    }

    #[DataProvider('environmentFilesWithoutADsnProvider')]
    public function testDsnIsRequiredWhenTheEnvironmentFileHasNoDsnValue(string $contents): void
    {
        $environmentFile = $this->app->environmentFilePath();
        $this->filesystem->put($environmentFile, $contents);

        $this->artisan('sentry:publish', ['--no-interaction' => true])
            ->expectsOutputToContain('Please provide a valid DSN')
            ->assertExitCode(1);

        $this->assertSame($contents, $this->filesystem->get($environmentFile));
    }

    /**
     * Provide environment files that do not assign a DSN value.
     *
     * @return array<string, array{string}>
     */
    public static function environmentFilesWithoutADsnProvider(): array
    {
        return [
            'empty assignment' => ["SENTRY_HYPERVEL_DSN=\n"],
            'assignment text inside a multiline value' => ["SENTRY_NOTES=\"Copied from the old server:\nSENTRY_HYPERVEL_DSN=https://public@sentry.test/1\"\n"],
        ];
    }
}
