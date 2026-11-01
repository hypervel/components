<?php

declare(strict_types=1);

namespace Hypervel\Session\Console;

use Hypervel\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'session:garbage-collect')]
class SessionGarbageCollectCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'session:garbage-collect';

    /**
     * The console command description.
     */
    protected string $description = 'Run the session garbage collector';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->hypervel->make('session')
            ->driver()
            ->getHandler()
            ->gc($this->hypervel->make('config')->integer('session.lifetime') * 60);

        $this->components->info('Session garbage collector ran successfully.');
    }
}
