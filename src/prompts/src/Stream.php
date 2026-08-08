<?php

declare(strict_types=1);

namespace Hypervel\Prompts;

use Closure;
use Hypervel\Prompts\Themes\Default\Concerns\InteractsWithStrings;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class Stream extends Prompt
{
    use InteractsWithStrings;

    protected int $minWidth = 0;

    protected string $message = '';

    /** @var array<int, string> */
    protected array $currentlyFading = [];

    protected int $maxWidth = 0;

    /** @var array<int, Closure(string): string> */
    protected array $fadingOutColors = [];

    /**
     * Whether chunks should be written directly without terminal animation.
     */
    protected bool $direct = false;

    /**
     * Create a new Stream instance.
     */
    public function __construct()
    {
        $this->direct = ! static::output()->isDecorated();

        if ($this->direct) {
            return;
        }

        $this->maxWidth = static::terminal()->cols() - 20;
        $this->fadingOutColors = $this->fadeOut();
        $this->hideCursor();
    }

    /**
     * Append a message to the stream.
     */
    public function append(string $message): self
    {
        if ($this->direct) {
            $this->message .= $message;
            static::output()->write($message, false, OutputInterface::OUTPUT_RAW);

            return $this;
        }

        $this->currentlyFading[] = $message;

        while (count($this->currentlyFading) > count($this->fadingOutColors)) {
            $this->message .= array_shift($this->currentlyFading);
        }

        $this->render();

        return $this;
    }

    /**
     * Close the stream and finish rendering its output.
     */
    public function close(): void
    {
        if ($this->direct) {
            return;
        }

        $failure = null;

        try {
            while (count($this->currentlyFading) > 0) {
                $this->message .= array_shift($this->currentlyFading);
                $this->render();
                usleep(25_000);
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        try {
            $this->restoreTerminalState();
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Get the rendered stream lines.
     *
     * @return array<int, string>
     */
    public function lines(): array
    {
        if ($this->direct) {
            return explode(PHP_EOL, $this->message);
        }

        $toFadeIn = [];

        foreach ($this->currentlyFading as $index => $message) {
            $toFadeIn[] = $this->fadingOutColors[$index]($message);
        }

        $lines = explode(PHP_EOL, $this->message . implode('', $toFadeIn));
        $finalLines = [];

        foreach ($lines as $line) {
            $finalLines = array_merge(
                $finalLines,
                $this->ansiWordwrap($line, $this->maxWidth),
            );
        }

        return $finalLines;
    }

    /**
     * Disable prompting for input.
     *
     * @throws RuntimeException
     */
    public function prompt(): mixed
    {
        throw new RuntimeException('Stream cannot be prompted');
    }

    /**
     * Get the value of the prompt.
     */
    public function value(): string
    {
        return $this->message . implode('', $this->currentlyFading);
    }

    /**
     * Get an array of closures that progressively fade text from full color to nearly invisible.
     *
     * @return array<int, Closure(string): string>
     */
    protected function fadeOut(int $steps = 10): array
    {
        // Stored closures must remain static so an abandoned stream can reach destructor cleanup.
        if (! static::terminal()->supportsTrueColor()) {
            return [
                static fn (string $text) => $text,
                static fn (string $text) => "\e[2m{$text}\e[22m",
            ];
        }

        $fg = static::terminal()->foregroundColor();
        $bg = static::terminal()->backgroundColor();

        return array_map(
            static function (int $step) use ($fg, $bg, $steps) {
                $factor = 1 - ($step / $steps);
                $r = (int) ($bg[0] + ($fg[0] - $bg[0]) * $factor);
                $g = (int) ($bg[1] + ($fg[1] - $bg[1]) * $factor);
                $b = (int) ($bg[2] + ($fg[2] - $bg[2]) * $factor);

                return static fn (string $text) => "\e[38;2;{$r};{$g};{$b}m{$text}\e[0m";
            },
            range(0, $steps - 1),
        );
    }
}
