<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'event:clear')]
class EventClearCommand extends Command
{
    /**
     * The console command name.
     */
    protected ?string $name = 'event:clear';

    /**
     * The console command description.
     */
    protected string $description = 'Clear all cached events and listeners';

    /**
     * Create a new event clear command instance.
     */
    public function __construct(
        protected Filesystem $files,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->files->delete($this->hypervel->getCachedEventsPath());

        $this->components->info('Cached events cleared successfully.');
    }
}
