<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Command;

use Hypervel\Console\Command;
use Hypervel\Contracts\Event\Dispatcher;
use Mockery as m;

class FooExitCommand extends Command
{
    public function __construct(?string $name = null)
    {
        parent::__construct($name);

        $this->eventDispatcher = m::mock(Dispatcher::class);
        $this->eventDispatcher->shouldReceive('dispatch')->andReturnNull();
    }

    public function handle(): void
    {
        exit('11xxx');
    }
}
