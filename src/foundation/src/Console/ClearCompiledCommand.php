<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'clear-compiled')]
class ClearCompiledCommand extends Command
{
    /**
     * The console command name.
     */
    protected ?string $name = 'clear-compiled';

    /**
     * The console command description.
     */
    protected string $description = 'Remove the compiled class file';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        if (is_file($servicesPath = $this->hypervel->getCachedServicesPath())) {
            @unlink($servicesPath);
        }

        if (is_file($packagesPath = $this->hypervel->getCachedPackagesPath())) {
            @unlink($packagesPath);
        }

        $this->components->info('Compiled services and packages files removed successfully.');
    }
}
