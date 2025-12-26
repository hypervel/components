<?php

declare(strict_types=1);

namespace Hypervel\Support\Traits;

use Hyperf\Context\ApplicationContext;
use Hypervel\Foundation\Console\Contracts\Kernel as KernelContract;
use Hypervel\Foundation\Contracts\Application;

trait HasLaravelStyleCommand
{
    protected Application $app;

    public function __construct(?string $name = null)
    {
        parent::__construct($name);

        /** @var Application $app */
        $app = ApplicationContext::getContainer();
        $this->app = $app;
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
