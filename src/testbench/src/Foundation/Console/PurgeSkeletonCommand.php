<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Actions\DeleteVendorSymlink;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\Workbench\Actions\RemoveAssetSymlinkFolders;
use Symfony\Component\Console\Attribute\AsCommand;

use function Hypervel\Filesystem\join_paths;

#[AsCommand(name: 'package:purge-skeleton', description: 'Purge skeleton folder to original state')]
class PurgeSkeletonCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'package:purge-skeleton';

    /**
     * Execute the console command.
     */
    public function handle(Filesystem $filesystem, ConfigContract $config): int
    {
        $this->call('config:clear');
        $this->call('event:clear');
        $this->call('route:clear');
        $this->call('view:clear');

        (new RemoveAssetSymlinkFolders($filesystem, $config))->handle();

        ['files' => $files, 'directories' => $directories] = $config->getPurgeAttributes();

        $environmentFile = Env::get('TESTBENCH_ENVIRONMENT_FILENAME', '.env');

        (new Actions\DeleteFiles(
            filesystem: $filesystem,
        ))->handle(
            (new Collection([
                $environmentFile,
                "{$environmentFile}.backup",
                join_paths('bootstrap', 'cache', 'testbench.yaml'),
                join_paths('bootstrap', 'cache', 'testbench.yaml.backup'),
            ]))->map(fn (string $file) => $this->hypervel->basePath($file))
        );

        (new Actions\DeleteFiles(
            filesystem: $filesystem,
        ))->handle(
            (new LazyCollection(function () use ($filesystem) {
                yield $this->hypervel->databasePath('database.sqlite');
                yield $filesystem->glob($this->hypervel->basePath(join_paths('routes', 'testbench-*.php')));
                yield $filesystem->glob($this->hypervel->storagePath(join_paths('app', 'public', '*')));
                yield $filesystem->glob($this->hypervel->storagePath(join_paths('app', '*')));
                yield $filesystem->glob($this->hypervel->storagePath(join_paths('framework', 'sessions', '*')));
            }))->flatten()
        );

        (new Actions\DeleteFiles(
            filesystem: $filesystem,
            components: $this->components,
        ))->handle(
            (new LazyCollection($files))
                ->map(fn (string $file) => $this->hypervel->basePath($file))
                ->map(static fn (string $file) => str_contains($file, '*') ? [...$filesystem->glob($file)] : $file)
                ->flatten()
                ->reject(static fn (string $file) => str_contains($file, '*'))
        );

        (new Actions\DeleteDirectories(
            filesystem: $filesystem,
            components: $this->components,
        ))->handle(
            (new Collection($directories))
                ->map(fn (string $directory) => $this->hypervel->basePath($directory))
                ->map(static fn (string $directory) => str_contains($directory, '*') ? [...$filesystem->glob($directory)] : $directory)
                ->flatten()
                ->reject(static fn (string $directory) => str_contains($directory, '*'))
        );

        TerminatingConsole::before(function (): void {
            (new DeleteVendorSymlink())->handle($this->hypervel);
        });

        return self::SUCCESS;
    }
}
