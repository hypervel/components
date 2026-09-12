<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Fixtures\Commands;

use Hypervel\Console\Command;
use Hypervel\Contracts\Events\Dispatcher;
use Mockery as m;

class FooExitCommand extends Command
{
    /**
     * Create a new command instance.
     */
    public function __construct(?string $name = null)
    {
        parent::__construct($name);

        $this->eventDispatcher = m::mock(Dispatcher::class);
        $this->eventDispatcher->shouldReceive('hasListeners')->andReturnTrue();
        $this->eventDispatcher->shouldReceive('dispatch')->andReturnNull();
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        exit('11xxx');
    }
}
