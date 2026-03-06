<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console\Commands;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'route:clear')]
class RouteClearCommand extends Command
{
    /**
     * The console command name.
     */
    protected ?string $name = 'route:clear';

    /**
     * The console command description.
     */
    protected string $description = 'Remove the route cache file';

    /**
     * The filesystem instance.
     */
    protected Filesystem $files;

    /**
     * Create a new route clear command instance.
     */
    public function __construct(Filesystem $files)
    {
        parent::__construct();

        $this->files = $files;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->files->delete($this->hypervel->getCachedRoutesPath());

        $this->components->info('Route cache cleared successfully.');
    }
}
