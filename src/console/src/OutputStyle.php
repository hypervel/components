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
     * If the last output written wrote a new line.
     *
     * @deprecated use $newLinesWritten
     */
    protected bool $newLineWritten = false;

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
        $this->newLinesWritten = $this->trailingNewLineCount($messages) + (int) $newline;
        $this->newLineWritten = $this->newLinesWritten > 0;

        parent::write($messages, $newline, $options);
    }

    #[Override]
    public function writeln(string|iterable $messages, int $type = self::OUTPUT_NORMAL): void
    {
        if ($this->output->getVerbosity() >= $type) {
            $this->newLinesWritten = $this->trailingNewLineCount($messages) + 1;
            $this->newLineWritten = true;
        }

        parent::writeln($messages, $type);
    }

    #[Override]
    public function newLine(int $count = 1): void
    {
        $this->newLinesWritten += $count;
        $this->newLineWritten = $this->newLinesWritten > 0;

        parent::newLine($count);
    }

    public function newLinesWritten(): int
    {
        if ($this->output instanceof static) {
            return $this->output->newLinesWritten();
        }

        return $this->newLinesWritten;
    }

    /**
     * @deprecated use newLinesWritten
     */
    public function newLineWritten(): bool
    {
        if ($this->output instanceof static && $this->output->newLineWritten()) {
            return true;
        }

        return $this->newLineWritten;
    }

    /**
     * Count the number of trailing new lines in a string.
     *
     * @param iterable<string>|string $messages
     */
    protected function trailingNewLineCount(string|iterable $messages): int
    {
        if (is_iterable($messages)) {
            $string = '';

            foreach ($messages as $message) {
                $string .= $message . PHP_EOL;
            }
        } else {
            $string = $messages;
        }

        return strlen($string) - strlen(rtrim($string, PHP_EOL));
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
