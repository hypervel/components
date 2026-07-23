<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Hypervel\Console\Contracts\NewLineAware;
use Override;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

class OutputStyle extends SymfonyStyle implements NewLineAware
{
    /**
     * The number of trailing new lines written by the last output.
     *
     * This is initialized as 1 to account for the new line written by the shell after executing a command.
     */
    protected int $newLinesWritten = 1;

    /**
     * Create a new Console OutputStyle instance.
     */
    public function __construct(
        InputInterface $input,
        private OutputInterface $output,
    ) {
        parent::__construct($input, $output);
    }

    #[Override]
    public function askQuestion(Question $question): mixed
    {
        try {
            return parent::askQuestion($question);
        } finally {
            ++$this->newLinesWritten;
        }
    }

    #[Override]
    public function write(string|iterable $messages, bool $newline = false, int $options = 0): void
    {
        if (! is_iterable($messages)) {
            parent::write($messages, $newline, $options);

            if ($this->shouldWrite($options) && ($messages !== '' || $newline)) {
                $this->newLinesWritten = $this->trailingNewLineCount($messages) + (int) $newline;
            }

            return;
        }

        foreach ($messages as $message) {
            parent::write($message, $newline, $options);

            if ($this->shouldWrite($options) && ($message !== '' || $newline)) {
                $this->newLinesWritten = $this->trailingNewLineCount($message) + (int) $newline;
            }
        }
    }

    #[Override]
    public function writeln(string|iterable $messages, int $type = self::OUTPUT_NORMAL): void
    {
        $this->write($messages, true, $type);
    }

    #[Override]
    public function newLine(int $count = 1): void
    {
        parent::newLine($count);

        if ($this->shouldWrite(0)) {
            $this->newLinesWritten += $count;
        }
    }

    public function newLinesWritten(): int
    {
        if ($this->output instanceof static) {
            return $this->output->newLinesWritten();
        }

        return $this->newLinesWritten;
    }

    /**
     * Count the number of trailing new lines in a string.
     */
    protected function trailingNewLineCount(string $message): int
    {
        return strlen($message) - strlen(rtrim($message, PHP_EOL));
    }

    /**
     * Determine whether output with the given options is visible.
     */
    protected function shouldWrite(int $options): bool
    {
        $verbosities = self::VERBOSITY_QUIET
            | self::VERBOSITY_NORMAL
            | self::VERBOSITY_VERBOSE
            | self::VERBOSITY_VERY_VERBOSE
            | self::VERBOSITY_DEBUG;

        $verbosity = $verbosities & $options ?: self::VERBOSITY_NORMAL;

        return $verbosity <= $this->output->getVerbosity();
    }

    /**
     * Determine whether verbosity is quiet (-q).
     */
    public function isQuiet(): bool
    {
        return $this->output->isQuiet();
    }

    /**
     * Determine whether verbosity is verbose (-v).
     */
    public function isVerbose(): bool
    {
        return $this->output->isVerbose();
    }

    /**
     * Determine whether verbosity is very verbose (-vv).
     */
    public function isVeryVerbose(): bool
    {
        return $this->output->isVeryVerbose();
    }

    /**
     * Determine whether verbosity is debug (-vvv).
     */
    public function isDebug(): bool
    {
        return $this->output->isDebug();
    }

    /**
     * Get the underlying Symfony output implementation.
     */
    public function getOutput(): OutputInterface
    {
        return $this->output;
    }
}
