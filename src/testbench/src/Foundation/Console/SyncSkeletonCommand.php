<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Console\Concerns\CopyTestbenchFiles;
use Hypervel\Testbench\Workbench\Actions\AddAssetSymlinkFolders;
use Override;
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

    #[Override]
    protected function configure(): void
    {
        parent::configure();

        TerminatingConsole::flush();
    }

    /**
     * Execute the console command.
     */
    public function handle(Filesystem $filesystem, ConfigContract $config): int
    {
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
