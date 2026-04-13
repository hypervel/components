<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'config:clear')]
class ConfigClearCommand extends Command
{
    /**
     * The console command name.
     */
    protected ?string $name = 'config:clear';

    /**
     * The console command description.
     */
    protected string $description = 'Remove the configuration cache file';

    /**
     * Create a new config clear command instance.
     */
    public function __construct(
        protected Filesystem $files,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->files->delete($this->hypervel->getCachedConfigPath());

        $this->components->info('Configuration cache cleared successfully.');
    }
}
