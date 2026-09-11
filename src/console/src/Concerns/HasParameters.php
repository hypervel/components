<?php

declare(strict_types=1);

namespace Hypervel\Console\Concerns;

use Closure;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\Suggestion;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

trait HasParameters
{
    /**
     * Specify the arguments and options on the command.
     */
    protected function specifyParameters(): void
    {
        // We will loop through all of the arguments and options for the command and
        // set them all on the base command instance. This specifies what can get
        // passed into these commands as "parameters" to control the execution.
        foreach ($this->getArguments() as $arguments) {
            if ($arguments instanceof InputArgument) {
                $this->getDefinition()->addArgument($arguments);
            } else {
                $this->addArgument(...$arguments);
            }
        }

        foreach ($this->getOptions() as $options) {
            if ($options instanceof InputOption) {
                $this->getDefinition()->addOption($options);
            } else {
                $this->addOption(...$options);
            }
        }
    }

    /**
     * Get the console command arguments.
     *
     * @return (array{
     *     0: non-empty-string,
     *     1?: null|int-mask-of<InputArgument::IS_ARRAY|InputArgument::OPTIONAL|InputArgument::REQUIRED>,
     *     2?: string,
     *     3?: mixed,
     *     4?: Closure(CompletionInput): list<string|Suggestion>|list<string|Suggestion>
     * }|InputArgument)[]
     */
    protected function getArguments(): array
    {
        return [];
    }

    /**
     * Get the console command options.
     *
     * @return (array{
     *     0: non-empty-string,
     *     1?: null|non-empty-array<string>|string,
     *     2?: null|int-mask-of<InputOption::VALUE_*>,
     *     3?: string,
     *     4?: mixed,
     *     5?: Closure(CompletionInput): list<string|Suggestion>|list<string|Suggestion>
     * }|InputOption)[]
     */
    protected function getOptions(): array
    {
        return [];
    }
}
