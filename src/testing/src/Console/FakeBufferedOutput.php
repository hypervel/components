<?php

declare(strict_types=1);

namespace Hypervel\Testing\Console;

use Hypervel\Prompts\Support\Utils;
use Override;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @internal
 */
class FakeBufferedOutput extends BufferedOutput
{
    /**
     * The messages written to the output.
     *
     * @var array<int, string>
     */
    protected array $written = [];

    /**
     * The position and line of the latest expected output that was written.
     *
     * @var null|array{0: int, 1: string}
     */
    protected ?array $latest = null;

    /**
     * The first expected output written out of order, as [written first, expected first] lines.
     *
     * @var null|array{0: string, 1: string}
     */
    protected ?array $outOfOrder = null;

    /**
     * Create a new fake buffered output instance.
     *
     * @param array<int, string> $expected
     * @param array<int, string> $expectedSubstrings
     * @param array<int, string> $unexpected
     * @param array<int, string> $unexpectedSubstrings
     * @param array<int, string> $expectedPromptFrames
     */
    public function __construct(
        protected array $expected = [],
        protected array $expectedSubstrings = [],
        protected array $unexpected = [],
        protected array $unexpectedSubstrings = [],
        protected ?bool $expectsOutput = null,
        protected array $expectedPromptFrames = [],
    ) {
        parent::__construct();

        $this->expected = array_values(array_map(strval(...), $expected));
        $this->expectedSubstrings = array_values(array_map(strval(...), $expectedSubstrings));
        $this->unexpected = array_values(array_map(strval(...), $unexpected));
        $this->unexpectedSubstrings = array_values(array_map(strval(...), $unexpectedSubstrings));
    }

    /**
     * Record the given message against the expected output.
     */
    #[Override]
    protected function doWrite(string $message, bool $newline): void
    {
        $this->written[] = $message;

        if ($this->expectedPromptFrames !== []) {
            $this->matchPromptFrame($message);
        }

        if (($i = array_search($message, $this->expected, true)) !== false) {
            unset($this->expected[$i]);

            if ($i < ($this->latest[0] ?? -1)) {
                $this->outOfOrder ??= [$this->latest[1], $message];
            } else {
                $this->latest = [$i, $message];
            }
        }

        $this->expectedSubstrings = array_filter(
            $this->expectedSubstrings,
            fn (string $text): bool => ! str_contains($message, $text)
        );
    }

    /**
     * Match the styled output of a prompt before plain output loses its styling.
     */
    public function matchPromptFrame(string $frame): void
    {
        foreach ($this->expectedPromptFrames as $index => $expected) {
            if (str_contains($frame, $expected)) {
                unset($this->expectedPromptFrames[$index]);
            }
        }
    }

    /**
     * Get the message for the first unmet output expectation.
     */
    public function failure(): ?string
    {
        if ($this->expected !== []) {
            return 'Output "' . reset($this->expected) . '" was not printed.';
        }

        if ($this->outOfOrder) {
            return 'Output "' . $this->outOfOrder[0] . '" was printed before "' . $this->outOfOrder[1] . '".';
        }

        if ($this->expectedSubstrings !== []) {
            return 'Output does not contain "' . reset($this->expectedSubstrings) . '".';
        }

        if ($this->expectedPromptFrames !== []) {
            return 'Output does not contain "' . trim(Utils::stripTerminalControls(reset($this->expectedPromptFrames))) . '".';
        }

        if ($this->expectsOutput === true && $this->written === []) {
            return 'No output was printed.';
        }

        if ($this->expectsOutput === false && $this->written !== []) {
            return 'Output "' . $this->written[0] . '" was printed.';
        }

        foreach ($this->unexpected as $line) {
            if (in_array($line, $this->written, true)) {
                return 'Output "' . $line . '" was printed.';
            }
        }

        foreach ($this->unexpectedSubstrings as $text) {
            foreach ($this->written as $message) {
                if (str_contains($message, $text)) {
                    return 'Output "' . $text . '" was printed.';
                }
            }
        }

        return null;
    }
}
