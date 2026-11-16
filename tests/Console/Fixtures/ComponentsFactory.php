<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Fixtures;

use Hypervel\Console\View\Components\Factory;
use Symfony\Component\Console\Output\OutputInterface;

// Declare the dynamically dispatched component methods so Double can verify their arguments and calls.
abstract class ComponentsFactory extends Factory
{
    /**
     * Prompt for a text answer.
     */
    abstract public function ask(string $question, ?string $default = null, bool $multiline = false): mixed;

    /**
     * Prompt with completion choices.
     */
    abstract public function askWithCompletion(string $question, iterable|callable $choices, ?string $default = null): mixed;

    /**
     * Prompt for a choice.
     */
    abstract public function choice(string $question, array $choices, mixed $default = null, ?int $attempts = null, bool $multiple = false): mixed;

    /**
     * Prompt for confirmation.
     */
    abstract public function confirm(string $question, bool $default = false): bool;

    /**
     * Render an error message.
     */
    abstract public function error(string $string, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void;

    /**
     * Prompt for a hidden answer.
     */
    abstract public function secret(string $question, bool $fallback = true): mixed;
}
