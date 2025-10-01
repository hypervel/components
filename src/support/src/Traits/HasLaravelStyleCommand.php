<?php

declare(strict_types=1);

namespace Hypervel\Support\Traits;

use Hyperf\Context\ApplicationContext;
use Hypervel\Foundation\Console\Contracts\Kernel as KernelContract;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Output\NullOutput;

trait HasLaravelStyleCommand
{
    protected ContainerInterface $app;

    public function __construct(?string $name = null)
    {
        parent::__construct($name);

        $this->app = ApplicationContext::getContainer();
    }

    /**
     * Call another console command without output.
     */
    public function callSilent(string $command, array $arguments = []): int
    {
        return $this->app
            ->get(KernelContract::class)
            ->call($command, $arguments);
    }
}
