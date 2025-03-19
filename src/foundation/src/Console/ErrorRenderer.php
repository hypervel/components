<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use NunoMaduro\Collision\Adapters\Laravel\Inspector;
use NunoMaduro\Collision\Provider;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ErrorRenderer
{
    protected InputInterface $input;

    protected OutputInterface $output;

    public function __construct(
        ?InputInterface $input = null,
        ?OutputInterface $output = null
    ) {
        $this->input = $input ?? new ArgvInput();
        $this->output = $output ?? new ConsoleOutput();

        $this->setVerbosity($this->input, $this->output);
    }

    protected function setVerbosity(InputInterface $input, OutputInterface $output): void
    {
        if ($input->hasParameterOption(['--silent'], true) === true) {
            $output->setVerbosity(OutputInterface::VERBOSITY_SILENT);
        } elseif ($input->hasParameterOption(['--quiet', '-q'], true) === true) {
            $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        } elseif ($input->hasParameterOption('-vvv', true) || $input->hasParameterOption('--verbose=3', true) || $input->getParameterOption('--verbose', false, true) === 3) {
            $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        } elseif ($input->hasParameterOption('-vv', true) || $input->hasParameterOption('--verbose=2', true) || $input->getParameterOption('--verbose', false, true) === 2) {
            $output->setVerbosity(OutputInterface::VERBOSITY_VERY_VERBOSE);
        } elseif ($input->hasParameterOption('-v', true) || $input->hasParameterOption('--verbose=1', true) || $input->hasParameterOption('--verbose', true) || $input->getParameterOption('--verbose', false, true)) {
            $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
        }
    }

    public function render(Throwable $throwable): void
    {
        if (class_exists(Provider::class)) {
            $this->renderCollision($throwable);
            return;
        }

        (new SymfonyApplication())
            ->renderThrowable($throwable, $this->output);
    }

    protected function renderCollision(Throwable $throwable): void
    {
        $handler = (new Provider())->register()
            ->getHandler()
            ->setOutput($this->output);
        $handler->setInspector(new Inspector($throwable));
        $handler->handle();
    }
}
