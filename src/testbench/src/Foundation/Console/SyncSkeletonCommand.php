<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Console\Concerns\CopyTestbenchFiles;
use Hypervel\Testbench\Workbench\Actions\AddAssetSymlinkFolders;
use Symfony\Component\Console\Attribute\AsCommand;

use function Hypervel\Testbench\package_path;

#[AsCommand(name: 'package:sync-skeleton', description: 'Sync skeleton folder to be served externally')]
class SyncSkeletonCommand extends Command
{
    use CopyTestbenchFiles;

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'package:sync-skeleton';

    /**
     * Execute the console command.
     */
    public function handle(Filesystem $filesystem, ConfigContract $config): int
    {
        if (Bootstrapper::isRuntimeCopy($this->hypervel->basePath())) {
            $this->components->error(
                'The skeleton is a disposable copy that is deleted when its command exits, so it cannot be synced. The serve command creates the configured links while it runs.'
            );

            return self::FAILURE;
        }

        TerminatingConsole::flush();

        $this->copyTestbenchConfigurationFile(
            $this->hypervel,
            $filesystem,
            package_path(),
            backupExistingFile: false,
            resetOnTerminating: false
        );

        $this->copyTestbenchDotEnvFile(
            $this->hypervel,
            $filesystem,
            package_path(),
            backupExistingFile: false,
            resetOnTerminating: false
        );

        (new AddAssetSymlinkFolders($filesystem, $config))->handle();

        return self::SUCCESS;
    }
}
