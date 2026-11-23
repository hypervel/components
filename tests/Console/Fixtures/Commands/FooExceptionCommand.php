<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Fixtures\Commands;

use Hypervel\Console\Command;
use Hypervel\Contracts\Events\Dispatcher;
use JMac\Testing\Double;
use RuntimeException;

class FooExceptionCommand extends Command
{
    /**
     * Create a new command instance.
     */
    public function __construct(?string $name = null)
    {
        parent::__construct($name);

        $this->eventDispatcher = Double::for(Dispatcher::class)->strict();
        $this->eventDispatcher->allows('hasListeners')->returns(true);
        $this->eventDispatcher->allows('dispatch')->returns(null);
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        throw new RuntimeException('xxx', 99);
    }
}
