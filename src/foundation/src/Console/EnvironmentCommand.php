<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'env')]
class EnvironmentCommand extends Command
{
    /**
     * The console command name.
     */
    protected ?string $name = 'env';

    /**
     * The console command description.
     */
    protected string $description = 'Display the current framework environment';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->components->info(sprintf(
            'The application environment is [%s].',
            $this->hypervel['env'],
        ));
    }
}
