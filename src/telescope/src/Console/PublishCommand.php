<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Console;

use Hypervel\Foundation\Console\Command;

class PublishCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'telescope:publish {--force : Overwrite any existing files}';

    /**
     * The console command description.
     */
    protected string $description = 'Publish all of the Telescope resources';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->call('vendor:publish', [
            '--tag' => 'telescope-config',
            '--force' => $this->option('force'),
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'telescope-assets',
            '--force' => true,
        ]);
    }
}
