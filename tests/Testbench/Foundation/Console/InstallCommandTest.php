<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console;

use Closure;
use Hypervel\Console\OutputStyle;
use Hypervel\Console\View\Components\Factory;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Composer;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\Foundation\Console\InstallCommand;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Override;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\Process;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Support\php_binary;

#[RequiresOperatingSystem('Linux|Darwin')]
class InstallCommandTest extends TestCase
{
    private Filesystem $filesystem;

    private string $workingPath;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->workingPath = ParallelTesting::tempDir('InstallCommandTest-' . uniqid());

        $this->filesystem->deleteDirectory($this->workingPath);
        $this->filesystem->ensureDirectoryExists($this->workingPath);

        $this->writeComposerJson();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->workingPath);

        parent::tearDown();
    }

    #[Test]
    public function itInstallsTheDefaultWorkbenchScaffold(): void
    {
        $runtimeBasePath = $this->path('runtime-hypervel');

        $this->filesystem->copyDirectory($this->componentPath('src/testbench/hypervel'), $runtimeBasePath);

        $this->runInstallCommand(['--no-interaction'], [
            'TESTBENCH_BASE_PATH' => $runtimeBasePath,
            'TESTBENCH_PACKAGE_REMOTE' => '(true)',
        ]);

        $this->assertFileExists($this->path('testbench.yaml'));
        $this->assertFileExists($this->path('workbench/.gitignore'));
        $this->assertFileExists($this->path('workbench/app/Providers/WorkbenchServiceProvider.php'));
        $this->assertFileExists($this->path('workbench/app/Models/User.php'));
        $this->assertFileExists($this->path('workbench/database/factories/UserFactory.php'));
        $this->assertFileExists($this->path('workbench/database/migrations/.gitkeep'));
        $this->assertFileExists($this->path('workbench/database/seeders/DatabaseSeeder.php'));
        $this->assertFileExists($this->path('workbench/routes/web.php'));
        $this->assertFileExists($this->path('workbench/routes/api.php'));
        $this->assertFileExists($this->path('workbench/routes/console.php'));
        $this->assertFileDoesNotExist($this->path('workbench/.env'));
        $this->assertFileExists(join_paths($runtimeBasePath, 'database', 'database.sqlite'));

        $this->assertSame([
            'Tests\\\\' => 'tests/',
            'Workbench\App\\' => 'workbench/app/',
            'Workbench\Database\Factories\\' => 'workbench/database/factories/',
            'Workbench\Database\Seeders\\' => 'workbench/database/seeders/',
        ], $this->composerAutoloadDevNamespaces());

        $config = Config::loadFromYaml($this->workingPath);

        $this->assertSame([
            'Workbench\App\Providers\WorkbenchServiceProvider',
        ], $config->getExtraAttributes()['providers']);
        $this->assertSame(['workbench/database/migrations'], $config['migrations']);
        $this->assertSame(['Workbench\Database\Seeders\DatabaseSeeder'], $config['seeders']);
        $this->assertSame([
            'install' => true,
            'auth' => true,
            'health' => true,
            'sync' => [
                [
                    'from' => 'storage',
                    'to' => 'workbench/storage',
                    'reverse' => true,
                ],
            ],
            'discovers' => [
                'config' => true,
                'factories' => true,
                'web' => true,
                'api' => true,
                'commands' => true,
                'components' => false,
                'views' => true,
            ],
        ], $config->getWorkbenchAttributes());
    }

    #[Test]
    public function itInstallsTheBasicWorkbenchScaffold(): void
    {
        $this->runInstallCommand(['--basic', '--no-interaction']);

        $this->assertFileExists($this->path('testbench.yaml'));
        $this->assertFileExists($this->path('workbench/app/Providers/WorkbenchServiceProvider.php'));
        $this->assertFileExists($this->path('workbench/app/Models/User.php'));
        $this->assertFileExists($this->path('workbench/database/factories/UserFactory.php'));
        $this->assertFileExists($this->path('workbench/database/seeders/DatabaseSeeder.php'));
        $this->assertDirectoryDoesNotExist($this->path('workbench/config'));
        $this->assertDirectoryDoesNotExist($this->path('workbench/resources'));
        $this->assertDirectoryDoesNotExist($this->path('workbench/routes'));

        $config = Config::loadFromYaml($this->workingPath);

        $this->assertSame([
            'install' => true,
            'auth' => true,
            'health' => null,
            'sync' => [],
            'discovers' => [
                'config' => false,
                'factories' => false,
                'web' => false,
                'api' => false,
                'commands' => false,
                'components' => false,
                'views' => false,
            ],
        ], $config->getWorkbenchAttributes());
    }

    #[Test]
    public function itDoesNotOverwriteExistingFilesUnlessForced(): void
    {
        $this->filesystem->put($this->path('testbench.yaml'), "workbench:\n  install: false\n");
        $this->filesystem->ensureDirectoryExists($this->path('workbench/routes'));
        $this->filesystem->put($this->path('workbench/routes/web.php'), 'existing route file');

        $this->runInstallCommand(['--no-interaction']);

        $this->assertSame("workbench:\n  install: false\n", $this->filesystem->get($this->path('testbench.yaml')));
        $this->assertSame('existing route file', $this->filesystem->get($this->path('workbench/routes/web.php')));

        $autoloadNamespaces = $this->composerAutoloadDevNamespaces();

        $this->runInstallCommand(['--no-interaction']);

        $this->assertSame($autoloadNamespaces, $this->composerAutoloadDevNamespaces());

        $this->runInstallCommand(['--force', '--no-interaction']);

        $this->assertStringContainsString('Workbench\App\Providers\WorkbenchServiceProvider', $this->filesystem->get($this->path('testbench.yaml')));
        $this->assertStringContainsString('Workbench', $this->filesystem->get($this->path('workbench/routes/web.php')));
    }

    #[Test]
    public function itUsesExistingWorkbenchAutoloadNamespaces(): void
    {
        $this->writeComposerJson([
            'autoload-dev' => [
                'psr-4' => [
                    'Tests\\\\' => 'tests/',
                    'App\\' => 'workbench/app/',
                    'Database\Factories\\' => 'workbench/database/factories/',
                    'Database\Seeders\\' => 'workbench/database/seeders/',
                ],
            ],
        ]);

        $this->runInstallCommand(['--no-interaction']);

        $this->assertSame([
            'Tests\\\\' => 'tests/',
            'App\\' => 'workbench/app/',
            'Database\Factories\\' => 'workbench/database/factories/',
            'Database\Seeders\\' => 'workbench/database/seeders/',
        ], $this->composerAutoloadDevNamespaces());
        $this->assertStringContainsString('namespace App\Providers;', $this->filesystem->get($this->path('workbench/app/Providers/WorkbenchServiceProvider.php')));
        $this->assertStringContainsString('App\Providers\WorkbenchServiceProvider', $this->filesystem->get($this->path('testbench.yaml')));
        $this->assertStringContainsString('use Database\Factories\UserFactory;', $this->filesystem->get($this->path('workbench/database/seeders/DatabaseSeeder.php')));
    }

    #[Test]
    public function itFailsWhenDefaultWorkbenchAutoloadNamespaceUsesADifferentPath(): void
    {
        $this->writeComposerJson([
            'autoload-dev' => [
                'psr-4' => [
                    'Tests\\\\' => 'tests/',
                    'Workbench\App\\' => 'app/',
                ],
            ],
        ]);

        $process = $this->runInstallCommand(['--no-interaction'], mustRun: false);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Unable to add Workbench autoload mapping [Workbench\App\ => workbench/app/]', $process->getErrorOutput());
        $this->assertStringContainsString('because [Workbench\App\] is already mapped to a different path.', $process->getErrorOutput());
    }

    #[Test]
    public function itCanSkipExportingTheWorkbenchEnvironmentFile(): void
    {
        $this->filesystem->put($this->path('.env.example'), 'APP_NAME=Workbench');

        $this->runEnvironmentFileCopyCommand(
            [],
            fn (Factory $components) => $components
                ->expects('choice')
                ->with("Export '.env' file as?", [
                    'skip' => 'Skip exporting .env',
                    '.env' => '.env',
                    '.env.example' => '.env.example',
                    '.env.dist' => '.env.dist',
                ], null)
                ->andReturn('skip')
        );

        $this->assertFileDoesNotExist($this->path('workbench/.env'));
        $this->assertFileDoesNotExist($this->path('workbench/.env.example'));
        $this->assertFileDoesNotExist($this->path('workbench/.env.dist'));
    }

    #[Test]
    public function itExportsTheSelectedWorkbenchEnvironmentFile(): void
    {
        $this->filesystem->put($this->path('.env.example'), 'APP_NAME=Workbench');

        $this->runEnvironmentFileCopyCommand(
            [],
            fn (Factory $components) => $components
                ->expects('choice')
                ->with("Export '.env' file as?", [
                    'skip' => 'Skip exporting .env',
                    '.env' => '.env',
                    '.env.example' => '.env.example',
                    '.env.dist' => '.env.dist',
                ], null)
                ->andReturn('.env')
        );

        $this->assertSame('APP_NAME=Workbench', $this->filesystem->get($this->path('workbench/.env')));
    }

    #[Test]
    public function itSkipsEnvironmentExportWhenEveryEnvironmentFileAlreadyExists(): void
    {
        $this->filesystem->put($this->path('.env.example'), 'APP_NAME=Workbench');
        $this->filesystem->ensureDirectoryExists($this->path('workbench'));
        $this->filesystem->put($this->path('workbench/.env'), 'existing .env');
        $this->filesystem->put($this->path('workbench/.env.example'), 'existing .env.example');
        $this->filesystem->put($this->path('workbench/.env.dist'), 'existing .env.dist');

        $this->runEnvironmentFileCopyCommand(
            [],
            fn (Factory $components) => $components
                ->expects('twoColumnDetail')
                ->with('File [.env] already exists', '<fg=yellow;options=bold>SKIPPED</>')
        );

        $this->assertSame('existing .env', $this->filesystem->get($this->path('workbench/.env')));
        $this->assertSame('existing .env.example', $this->filesystem->get($this->path('workbench/.env.example')));
        $this->assertSame('existing .env.dist', $this->filesystem->get($this->path('workbench/.env.dist')));
    }

    #[Test]
    public function itOffersExistingEnvironmentFilesWhenForced(): void
    {
        $this->filesystem->put($this->path('.env.example'), 'APP_NAME=Workbench');
        $this->filesystem->ensureDirectoryExists($this->path('workbench'));
        $this->filesystem->put($this->path('workbench/.env.dist'), 'existing .env.dist');

        $this->runEnvironmentFileCopyCommand(
            ['--force' => true],
            fn (Factory $components) => $components
                ->expects('choice')
                ->with("Export '.env' file as?", [
                    'skip' => 'Skip exporting .env',
                    '.env' => '.env',
                    '.env.example' => '.env.example',
                    '.env.dist' => '.env.dist',
                ], null)
                ->andReturn('.env.dist')
        );

        $this->assertSame('APP_NAME=Workbench', $this->filesystem->get($this->path('workbench/.env.dist')));
    }

    /**
     * Run the package install command against the temporary package.
     *
     * @param array<int, string> $arguments
     * @param array<string, string> $environment
     */
    private function runInstallCommand(array $arguments = [], array $environment = [], bool $mustRun = true): Process
    {
        $process = new Process(
            [
                php_binary(),
                $this->componentPath('src/testbench/bin/testbench'),
                'package:install',
                ...$arguments,
            ],
            $this->workingPath,
            [
                'TESTBENCH_WORKING_PATH' => $this->workingPath,
                ...$environment,
            ],
        );

        $process->setTimeout(null);

        if ($mustRun) {
            $process->mustRun();
        } else {
            $process->run();
        }

        return $process;
    }

    /**
     * Run the command branch that copies the Workbench environment file.
     *
     * @param array<string, mixed> $input
     * @param Closure(Factory): void $expectations
     */
    private function runEnvironmentFileCopyCommand(array $input, Closure $expectations): void
    {
        $command = new class($this->filesystem, $this->workingPath) extends InstallCommand {
            public function __construct(
                private readonly Filesystem $filesystem,
                private readonly string $workingPath
            ) {
                parent::__construct();
            }

            /**
             * Execute the console command.
             */
            public function handle(Filesystem $filesystem, Composer $composer): int
            {
                $this->copyWorkbenchDotEnvFile($this->filesystem, $this->workingPath);

                return self::SUCCESS;
            }
        };

        $application = m::mock(Application::class);
        $outputStyle = m::mock(OutputStyle::class);
        $components = m::mock(Factory::class);

        $this->filesystem->ensureDirectoryExists($this->path('workbench'));

        $command->setHypervel($application);

        $application->shouldReceive('make')->withArgs(fn (string $abstract): bool => $abstract === OutputStyle::class)->andReturn($outputStyle);
        $application->shouldReceive('make')->withArgs(fn (string $abstract): bool => $abstract === Factory::class)->andReturn($components);
        $application->shouldReceive('bound')->andReturn(false);
        $application->shouldReceive('basePath')->with('.env.example')->andReturn($this->path('.env.example'));
        $application->shouldReceive('runningUnitTests')->andReturn(true);
        $application->shouldReceive('call')->with([$command, 'handle'])->andReturnUsing(fn (array $callback): int => $callback[0]->handle($this->filesystem, m::mock(Composer::class)));
        $outputStyle->shouldReceive('newLinesWritten')->andReturn(1);
        $components->shouldReceive('task')->zeroOrMoreTimes();

        $expectations($components);

        $status = $command->run(new ArrayInput($input), new NullOutput);

        $this->assertSame(InstallCommand::SUCCESS, $status);
    }

    /**
     * Write composer.json into the temporary package.
     *
     * @param array<string, mixed> $overrides
     */
    private function writeComposerJson(array $overrides = []): void
    {
        $composer = array_replace_recursive([
            'name' => 'hypervel/install-command-test',
            'description' => 'Test package',
            'autoload' => [
                'psr-4' => [
                    'Package\\\\' => 'src/',
                ],
            ],
            'autoload-dev' => [
                'psr-4' => [
                    'Tests\\\\' => 'tests/',
                ],
            ],
        ], $overrides);

        $this->filesystem->put(
            $this->path('composer.json'),
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Get composer autoload-dev namespaces.
     *
     * @return array<string, array<int, string>|string>
     */
    private function composerAutoloadDevNamespaces(): array
    {
        /** @var array{autoload-dev: array{psr-4: array<string, array<int, string>|string>}} $composer */
        $composer = json_decode($this->filesystem->get($this->path('composer.json')), true, 512, JSON_THROW_ON_ERROR);

        return $composer['autoload-dev']['psr-4'];
    }

    /**
     * Get a temporary package path.
     */
    private function path(string $path): string
    {
        return join_paths($this->workingPath, $path);
    }

    /**
     * Get a component repository path.
     */
    private function componentPath(string $path): string
    {
        return join_paths(dirname(__DIR__, 4), $path);
    }
}
