<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature;

use Hypervel\Console\OutputStyle;
use Hypervel\Contracts\Console\Kernel;
use Hypervel\Doctor\Console\DoctorCommand;
use Hypervel\Doctor\Diagnostics\ApplicationKeyIsSet;
use Hypervel\Doctor\Diagnostics\BootstrapCacheMatchesEnvironment;
use Hypervel\Doctor\Diagnostics\CacheStoreIsReachable;
use Hypervel\Doctor\Diagnostics\ComposerAuditPasses;
use Hypervel\Doctor\Diagnostics\ComposerAutoloadIsValid;
use Hypervel\Doctor\Diagnostics\ComposerLockIsFresh;
use Hypervel\Doctor\Diagnostics\ConfigurationCanBeCached;
use Hypervel\Doctor\Diagnostics\ConfigurationFilesCanBeLoaded;
use Hypervel\Doctor\Diagnostics\DatabaseConnectionIsReachable;
use Hypervel\Doctor\Diagnostics\DebugModeMatchesEnvironment;
use Hypervel\Doctor\Diagnostics\EnvironmentFileExists;
use Hypervel\Doctor\Diagnostics\EnvironmentFileIsGitIgnored;
use Hypervel\Doctor\Diagnostics\FilesystemDisksAreReachable;
use Hypervel\Doctor\Diagnostics\MigrationsAreUpToDate;
use Hypervel\Doctor\Diagnostics\PhpVersionSatisfiesComposerRequirement;
use Hypervel\Doctor\Diagnostics\PublicStorageLinkExists;
use Hypervel\Doctor\Diagnostics\QueueConnectionIsAsynchronous;
use Hypervel\Doctor\Diagnostics\QueueConnectionIsReachable;
use Hypervel\Doctor\Diagnostics\RecommendedPhpExtensionsAreLoaded;
use Hypervel\Doctor\Diagnostics\RedisConnectionsAreReachable;
use Hypervel\Doctor\Diagnostics\RedisSupportsConfiguredFeatures;
use Hypervel\Doctor\Diagnostics\RequiredConfigurationValuesAreSet;
use Hypervel\Doctor\Diagnostics\RequiredPhpExtensionsAreLoaded;
use Hypervel\Doctor\Diagnostics\ScheduledTasksRequireScheduler;
use Hypervel\Doctor\Diagnostics\SessionDriverIsReachable;
use Hypervel\Doctor\Diagnostics\SqliteDatabaseExists;
use Hypervel\Doctor\Diagnostics\StorageIsWritable;
use Hypervel\Doctor\Doctor as DoctorService;
use Hypervel\Doctor\DoctorServiceProvider;
use Hypervel\Doctor\Facades\Doctor;
use Hypervel\Doctor\Renderers\CliRenderer;
use Hypervel\Doctor\Results\DiagnosticFixOutcome;
use Hypervel\Doctor\Results\DiagnosticOutcome;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;
use Hypervel\Doctor\Results\Status;
use Hypervel\Prompts\Elements\Element;
use Hypervel\Support\Facades\File;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\FixableDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\FixesSharedStateDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\LinkedDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\LocalOnlyFixableDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\OptionFixableDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\PackagedNoticeDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\PassingDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\SharedStateWasFixedDiagnostic;
use Hypervel\Tests\Doctor\Fixtures\Diagnostics\WarningDiagnostic;
use Hypervel\Tests\Doctor\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class DoctorServiceProviderTest extends TestCase
{
    private string $temporaryPath;

    /**
     * Isolate files changed by maintenance commands.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryPath = ParallelTesting::tempDir('DoctorCommands');
        File::deleteDirectory($this->temporaryPath);
        File::makeDirectory($this->temporaryPath, 0775, true);
    }

    /**
     * Remove command fixtures.
     */
    protected function tearDown(): void
    {
        try {
            File::deleteDirectory($this->temporaryPath);
        } finally {
            parent::tearDown();
        }
    }

    public function testLoadsThePackageServiceProvider(): void
    {
        $this->assertNotNull($this->app->getProvider(DoctorServiceProvider::class));
    }

    public function testDoesNotRedefineSymfonyConsoleVerbosityOptions(): void
    {
        $command = new DoctorCommand;

        $this->assertFalse($command->getDefinition()->hasOption('verbose'));
        $this->assertTrue($command->getDefinition()->hasOption('format'));
        $this->assertTrue($command->getDefinition()->hasOption('bail'));
    }

    public function testLoadsTheDefaultDoctorConfiguration(): void
    {
        $this->assertSame([], config('doctor.only'));
        $this->assertSame([], config('doctor.except'));
        $this->assertSame([
            'local' => ['local'],
            'production' => ['production', 'staging'],
        ], config('doctor.environments'));
    }

    // REMOVED: timezone command termination; Hypervel rejects invalid timezones during boot.

    public function testDoesNotRepeatDiagnosticsThatWereFixed(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        config(['app.key' => '']);

        $environmentPath = $this->app->basePath();
        $path = $environmentPath . '/.env';
        $originalEnvironmentFile = is_file($path) ? File::get($path) : null;
        File::put($path, "APP_KEY=\n");

        $this->app->useEnvironmentPath($environmentPath);
        $this->app->loadEnvironmentFrom('.env');

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        try {
            $exitCode = $this->withEnvironmentValues([
                'APP_KEY' => null,
                'HYPERVEL_AUTOLOAD_PATH' => $this->autoloadPath(),
            ], fn () => $this->app->make(Kernel::class)->call('doctor', [
                '--only' => 'ApplicationKeyIsSet',
                '--fix' => true,
            ], $output));

            $this->assertStringContainsString('base64:', File::get($path));
        } finally {
            $originalEnvironmentFile === null ? File::delete($path) : File::put($path, $originalEnvironmentFile);
        }

        $contents = $output->fetch();

        $this->assertStringContainsString('Re-running diagnostics after applying fixes...', $contents);
        $this->assertMatchesRegularExpression('/Re-running diagnostics after applying fixes\.\.\..*Results.*App key is set/s', $contents);
        $this->assertStringContainsString('App key is set', $contents);
        $this->assertStringContainsString('The application key was generated.', $contents);
        $this->assertStringNotContainsString('App key is set: The application key was generated.', $contents);
        $this->assertStringContainsString('All diagnostics passed or were fixed.', $contents);
        $this->assertSame(0, $exitCode);
    }

    public function testRerunsDiagnosticsAfterApplyingFixes(): void
    {
        Doctor::diagnostic(FixesSharedStateDiagnostic::class);
        Doctor::diagnostic(SharedStateWasFixedDiagnostic::class);

        config(['doctor-testing.shared-state-fixed' => false]);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--only' => 'testing',
            '--fix' => true,
        ], $output);

        $contents = $output->fetch();
        $this->assertStringNotContainsString('Fix', $contents);
        $this->assertStringContainsString('Re-running diagnostics after applying fixes...', $contents);
        $this->assertStringContainsString('Testing diagnostic fixes shared state', $contents);
        $this->assertStringContainsString('The shared state fix', $contents);
        $this->assertStringContainsString('ran.', $contents);
        $this->assertStringContainsString('All diagnostics passed or were fixed.', $contents);
        $this->assertStringNotContainsString('[pass] fix', $contents);
        $this->assertStringNotContainsString('The shared state is still broken.', $contents);
        $this->assertSame(0, $exitCode);
    }

    public function testMergesAFailedFixIntoTheDiagnosticIssueCallout(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        config(['app.key' => '']);

        $environmentPath = $this->temporaryPath . '/key-fail';

        mkdir($environmentPath, 0775, true);

        $this->app->useEnvironmentPath($environmentPath);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--only' => 'ApplicationKeyIsSet',
            '--fix' => true,
        ], $output);

        $contents = $output->fetch();

        $this->assertStringContainsString('The application key is not configured.', $contents);
        $this->assertStringContainsString('Fix failed', $contents);
        $this->assertStringContainsString('The application key could not be generated.', $contents);
        $this->assertStringContainsString('could not be updated', $contents);
        $this->assertStringNotContainsString('Suggested fix', $contents);
        $this->assertStringContainsString('Doctor found failing diagnostics.', $contents);
        $this->assertSame(1, substr_count($contents, 'The application key could not be generated.'));
        $this->assertSame(1, $exitCode);
    }

    public function testEscalatesWarningIssueCalloutsToErrorsWhenTheirFixFailed(): void
    {
        $renderer = new class(new OutputStyle(new ArrayInput([]), new NullOutput)) extends CliRenderer {
            /**
             * Expose the rendered callout type.
             */
            public function type(DiagnosticOutcome $outcome, ?DiagnosticFixOutcome $failedFix): string
            {
                return $this->issueCalloutType($outcome, $failedFix);
            }
        };

        $outcome = new DiagnosticOutcome(
            new FixableDiagnostic,
            DiagnosticResult::warn('The diagnostic partially improved.'),
        );

        $failedFix = new DiagnosticFixOutcome(
            new FixableDiagnostic,
            new FixResult(Status::Fail, 'The fix failed.'),
        );

        $this->assertSame('warning', $renderer->type($outcome, null));
        $this->assertSame('error', $renderer->type($outcome, $failedFix));
    }

    public function testFormatsInteractiveFixCalloutsWithoutRemediationOrConfirmationText(): void
    {
        $renderer = new class(new OutputStyle(new ArrayInput([]), new NullOutput)) extends CliRenderer {
            /**
             * Expose the fix confirmation content.
             */
            public function content(DiagnosticOutcome $outcome): array
            {
                return $this->fixConfirmationCalloutContent($outcome);
            }
        };

        $command = new class extends DoctorCommand {
            /**
             * Expose the confirmation prompt.
             */
            public function prompt(DiagnosticOutcome $outcome): string
            {
                return $this->confirmationPrompt($outcome);
            }
        };

        $outcome = new DiagnosticOutcome(
            new FixableDiagnostic,
            DiagnosticResult::fail('The diagnostic failed.')
                ->suggest('Apply the testing diagnostic fix.')
                ->confirmUsing('Fix the testing diagnostic?'),
        );

        $outcomeWithoutConfirmation = new DiagnosticOutcome(
            new FixableDiagnostic,
            DiagnosticResult::fail('The diagnostic failed.')->fixable(),
        );

        $this->assertSame(['The diagnostic failed.'], $renderer->content($outcome));
        $this->assertSame('Fix the testing diagnostic?', $command->prompt($outcome));
        $this->assertSame('Fix "Testing diagnostic is fixable"?', $command->prompt($outcomeWithoutConfirmation));
    }

    public function testRendersIssueDetailsUnderAHeadingDistinctFromTheSuggestedFix(): void
    {
        $renderer = new class(new OutputStyle(new ArrayInput([]), new NullOutput)) extends CliRenderer {
            /**
             * Expose the diagnostic callout content.
             */
            public function content(DiagnosticOutcome $outcome): array
            {
                return $this->diagnosticCalloutContent($outcome);
            }
        };

        $outcome = new DiagnosticOutcome(
            new FixableDiagnostic,
            DiagnosticResult::fail('The diagnostic failed.')
                ->withDetails('- The lock file is not up to date with the latest changes in composer.json.')
                ->suggest('Run `composer update --lock`.'),
        );

        $this->assertEquals([
            'The diagnostic failed.',
            Element::heading('Details'),
            '- The lock file is not up to date with the latest changes in composer.json.',
            Element::heading('Suggested fix'),
            'Run `composer update --lock`.',
        ], $renderer->content($outcome));
    }

    public function testRendersRelatedLinksBeneathTheSuggestedFixAsPromptLinksWithShortenedUrls(): void
    {
        $renderer = new class(new OutputStyle(new ArrayInput([]), new NullOutput)) extends CliRenderer {
            /**
             * Expose the diagnostic callout content.
             */
            public function content(DiagnosticOutcome $outcome): array
            {
                return $this->diagnosticCalloutContent($outcome);
            }

            /**
             * Expose the notice callout content.
             *
             * @param list<DiagnosticOutcome> $outcomes
             */
            public function notices(array $outcomes): array
            {
                return $this->noticesCalloutContent($outcomes);
            }
        };

        $outcome = new DiagnosticOutcome(new LinkedDiagnostic, (new LinkedDiagnostic)->check());

        $this->assertEquals([
            'The linked diagnostic warned.',
            Element::heading('Details'),
            'Detailed link context.',
            Element::heading('Suggested fix'),
            'Follow the linked documentation.',
            Element::link(
                'https://hypervel.org/docs/queues#connections-vs-queues',
                'hypervel.org/docs/queues',
            ),
            Element::link('https://example.com/links', 'example.com/links'),
        ], $renderer->content($outcome));
        $this->assertEquals([
            'The linked diagnostic warned. Detailed link context. Follow the linked documentation.',
            Element::link(
                'https://hypervel.org/docs/queues#connections-vs-queues',
                'hypervel.org/docs/queues',
            ),
            Element::link('https://example.com/links', 'example.com/links'),
        ], $renderer->notices([$outcome]));
    }

    public function testRendersIssueCalloutSourcesWithPackageFooter(): void
    {
        config(['app.key' => '']);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);

        $this->app->make(Kernel::class)->call('doctor', [
            '--only' => 'ApplicationKeyIsSet',
            '--fail-on' => 'fail',
            '--no-interaction' => true,
        ], $output);

        $contents = $output->fetch();
        $this->assertStringContainsString('Results', $contents);
        $this->assertStringContainsString('The application key is not configured.', $contents);
        $this->assertStringContainsString('Suggested fix', $contents);
        $this->assertStringContainsString('Generate an application key with', $contents);
        $this->assertStringContainsString('php artisan key:generate', $contents);
        $this->assertStringContainsString('https://hypervel.org/docs/encryption', $contents);
        $this->assertStringContainsString('hypervel/components', $contents);
        $this->assertStringNotContainsString('docs:', $contents);
        $this->assertStringNotContainsString('Encryption documentation', $contents);
        $this->assertStringNotContainsString('Links', $contents);
        $this->assertStringNotContainsString('Confirmation', $contents);
        $this->assertStringNotContainsString('Generate an application key using `php artisan key:generate`?', $contents);
        $this->assertStringNotContainsString('File:', $contents);
        $this->assertStringNotContainsString('ApplicationKeyIsSet.php', $contents);
        $this->assertStringNotContainsString('hypervel/components ApplicationKeyIsSet.php', $contents);
    }

    public function testRendersNoticeDiagnosticsWithoutDiagnosticSourceNoise(): void
    {
        $basePath = $this->temporaryPath . '/notice-output';

        mkdir($basePath . '/bootstrap/cache', 0775, true);
        mkdir($basePath . '/storage/framework/views', 0775, true);

        $this->app->setBasePath($basePath);
        $this->app->useStoragePath($basePath . '/storage');
        $this->app->detectEnvironment(fn (): string => 'local');
        config(['view.compiled' => $basePath . '/storage/framework/views']);

        file_put_contents($this->app->getCachedEventsPath(), '<?php return [];');
        file_put_contents($basePath . '/storage/framework/views/example.php', '<?php echo "cached";');

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', ['--only' => 'BootstrapCacheMatchesEnvironment'], $output);

        $contents = $output->fetch();
        $this->assertStringContainsString('Notice', $contents);
        $this->assertStringContainsString('Cached bootstrap file detected: events.', $contents);
        $this->assertStringContainsString('optimize:clear', $contents);
        $this->assertStringContainsString('https://hypervel.org/docs/deployment#optimization', $contents);
        $this->assertStringNotContainsString('Suggested fix', $contents);
        $this->assertStringNotContainsString('docs:', $contents);
        $this->assertStringNotContainsString('Configuration documentation', $contents);
        $this->assertStringNotContainsString('Links', $contents);
        $this->assertStringNotContainsString('Notes:', $contents);
        $this->assertStringNotContainsString('[notice]', $contents);
        $this->assertStringNotContainsString('Bootstrap cache matches environment (hypervel/components)', $contents);
        $this->assertSame(0, $exitCode);
    }

    public function testRendersMultipleNoticeDiagnosticsInASingleCallout(): void
    {
        $basePath = $this->temporaryPath . '/notices-output';

        mkdir($basePath . '/bootstrap/cache', 0775, true);
        mkdir($basePath . '/storage/framework/views', 0775, true);

        $this->app->setBasePath($basePath);
        $this->app->useStoragePath($basePath . '/storage');
        $this->app->detectEnvironment(fn (): string => 'local');
        config([
            'queue.default' => 'database',
            'view.compiled' => $basePath . '/storage/framework/views',
        ]);

        file_put_contents($this->app->getCachedEventsPath(), '<?php return [];');
        file_put_contents($basePath . '/storage/framework/views/example.php', '<?php echo "cached";');

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--only' => [
                'BootstrapCacheMatchesEnvironment',
                'QueueConnectionIsAsynchronous',
            ],
        ], $output);

        $contents = $output->fetch();

        $this->assertStringContainsString('Notices', $contents);
        $this->assertStringContainsString('Cached bootstrap file detected: events.', $contents);
        $this->assertStringContainsString('Queued jobs are processed asynchronously.', $contents);
        $this->assertStringContainsString('hypervel/components', $contents);
        $this->assertStringContainsString('optimize:clear', $contents);
        $this->assertStringContainsString('queue:work', $contents);
        $this->assertMatchesRegularExpression('/Cached bootstrap file detected.*optimize:clear.*https:\/\/hypervel\.org\/docs\/deployment#optimization.*Queued jobs are processed asynchronously.*queue:work.*https:\/\/hypervel\.org\/docs\/queues#running-the-queue-worker/s', $contents);
        $this->assertStringNotContainsString('Suggested fix', $contents);
        $this->assertStringNotContainsString('docs:', $contents);
        $this->assertStringNotContainsString('Configuration documentation', $contents);
        $this->assertStringNotContainsString('Queues documentation', $contents);
        $this->assertStringNotContainsString('Links', $contents);
        $this->assertSame(1, substr_count($contents, 'Notice'));
        $this->assertSame(0, $exitCode);
    }

    public function testGroupsNoticeDiagnosticsByPackageSource(): void
    {
        Doctor::diagnostic(PackagedNoticeDiagnostic::class);

        $basePath = $this->temporaryPath . '/notice-packages';

        mkdir($basePath . '/bootstrap/cache', 0775, true);
        mkdir($basePath . '/storage/framework/views', 0775, true);

        $this->app->setBasePath($basePath);
        $this->app->useStoragePath($basePath . '/storage');
        $this->app->detectEnvironment(fn (): string => 'local');
        config([
            'queue.default' => 'database',
            'view.compiled' => $basePath . '/storage/framework/views',
        ]);

        file_put_contents($this->app->getCachedEventsPath(), '<?php return [];');
        file_put_contents($basePath . '/storage/framework/views/example.php', '<?php echo "cached";');

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--only' => [
                'BootstrapCacheMatchesEnvironment',
                'QueueConnectionIsAsynchronous',
                'PackagedNoticeDiagnostic',
            ],
        ], $output);

        $contents = $output->fetch();

        $this->assertStringContainsString('Cached bootstrap file detected: events.', $contents);
        $this->assertStringContainsString('Queued jobs are processed asynchronously.', $contents);
        $this->assertStringContainsString('The packaged diagnostic noticed.', $contents);
        $this->assertStringContainsString('hypervel/components', $contents);
        $this->assertStringContainsString('vendor/package', $contents);
        $this->assertSame(2, substr_count($contents, 'Notice'));
        $this->assertSame(0, $exitCode);
    }

    public function testRendersDiagnosticLinksInCliOutput(): void
    {
        Doctor::diagnostic(LinkedDiagnostic::class);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', ['--only' => 'LinkedDiagnostic'], $output);

        $contents = $output->fetch();
        $this->assertStringContainsString('Testing diagnostic has links', $contents);
        $this->assertStringContainsString('The linked diagnostic warned.', $contents);
        $this->assertStringContainsString('Detailed link context.', $contents);
        $this->assertStringContainsString('Follow the linked documentation.', $contents);
        $this->assertStringNotContainsString('[warn]', $contents);
        $this->assertStringContainsString('https://hypervel.org/docs/queues#connections-vs-queues', $contents);
        $this->assertStringContainsString('https://example.com/links', $contents);
        $this->assertStringNotContainsString('docs:', $contents);
        $this->assertStringNotContainsString('Queues documentation', $contents);
        $this->assertStringNotContainsString('Related links guide', $contents);
        $this->assertStringNotContainsString('Links', $contents);
        $this->assertSame(0, $exitCode);
    }

    public function testRendersDiagnosticLinksInJsonOutput(): void
    {
        Doctor::diagnostic(LinkedDiagnostic::class);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--only' => 'LinkedDiagnostic',
            '--format' => 'json',
        ], $output);

        $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([
            'label' => 'hypervel/components',
            'package' => 'hypervel/components',
            'file' => 'tests/Doctor/Fixtures/Diagnostics/LinkedDiagnostic.php',
            'application' => true,
        ], $payload['diagnostics'][0]['source']);
        $this->assertFalse($payload['diagnostics'][0]['fixable']);
        $this->assertSame([
            'Queues documentation' => 'https://hypervel.org/docs/queues#connections-vs-queues',
            'Related links guide' => 'https://example.com/links',
        ], $payload['diagnostics'][0]['links']);
        $this->assertSame(0, $exitCode);
    }

    public function testBailsAfterTheFirstFailureFromTheCommand(): void
    {
        Doctor::diagnostic(WarningDiagnostic::class);
        Doctor::diagnostic(FixableDiagnostic::class);
        Doctor::diagnostic(PassingDiagnostic::class);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--only' => [
                'WarningDiagnostic',
                'FixableDiagnostic',
                'PassingDiagnostic',
            ],
            '--bail' => true,
            '--format' => 'json',
        ], $output);

        $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([
            WarningDiagnostic::class,
            FixableDiagnostic::class,
        ], array_column($payload['diagnostics'], 'class'));
        $this->assertSame(1, $exitCode);
    }

    public function testRendersDiagnosticLinksInGithubOutput(): void
    {
        Doctor::diagnostic(LinkedDiagnostic::class);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--only' => 'LinkedDiagnostic',
            '--format' => 'github',
        ], $output);

        $contents = $output->fetch();
        $this->assertStringContainsString('title=Testing diagnostic has links (hypervel/components)', $contents);
        $this->assertStringContainsString('https://hypervel.org/docs/queues#connections-vs-queues', $contents);
        $this->assertStringContainsString('https://example.com/links', $contents);
        $this->assertStringNotContainsString('docs:', $contents);
        $this->assertStringNotContainsString('Queues documentation', $contents);
        $this->assertStringNotContainsString('Related links guide', $contents);
        $this->assertSame(0, $exitCode);
    }

    public function testRejectsFixesWithJsonOutput(): void
    {
        $this->artisan('doctor --only=ApplicationKeyIsSet --fix --format=json')
            ->expectsOutputToContain('The --fix option may only be used with the cli or agent output formats.')
            ->doesntExpectOutputToContain('"diagnostics"')
            ->assertExitCode(1);
    }

    public function testRejectsFixesWithGithubOutput(): void
    {
        $this->artisan('doctor --only=ApplicationKeyIsSet --fix --format=github')
            ->expectsOutputToContain('The --fix option may only be used with the cli or agent output formats.')
            ->doesntExpectOutputToContain('::')
            ->assertExitCode(1);
    }

    public function testRendersAgentOutputWhenAnAgentIsDetected(): void
    {
        Doctor::diagnostic(LinkedDiagnostic::class);

        putenv('AI_AGENT=hypervel-doctor-test');

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--only' => 'LinkedDiagnostic',
        ], $output);

        $contents = trim($output->fetch());
        $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString("\n", $contents);
        $this->assertSame('doctor', $payload['tool']);
        $this->assertSame('passed', $payload['result']);
        $this->assertSame(1, $payload['diagnostics']);
        $this->assertSame(1, $payload['warnings']);
        $this->assertSame([[
            'name' => 'Testing diagnostic has links',
            'status' => 'warn',
            'summary' => 'The linked diagnostic warned.',
            'details' => 'Detailed link context.',
            'fix' => 'Follow the linked documentation.',
            'links' => [
                'Queues documentation' => 'https://hypervel.org/docs/queues#connections-vs-queues',
                'Related links guide' => 'https://example.com/links',
            ],
        ]], $payload['issues']);
        $this->assertArrayNotHasKey('fixes', $payload);
        $this->assertSame(0, $exitCode);
    }

    public function testPrefersAnExplicitFormatOverAgentDetection(): void
    {
        Doctor::diagnostic(LinkedDiagnostic::class);

        putenv('AI_AGENT=hypervel-doctor-test');

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--only' => 'LinkedDiagnostic',
            '--format' => 'cli',
        ], $output);

        $contents = $output->fetch();
        $this->assertStringContainsString('Testing diagnostic has links', $contents);
        $this->assertStringContainsString('Follow the linked documentation.', $contents);
        $this->assertSame(0, $exitCode);
    }

    public function testKeepsCliOutputForNonInteractiveRunsOutsideAgents(): void
    {
        Doctor::diagnostic(LinkedDiagnostic::class);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--only' => 'LinkedDiagnostic',
            '--no-interaction' => true,
        ], $output);

        $contents = $output->fetch();
        $this->assertStringContainsString('Testing diagnostic has links', $contents);
        $this->assertStringContainsString('Follow the linked documentation.', $contents);
        $this->assertSame(0, $exitCode);
    }

    public function testRendersMinimalAgentOutputWhenEveryDiagnosticPasses(): void
    {
        Doctor::diagnostic(PassingDiagnostic::class);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--only' => 'PassingDiagnostic',
            '--format' => 'agent',
        ], $output);

        $payload = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([
            'tool' => 'doctor',
            'result' => 'passed',
            'diagnostics' => 1,
            'failed' => 0,
            'warnings' => 0,
            'notices' => 0,
            'passed' => 1,
            'skipped' => 0,
        ], $payload);
        $this->assertSame(0, $exitCode);
    }

    public function testMarksFixableIssuesInAgentOutput(): void
    {
        Doctor::diagnostic(FixableDiagnostic::class);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--only' => 'FixableDiagnostic',
            '--format' => 'agent',
        ], $output);

        $payload = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('failed', $payload['result']);
        $this->assertSame([[
            'name' => 'Testing diagnostic is fixable',
            'status' => 'fail',
            'summary' => 'The diagnostic failed.',
            'fix' => 'Apply the testing diagnostic fix.',
            'fixable' => true,
        ]], $payload['issues']);
        $this->assertArrayNotHasKey('fixes', $payload);
        $this->assertSame(1, $exitCode);
    }

    public function testDoesNotExposeOrApplyLocalOnlyFixesInProductionAgentOutput(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        Doctor::diagnostic(LocalOnlyFixableDiagnostic::class);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--only' => 'LocalOnlyFixableDiagnostic',
            '--format' => 'agent',
            '--fix' => true,
        ], $output);

        $payload = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([[
            'name' => 'Testing diagnostic is fixable locally',
            'status' => 'fail',
            'summary' => 'The local diagnostic failed.',
            'fix' => 'Apply the local testing diagnostic fix.',
        ]], $payload['issues']);
        $this->assertArrayNotHasKey('fixes', $payload);
        $this->assertSame(1, $exitCode);
    }

    public function testAppliesFixesAndRerunsForAgentOutputWhenRequested(): void
    {
        Doctor::diagnostic(FixesSharedStateDiagnostic::class);

        config(['doctor-testing.shared-state-fixed' => false]);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--only' => 'FixesSharedStateDiagnostic',
            '--format' => 'agent',
            '--fix' => true,
        ], $output);

        $payload = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('passed', $payload['result']);
        $this->assertSame(1, $payload['passed']);
        $this->assertSame([[
            'name' => 'Testing diagnostic fixes shared state',
            'status' => 'pass',
            'summary' => 'The shared state fix ran.',
        ]], $payload['fixes']);
        $this->assertArrayNotHasKey('issues', $payload);
        $this->assertSame(0, $exitCode);
    }

    public function testAppliesTheFixOptionSelectedInteractively(): void
    {
        Doctor::diagnostic(OptionFixableDiagnostic::class);

        $this->artisan('doctor --only=OptionFixableDiagnostic')
            ->expectsChoice('Which option should fix the diagnostic?', 'a', [
                'a' => 'Option A',
                'b' => 'Option B',
                '__skip__' => 'Skip — leave unfixed',
            ])
            ->expectsOutputToContain('The option diagnostic was fixed with [a].')
            ->expectsOutputToContain('All diagnostics passed or were fixed.')
            ->assertExitCode(0);

        $this->assertSame('a', config('doctor-testing.option-fixed'));
    }

    public function testSkipsTheFixWhenTheInteractiveChoiceDeclinesIt(): void
    {
        Doctor::diagnostic(OptionFixableDiagnostic::class);

        $this->artisan('doctor --only=OptionFixableDiagnostic')
            ->expectsChoice('Which option should fix the diagnostic?', '__skip__', [
                'a' => 'Option A',
                'b' => 'Option B',
                '__skip__' => 'Skip — leave unfixed',
            ])
            ->expectsOutputToContain('The option diagnostic failed.')
            ->assertExitCode(1);

        $this->assertNull(config('doctor-testing.option-fixed'));
    }

    public function testPreservesNumericFixOptionKeys(): void
    {
        Doctor::diagnostic(OptionFixableDiagnostic::class);
        config(['doctor-testing.options' => [42 => 'Selected option', 99 => 'Other option']]);

        $this->artisan('doctor --only=OptionFixableDiagnostic')
            ->expectsChoice('Which option should fix the diagnostic?', '42', [
                42 => 'Selected option',
                99 => 'Other option',
                '__skip__' => 'Skip — leave unfixed',
            ])
            ->assertSuccessful();

        $this->assertSame('42', config('doctor-testing.option-fixed'));
    }

    public function testKeepsTheUnreachableCacheStoreWhenItsDeclineEntryIsChosen(): void
    {
        // Prompt interception requires the app to stay in the testing environment,
        // so map it to local mode instead of re-detecting the environment.
        config(['doctor.environments' => ['local' => ['testing']]]);

        $path = $this->temporaryPath . '/decline';

        mkdir($path, 0775, true);

        config(['cache' => [
            'default' => 'redis',
            'prefix' => '',
            'stores' => [
                'redis' => ['driver' => 'broken-driver'],
                'file' => ['driver' => 'file', 'path' => $path],
            ],
        ]]);

        $this->artisan('doctor --only=CacheStoreIsReachable')
            ->expectsChoice('Which cache store should the application use?', '__skip__', [
                'file' => 'File',
                '__skip__' => 'Keep Redis (repair it manually)',
            ])
            ->expectsOutputToContain('Check CACHE_STORE')
            ->expectsOutputToContain('Doctor found failing diagnostics.')
            ->assertExitCode(1);

        $this->assertSame('redis', config('cache.default'));
    }

    #[DataProvider('interactionModes')]
    public function testLeavesFixesThatRequireAChoiceUnfixedUnderFix(bool $interactive): void
    {
        Doctor::diagnostic(OptionFixableDiagnostic::class);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--only' => 'OptionFixableDiagnostic',
            '--fix' => true,
            ...($interactive ? [] : ['--no-interaction' => true]),
        ], $output);

        $contents = $output->fetch();
        $this->assertStringContainsString('The option diagnostic failed.', $contents);
        $this->assertStringContainsString('Apply one of the diagnostic fix options.', $contents);
        $this->assertNull(config('doctor-testing.option-fixed'));
        $this->assertSame(1, $exitCode);
    }

    /**
     * Provide interactive and noninteractive command inputs.
     */
    public static function interactionModes(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('interactionModes')]
    public function testPipedInputRequiresAnExplicitFixFlag(bool $fix): void
    {
        $script = $this->temporaryPath . '/piped-doctor.php';
        File::put($script, <<<'PHP'
            <?php

            require getenv('HYPERVEL_AUTOLOAD_PATH');

            define('BASE_PATH', getenv('DOCTOR_TEST_BASE_PATH'));
            $app = require BASE_PATH . '/bootstrap/app.php';
            $kernel = $app->make(\Hypervel\Contracts\Console\Kernel::class);
            $kernel->bootstrap();
            $app->detectEnvironment(fn () => 'local');
            $app->register(\Hypervel\Doctor\DoctorServiceProvider::class);
            $app->make(\Hypervel\Doctor\Doctor::class)->diagnostics([
                \Hypervel\Tests\Doctor\Fixtures\Diagnostics\FixesSharedStateDiagnostic::class,
                \Hypervel\Tests\Doctor\Fixtures\Diagnostics\OptionFixableDiagnostic::class,
            ]);

            $status = $kernel->call('doctor', [
                '--only' => 'testing',
                '--format' => 'cli',
                ...(($argv[1] ?? '') === '--fix' ? ['--fix' => true] : []),
            ]);

            echo 'FIX_STATE=' . json_encode([
                'fixed' => config('doctor-testing.shared-state-fixed', false),
                'option' => config('doctor-testing.option-fixed'),
            ]) . PHP_EOL;

            exit($status);
            PHP);

        $process = new Process(
            [PHP_BINARY, $script, ...($fix ? ['--fix'] : [])],
            $this->app->basePath(),
            [
                'HYPERVEL_AUTOLOAD_PATH' => $this->autoloadPath(),
                'DOCTOR_TEST_BASE_PATH' => $this->app->basePath(),
            ],
        );
        $process->setInput('');
        $process->run();

        $this->assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString(
            'FIX_STATE=' . json_encode(['fixed' => $fix, 'option' => null]),
            $process->getOutput(),
        );
    }

    public function testExposesFixOptionsInsteadOfTheFixableFlagInAgentOutput(): void
    {
        Doctor::diagnostic(OptionFixableDiagnostic::class);
        Doctor::diagnostic(FixesSharedStateDiagnostic::class);

        config(['doctor-testing.shared-state-fixed' => false]);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--only' => ['OptionFixableDiagnostic', 'FixesSharedStateDiagnostic'],
            '--format' => 'agent',
            '--fix' => true,
        ], $output);

        $payload = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([[
            'name' => 'Testing diagnostic offers fix options',
            'status' => 'fail',
            'summary' => 'The option diagnostic failed.',
            'fix' => 'Apply one of the diagnostic fix options.',
            'options' => ['a' => 'Option A', 'b' => 'Option B'],
        ]], $payload['issues']);
        $this->assertSame([[
            'name' => 'Testing diagnostic fixes shared state',
            'status' => 'pass',
            'summary' => 'The shared state fix ran.',
        ]], $payload['fixes']);
        $this->assertNull(config('doctor-testing.option-fixed'));
        $this->assertSame(1, $exitCode);
    }

    public function testValidatesFailOnBeforeRunningDiagnostics(): void
    {
        Doctor::diagnostic(LinkedDiagnostic::class);

        $this->artisan('doctor --only=LinkedDiagnostic --fail-on=broken')
            ->expectsOutputToContain('The --fail-on option must be one of: fail, warn, never.')
            ->doesntExpectOutputToContain('The linked diagnostic warned.')
            ->assertExitCode(1);
    }

    public function testAppliesConfiguredOnlySelectors(): void
    {
        config(['doctor.only' => ['ApplicationKeyIsSet']]);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--format' => 'json',
            '--fail-on' => 'never',
        ], $output);

        $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $payload['diagnostics']);
        $this->assertSame(ApplicationKeyIsSet::class, $payload['diagnostics'][0]['class']);
        $this->assertSame(0, $exitCode);
    }

    public function testAppliesConfiguredExceptSelectors(): void
    {
        config([
            'doctor.only' => ['ApplicationKeyIsSet', 'EnvironmentFileExists'],
            'doctor.except' => ['ApplicationKeyIsSet'],
        ]);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--format' => 'json',
            '--fail-on' => 'never',
        ], $output);

        $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $payload['diagnostics']);
        $this->assertSame(EnvironmentFileExists::class, $payload['diagnostics'][0]['class']);
        $this->assertSame(0, $exitCode);
    }

    public function testNarrowsConfiguredOnlySelectorsWithCommandOnlySelectors(): void
    {
        config(['doctor.only' => ['security']]);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        $exitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--only' => 'EnvironmentFileExists',
            '--format' => 'json',
            '--fail-on' => 'never',
        ], $output);

        $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([], $payload['diagnostics']);
        $this->assertSame(0, $exitCode);
    }

    public function testBindsTheDoctorServiceAndFacade(): void
    {
        Doctor::diagnostic(PassingDiagnostic::class);

        $this->assertSame([
            EnvironmentFileExists::class,
            ApplicationKeyIsSet::class,
            PhpVersionSatisfiesComposerRequirement::class,
            RequiredPhpExtensionsAreLoaded::class,
            RecommendedPhpExtensionsAreLoaded::class,
            ComposerAutoloadIsValid::class,
            ComposerLockIsFresh::class,
            ConfigurationFilesCanBeLoaded::class,
            ConfigurationCanBeCached::class,
            RequiredConfigurationValuesAreSet::class,
            BootstrapCacheMatchesEnvironment::class,
            DatabaseConnectionIsReachable::class,
            SqliteDatabaseExists::class,
            MigrationsAreUpToDate::class,
            CacheStoreIsReachable::class,
            RedisConnectionsAreReachable::class,
            RedisSupportsConfiguredFeatures::class,
            QueueConnectionIsReachable::class,
            QueueConnectionIsAsynchronous::class,
            SessionDriverIsReachable::class,
            ScheduledTasksRequireScheduler::class,
            PublicStorageLinkExists::class,
            FilesystemDisksAreReachable::class,
            StorageIsWritable::class,
            DebugModeMatchesEnvironment::class,
            EnvironmentFileIsGitIgnored::class,
            ComposerAuditPasses::class,
            PassingDiagnostic::class,
        ], $this->app->make(DoctorService::class)->registered());
    }

    public function testRegistersTheDefaultDiagnostics(): void
    {
        $this->assertSame([
            EnvironmentFileExists::class,
            ApplicationKeyIsSet::class,
            PhpVersionSatisfiesComposerRequirement::class,
            RequiredPhpExtensionsAreLoaded::class,
            RecommendedPhpExtensionsAreLoaded::class,
            ComposerAutoloadIsValid::class,
            ComposerLockIsFresh::class,
            ConfigurationFilesCanBeLoaded::class,
            ConfigurationCanBeCached::class,
            RequiredConfigurationValuesAreSet::class,
            BootstrapCacheMatchesEnvironment::class,
            DatabaseConnectionIsReachable::class,
            SqliteDatabaseExists::class,
            MigrationsAreUpToDate::class,
            CacheStoreIsReachable::class,
            RedisConnectionsAreReachable::class,
            RedisSupportsConfiguredFeatures::class,
            QueueConnectionIsReachable::class,
            QueueConnectionIsAsynchronous::class,
            SessionDriverIsReachable::class,
            ScheduledTasksRequireScheduler::class,
            PublicStorageLinkExists::class,
            FilesystemDisksAreReachable::class,
            StorageIsWritable::class,
            DebugModeMatchesEnvironment::class,
            EnvironmentFileIsGitIgnored::class,
            ComposerAuditPasses::class,
        ], $this->app->make(DoctorService::class)->registered());
    }
}
