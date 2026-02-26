<?php

declare(strict_types=1);

namespace Hypervel\Console\Concerns;

use Closure;
use Hypervel\Contracts\Console\PromptsForMissingInput as PromptsForMissingInputContract;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Hypervel\Prompts\text;

trait PromptsForMissingInput
{
    /**
     * Interact with the user before validating the input.
     */
    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        parent::interact($input, $output);

        if ($this instanceof PromptsForMissingInputContract) {
            $this->promptForMissingArguments($input, $output);
        }
    }

    /**
     * Prompt the user for any missing arguments.
     */
    protected function promptForMissingArguments(InputInterface $input, OutputInterface $output): void
    {
        $prompted = (new Collection($this->getDefinition()->getArguments()))
            ->reject(fn (InputArgument $argument) => $argument->getName() === 'command')
            ->filter(fn (InputArgument $argument) => $argument->isRequired() && match (true) {
                $argument->isArray() => empty($input->getArgument($argument->getName())),
                default => is_null($input->getArgument($argument->getName())),
            })
            ->each(function (InputArgument $argument) use ($input) {
                $label = $this->promptForMissingArgumentsUsing()[$argument->getName()]
                    ?? 'What is ' . lcfirst($argument->getDescription() ?: ('the ' . $argument->getName())) . '?';

                if ($label instanceof Closure) {
                    return $input->setArgument($argument->getName(), $argument->isArray() ? Arr::wrap($label()) : $label());
                }

                if (is_array($label)) {
                    [$label, $placeholder] = $label;
                }

                $answer = text(
                    label: $label,
                    placeholder: $placeholder ?? '',
                    validate: fn ($value) => empty($value) ? "The {$argument->getName()} is required." : null,
                );

                $input->setArgument($argument->getName(), $argument->isArray() ? [$answer] : $answer);
            })
            ->isNotEmpty();

        if ($prompted) {
            $this->afterPromptingForMissingArguments($input, $output);
        }
    }

    /**
     * Prompt for missing input arguments using the returned questions.
     *
     * @return array<string, array{string, string}|Closure(): (array<int|string>|bool|int|string)|string>
     */
    protected function promptForMissingArgumentsUsing(): array
    {
        return [];
    }

    /**
     * Perform actions after the user was prompted for missing arguments.
     */
    protected function afterPromptingForMissingArguments(InputInterface $input, OutputInterface $output): void
    {
    }

    /**
     * Determine whether the input contains any options that differ from the default values.
     */
    protected function didReceiveOptions(InputInterface $input): bool
    {
        return (new Collection($this->getDefinition()->getOptions()))
            ->reject(fn ($option) => $input->getOption($option->getName()) === $option->getDefault())
            ->isNotEmpty();
    }
}
