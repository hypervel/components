<?php

declare(strict_types=1);

namespace Hypervel\Support\Traits;

use Hyperf\Context\ApplicationContext;
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
        $arguments['command'] = $command;

        return $this->getApplication()->find($command)->run($this->createInputFromArguments($arguments), new NullOutput());
    }
}
