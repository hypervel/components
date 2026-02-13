<?php

declare(strict_types=1);

namespace Hypervel\Support\Traits;

use Hypervel\Container\Container;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Container\Container as ContainerContract;

trait HasLaravelStyleCommand
{
    protected ContainerContract $app;

    public function __construct(?string $name = null)
    {
        parent::__construct($name);

        $this->app = Container::getInstance();
    }

    /**
     * Call another console command without output.
     */
    public function callSilent(string $command, array $arguments = []): int
    {
        return $this->app
            ->make(KernelContract::class)
            ->call($command, $arguments);
    }
}
