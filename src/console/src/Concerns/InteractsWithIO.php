<?php

declare(strict_types=1);

namespace Hypervel\Console\Concerns;

use Closure;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

trait InteractsWithIO
{
    protected ?InputInterface $input = null;

    /**
     * @var null|SymfonyStyle
     */
    protected ?OutputInterface $output = null;

    /**
     * The default verbosity of output commands.
     */
    protected int $verbosity = OutputInterface::VERBOSITY_NORMAL;

    /**
     * The mapping between human readable verbosity levels and Symfony's OutputInterface.
     */
    protected array $verbosityMap = [
        'v' => OutputInterface::VERBOSITY_VERBOSE,
        'vv' => OutputInterface::VERBOSITY_VERY_VERBOSE,
        'vvv' => OutputInterface::VERBOSITY_DEBUG,
        'quiet' => OutputInterface::VERBOSITY_QUIET,
        'normal' => OutputInterface::VERBOSITY_NORMAL,
    ];

    /**
     * Determine if the given argument is present.
     */
    public function hasArgument(int|string $name): bool
    {
        return $this->input->hasArgument($name);
    }

    /**
     * Get the value of a command argument.
     */
    public function argument(?string $key = null): array|bool|string|null
    {
        if (is_null($key)) {
            return $this->input->getArguments();
        }

        return $this->input->getArgument($key);
    }

    /**
     * Get all of the arguments passed to the command.
     */
    public function arguments(): array|bool|string|null
    {
        return $this->argument();
    }

    /**
     * Determine if the given option is present.
     */
    public function hasOption(string $name): bool
    {
        return $this->input->hasOption($name);
    }

    /**
     * Get the value of a command option.
     */
    public function option(?string $key = null): array|bool|float|int|string|null
    {
        if (is_null($key)) {
            return $this->input->getOptions();
        }

        return $this->input->getOption($key);
    }

    /**
     * Get all of the options passed to the command.
     */
    public function options(): array|bool|string|null
    {
        return $this->option();
    }

    /**
     * Confirm a question with the user.
     */
    public function confirm(string $question, bool $default = false): bool
    {
        return $this->output?->confirm($question, $default);
    }

    /**
     * Prompt the user for input.
     */
    public function ask(string $question, ?string $default = null): mixed
    {
        return $this->output?->ask($question, $default);
    }

    /**
     * Prompt the user for input with auto completion.
     */
    public function anticipate(string $question, array|callable $choices, ?string $default = null): mixed
    {
        return $this->askWithCompletion($question, $choices, $default);
    }

    /**
     * Prompt the user for input with auto completion.
     */
    public function askWithCompletion(string $question, array|callable $choices, ?string $default = null): mixed
    {
        $question = new Question($question, $default);

        is_callable($choices)
            ? $question->setAutocompleterCallback($choices)
            : $question->setAutocompleterValues($choices);

        return $this->output?->askQuestion($question);
    }

    /**
     * Prompt the user for input but hide the answer from the console.
     */
    public function secret(string $question, bool $fallback = true): mixed
    {
        $question = new Question($question);

        $question->setHidden(true)->setHiddenFallback($fallback);

        return $this->output?->askQuestion($question);
    }

    /**
     * Give the user a single choice from an array of answers.
     */
    public function choice(string $question, array $choices, int|string|null $default = null, ?int $attempts = null, bool $multiple = false): array|string
    {
        $question = new ChoiceQuestion($question, $choices, $default);

        $question->setMaxAttempts($attempts)->setMultiselect($multiple);

        return $this->output?->askQuestion($question);
    }

    /**
     * Format input to textual table.
     */
    public function table(array $headers, array|Arrayable $rows, string|TableStyle $tableStyle = 'default', array $columnStyles = []): void
    {
        $table = new Table($this->output);

        if ($rows instanceof Arrayable) {
            $rows = $rows->toArray();
        }

        $table->setHeaders((array) $headers)->setRows($rows)->setStyle($tableStyle);

        foreach ($columnStyles as $columnIndex => $columnStyle) {
            $table->setColumnStyle($columnIndex, $columnStyle);
        }

        $table->render();
    }

    /**
     * Execute a given callback while advancing a progress bar.
     */
    public function withProgressBar(iterable|int $totalSteps, Closure $callback): mixed
    {
        $bar = $this->output?->createProgressBar(
            is_iterable($totalSteps) ? count($totalSteps) : $totalSteps
        );

        $bar->start();

        if (is_iterable($totalSteps)) {
            foreach ($totalSteps as $value) {
                $callback($value, $bar);

                $bar->advance();
            }
        } else {
            $callback($bar);
        }

        $bar->finish();

        if (is_iterable($totalSteps)) {
            return $totalSteps;
        }

        return null;
    }

    /**
     * Write a string as information output.
     */
    public function info(string $string, int|string|null $verbosity = null): void
    {
        $this->line($string, 'info', $verbosity);
    }

    /**
     * Write a string as standard output.
     */
    public function line(string $string, ?string $style = null, int|string|null $verbosity = null): void
    {
        $styled = $style ? "<{$style}>{$string}</{$style}>" : $string;

        $this->output?->writeln($styled, $this->parseVerbosity($verbosity));
    }

    /**
     * Write a string as comment output.
     */
    public function comment(string $string, int|string|null $verbosity = null): void
    {
        $this->line($string, 'comment', $verbosity);
    }

    /**
     * Write a string as question output.
     */
    public function question(string $string, int|string|null $verbosity = null): void
    {
        $this->line($string, 'question', $verbosity);
    }

    /**
     * Write a string as error output.
     */
    public function error(string $string, int|string|null $verbosity = null): void
    {
        $this->line($string, 'error', $verbosity);
    }

    /**
     * Write a string as warning output.
     */
    public function warn(string $string, int|string|null $verbosity = null): void
    {
        if (! $this->output?->getFormatter()->hasStyle('warning')) {
            $style = new OutputFormatterStyle('yellow');

            $this->output?->getFormatter()->setStyle('warning', $style);
        }

        $this->line($string, 'warning', $verbosity);
    }

    /**
     * Write a string in an alert box.
     */
    public function alert(string $string, int|string|null $verbosity = null): void
    {
        $length = Str::length(strip_tags($string)) + 12;

        $this->comment(str_repeat('*', $length), $verbosity);
        $this->comment('*     ' . $string . '     *', $verbosity);
        $this->comment(str_repeat('*', $length), $verbosity);

        $this->comment('', $verbosity);
    }

    /**
     * Write a blank line.
     */
    public function newLine(int $count = 1): static
    {
        $this->output?->newLine($count);

        return $this;
    }

    /**
     * Set the input interface implementation.
     */
    public function setInput(InputInterface $input): void
    {
        $this->input = $input;
    }

    /**
     * Set the output interface implementation.
     *
     * @param SymfonyStyle $output
     */
    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    /**
     * Get the output implementation.
     *
     * @return null|SymfonyStyle
     */
    public function getOutput(): ?OutputInterface
    {
        return $this->output;
    }

    /**
     * Set the verbosity level.
     */
    protected function setVerbosity(int|string $level): void
    {
        $this->verbosity = $this->parseVerbosity($level);
    }

    /**
     * Get the verbosity level in terms of Symfony's OutputInterface level.
     */
    protected function parseVerbosity(int|string|null $level = null): int
    {
        if (isset($this->verbosityMap[$level])) {
            $level = $this->verbosityMap[$level];
        } elseif (! is_int($level)) {
            $level = $this->verbosity;
        }

        return $level;
    }
}
