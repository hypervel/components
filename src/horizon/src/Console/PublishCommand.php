<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;

class PublishCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected string $signature = 'horizon:publish';

    /**
     * The console command description.
     */
    protected string $description = 'Publish all of the Horizon resources';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->components->warn('Horizon no longer publishes its assets. You may stop calling the `horizon:publish` command.');
    }
}
