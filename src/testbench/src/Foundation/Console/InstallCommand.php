<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Collection;
use Hypervel\Support\Composer;
use Hypervel\Testbench\Foundation\Console\Actions\EnsureDirectoryExists;
use Hypervel\Testbench\Foundation\Console\Actions\GeneratesFile;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Prompts\select;
use function Hypervel\Testbench\package_path;

#[AsCommand(name: 'package:install', description: 'Setup Workbench for package development')]
class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'package:install
                                {--force : Overwrite any existing files}
                                {--basic : Skip routes and Workbench discovery}';

    /**
     * The default Workbench autoload mappings.
     *
     * @var array<string, string>
     */
    protected const array WORKBENCH_AUTOLOAD_MAPPINGS = [
        'workbench/app/' => 'Workbench\App\\',
        'workbench/database/factories/' => 'Workbench\Database\Factories\\',
        'workbench/database/seeders/' => 'Workbench\Database\Seeders\\',
    ];

    /**
     * Execute the console command.
     */
    public function handle(Filesystem $filesystem, Composer $composer): int
    {
        $workingPath = package_path();
        $namespaces = $this->configureComposerAutoloads($composer, $workingPath);

        $this->prepareWorkbenchDirectories($filesystem, $workingPath);
        $this->copyTestbenchConfigurationFile($filesystem, $workingPath, $namespaces);
        $this->copyWorkbenchFiles($filesystem, $workingPath, $namespaces);
        $this->copyWorkbenchDotEnvFile($filesystem, $workingPath);

        $this->call('package:create-sqlite-db', ['--force' => true]);

        return $composer->setWorkingPath($workingPath)->dumpAutoloads() === self::SUCCESS
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * Configure Composer autoloading for the Workbench classes.
     *
     * @return array{app: string, factories: string, seeders: string}
     */
    protected function configureComposerAutoloads(Composer $composer, string $workingPath): array
    {
        $namespaces = [];

        $composer->setWorkingPath($workingPath)->modify(function (array $content) use (&$namespaces): array {
            /** @var array{autoload-dev?: array{psr-4?: array<string, array<int, string>|string>}} $content */
            $content['autoload-dev'] ??= [];
            $content['autoload-dev']['psr-4'] ??= [];

            foreach (self::WORKBENCH_AUTOLOAD_MAPPINGS as $path => $defaultNamespace) {
                $namespace = $this->namespaceForPath($content['autoload-dev']['psr-4'], $path);

                if ($namespace === null) {
                    $this->ensureNamespaceCanBeAdded($content['autoload-dev']['psr-4'], $defaultNamespace, $path);

                    $content['autoload-dev']['psr-4'][$defaultNamespace] = $path;
                    $namespace = $defaultNamespace;
                }

                $namespaces[$path] = $namespace;
            }

            return $content;
        });

        return [
            'app' => $namespaces['workbench/app/'],
            'factories' => $namespaces['workbench/database/factories/'],
            'seeders' => $namespaces['workbench/database/seeders/'],
        ];
    }

    /**
     * Resolve the namespace mapped to the given path.
     *
     * @param array<string, array<int, string>|string> $autoloads
     */
    protected function namespaceForPath(array $autoloads, string $path): ?string
    {
        $path = $this->normalizeAutoloadPath($path);

        foreach ($autoloads as $namespace => $paths) {
            foreach ((array) $paths as $candidate) {
                if ($this->normalizeAutoloadPath($candidate) === $path) {
                    return $namespace;
                }
            }
        }

        return null;
    }

    /**
     * Ensure a default namespace can be added safely.
     *
     * @param array<string, array<int, string>|string> $autoloads
     */
    protected function ensureNamespaceCanBeAdded(array $autoloads, string $namespace, string $path): void
    {
        if (! array_key_exists($namespace, $autoloads)) {
            return;
        }

        foreach ((array) $autoloads[$namespace] as $candidate) {
            if ($this->normalizeAutoloadPath($candidate) === $this->normalizeAutoloadPath($path)) {
                return;
            }
        }

        throw new LogicException(sprintf(
            'Unable to add Workbench autoload mapping [%s => %s] because [%s] is already mapped to a different path.',
            $namespace,
            $path,
            $namespace
        ));
    }

    /**
     * Normalize a Composer autoload path.
     */
    protected function normalizeAutoloadPath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/') . '/';
    }

    /**
     * Prepare Workbench directories.
     */
    protected function prepareWorkbenchDirectories(Filesystem $filesystem, string $workingPath): void
    {
        $directories = [
            join_paths('workbench', 'app', 'Models'),
            join_paths('workbench', 'app', 'Providers'),
            join_paths('workbench', 'database', 'factories'),
            join_paths('workbench', 'database', 'migrations'),
            join_paths('workbench', 'database', 'seeders'),
            join_paths('workbench', 'storage'),
        ];

        if ($this->option('basic') === false) {
            $directories = [
                ...$directories,
                join_paths('workbench', 'config'),
                join_paths('workbench', 'resources', 'views'),
                join_paths('workbench', 'routes'),
            ];
        }

        (new EnsureDirectoryExists(
            filesystem: $filesystem,
            components: $this->components,
            workingPath: $workingPath,
        ))->handle(
            (new Collection($directories))
                ->map(static fn (string $directory): string => join_paths($workingPath, $directory))
        );
    }

    /**
     * Copy the "testbench.yaml" file.
     *
     * @param array{app: string, factories: string, seeders: string} $namespaces
     */
    protected function copyTestbenchConfigurationFile(Filesystem $filesystem, string $workingPath, array $namespaces): void
    {
        $this->copyStub(
            $filesystem,
            $this->option('basic') === true ? 'testbench.basic.yaml.stub' : 'testbench.yaml.stub',
            join_paths($workingPath, 'testbench.yaml'),
            $workingPath,
            $this->workbenchReplacements($namespaces)
        );
    }

    /**
     * Copy Workbench files.
     *
     * @param array{app: string, factories: string, seeders: string} $namespaces
     */
    protected function copyWorkbenchFiles(Filesystem $filesystem, string $workingPath, array $namespaces): void
    {
        $replacements = $this->workbenchReplacements($namespaces);

        foreach ([
            'workbench.gitignore' => join_paths('workbench', '.gitignore'),
            'provider.stub' => join_paths('workbench', 'app', 'Providers', 'WorkbenchServiceProvider.php'),
            'user.stub' => join_paths('workbench', 'app', 'Models', 'User.php'),
            'user-factory.stub' => join_paths('workbench', 'database', 'factories', 'UserFactory.php'),
            'database-seeder.stub' => join_paths('workbench', 'database', 'seeders', 'DatabaseSeeder.php'),
        ] as $stub => $target) {
            $this->copyStub($filesystem, $stub, join_paths($workingPath, $target), $workingPath, $replacements);
        }

        if ($this->option('basic') === true) {
            return;
        }

        foreach ([
            'routes.web.stub' => join_paths('workbench', 'routes', 'web.php'),
            'routes.api.stub' => join_paths('workbench', 'routes', 'api.php'),
            'routes.console.stub' => join_paths('workbench', 'routes', 'console.php'),
        ] as $stub => $target) {
            $this->copyStub($filesystem, $stub, join_paths($workingPath, $target), $workingPath, $replacements);
        }
    }

    /**
     * Copy the ".env" file.
     */
    protected function copyWorkbenchDotEnvFile(Filesystem $filesystem, string $workingPath): void
    {
        if (! $this->input->isInteractive()) {
            return;
        }

        $from = $this->hypervel->basePath('.env.example');

        if (! $filesystem->isFile($from)) {
            return;
        }

        $choices = (new Collection($this->environmentFiles()))
            ->when(
                ! $this->option('force'),
                fn (Collection $files): Collection => $files->reject(
                    static fn (string $file): bool => $filesystem->isFile(join_paths($workingPath, 'workbench', $file))
                )
            )
            ->values();

        if (! $this->option('force') && $choices->isEmpty()) {
            $this->components->twoColumnDetail(
                'File [.env] already exists',
                '<fg=yellow;options=bold>SKIPPED</>'
            );

            return;
        }

        $targetEnvironmentFile = select(
            "Export '.env' file as?",
            [
                'skip' => 'Skip exporting .env',
                ...$choices->mapWithKeys(static fn (string $file): array => [$file => $file])->all(),
            ],
        );

        if (! is_string($targetEnvironmentFile) || $targetEnvironmentFile === 'skip') {
            return;
        }

        $this->copyStub($filesystem, $from, join_paths($workingPath, 'workbench', $targetEnvironmentFile), $workingPath);
    }

    /**
     * Get possible environment files.
     *
     * @return array<int, string>
     */
    protected function environmentFiles(): array
    {
        return [
            '.env',
            '.env.example',
            '.env.dist',
        ];
    }

    /**
     * Copy a stub file.
     *
     * @param array<string, string> $replacements
     */
    protected function copyStub(
        Filesystem $filesystem,
        string $stub,
        string $target,
        string $workingPath,
        array $replacements = []
    ): void {
        $source = $filesystem->isFile($stub)
            ? $stub
            : join_paths(__DIR__, 'stubs', $stub);

        $willGenerateFile = $this->option('force') === true || ! $filesystem->exists($target);

        (new GeneratesFile(
            filesystem: $filesystem,
            components: $this->components,
            force: (bool) $this->option('force'),
            workingPath: $workingPath,
        ))->handle($source, $target);

        if ($willGenerateFile && $filesystem->exists($target) && $replacements !== []) {
            $filesystem->replaceInFile(array_keys($replacements), array_values($replacements), $target);
        }
    }

    /**
     * Build Workbench stub replacements.
     *
     * @param array{app: string, factories: string, seeders: string} $namespaces
     * @return array<string, string>
     */
    protected function workbenchReplacements(array $namespaces): array
    {
        $appNamespace = rtrim($namespaces['app'], '\\');
        $factoryNamespace = rtrim($namespaces['factories'], '\\');
        $seederNamespace = rtrim($namespaces['seeders'], '\\');

        return [
            '{{ WorkbenchAppNamespace }}' => $appNamespace,
            '{{ WorkbenchFactoryNamespace }}' => $factoryNamespace,
            '{{ WorkbenchSeederNamespace }}' => $seederNamespace,
            '{{ WorkbenchServiceProvider }}' => $appNamespace . '\Providers\WorkbenchServiceProvider',
            '{{ WorkbenchDatabaseSeeder }}' => $seederNamespace . '\DatabaseSeeder',
            '{{ WorkbenchUserModel }}' => $appNamespace . '\Models\User',
            '{{ WorkbenchUserFactory }}' => $factoryNamespace . '\UserFactory',
        ];
    }
}
