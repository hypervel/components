<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use RuntimeException;
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
        $path = $this->hypervel->getCachedRoutesPath();

        if (! $this->files->delete($path) && $this->files->exists($path)) {
            throw new RuntimeException("Unable to delete the route cache file [{$path}].");
        }

        $this->components->info('Route cache cleared successfully.');
    }
}
