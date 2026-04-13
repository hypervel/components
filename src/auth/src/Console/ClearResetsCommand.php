<?php

declare(strict_types=1);

namespace Hypervel\Auth\Console;

use Hypervel\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'auth:clear-resets')]
class ClearResetsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'auth:clear-resets {name? : The name of the password broker}';

    /**
     * The console command description.
     */
    protected string $description = 'Flush expired password reset tokens';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->hypervel['auth.password']->broker($this->argument('name'))->getRepository()->deleteExpired();

        $this->components->info('Expired reset tokens cleared successfully.');
    }
}
