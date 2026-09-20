<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'clear-compiled')]
class ClearCompiledCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'clear-compiled';

    /**
     * The console command description.
     */
    protected string $description = 'Remove the compiled class file';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        if (is_file($packagesPath = $this->hypervel->getCachedPackagesPath())
            && ! @unlink($packagesPath)) {
            // Another process may have removed the file after the first check.
            clearstatcache(false, $packagesPath);

            if (is_file($packagesPath)) {
                throw new RuntimeException("Unable to delete the compiled packages file [{$packagesPath}].");
            }
        }

        $this->components->info('Compiled packages file removed successfully.');
    }
}
