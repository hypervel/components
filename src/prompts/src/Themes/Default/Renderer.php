<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Themes\Default;

use Hypervel\Prompts\Concerns\Colors;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\Support\Utils;
use InvalidArgumentException;
use Stringable;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;

abstract class Renderer implements Stringable
{
    use Colors;
    use Concerns\InteractsWithAnsi;

    /**
     * The output to be rendered.
     */
    protected string $output = '';

    private ?OutputFormatterInterface $renderFormatter = null;

    /**
     * Create a new renderer instance.
     */
    public function __construct(protected Prompt $prompt, private ?OutputFormatterInterface $formatter = null)
    {
    }

    /**
     * Get the renderer-owned formatter without changing application output settings.
     */
    protected function formatter(): OutputFormatterInterface
    {
        $formatter = $this->renderFormatter ??= $this->formatter === null ? new OutputFormatter : clone $this->formatter;

        // Separate labels and cells must not inherit an unfinished tag from another value.
        if ($formatter instanceof OutputFormatter) {
            $formatter->getStyleStack()->reset();
        }

        return $formatter;
    }

    /**
     * Format an independent value using the configured console styles.
     */
    protected function formatText(string $text, bool $decorated = true): string
    {
        if (! str_contains($text, '<') && ! str_contains($text, '\\') && ! str_contains($text, "\0")) {
            return $text;
        }

        $formatter = $this->formatter();
        $formatter->setDecorated($decorated);

        return $formatter->format($text);
    }

    /**
     * Get the visible text used to measure a formatted value.
     */
    protected function visibleText(string $text): string
    {
        return Utils::stripTerminalControls($this->formatText($text, decorated: false));
    }

    /**
     * Truncate a formatted value without cutting its markup or terminal controls.
     */
    protected function truncate(string $string, int $width): string
    {
        if ($width <= 0) {
            throw new InvalidArgumentException("Width [{$width}] must be greater than zero.");
        }

        if (! str_contains($string, '<') && ! str_contains($string, "\e")) {
            return mb_strwidth($string) <= $width ? $string : (mb_strimwidth($string, 0, $width - 1) . '…');
        }

        return OutputFormatter::escape($this->truncateAnsi($this->formatText($string), $width));
    }

    /**
     * Render a line of output.
     */
    protected function line(string $message): self
    {
        $this->output .= $message . PHP_EOL;

        return $this;
    }

    /**
     * Render a new line.
     */
    protected function newLine(int $count = 1): self
    {
        $this->output .= str_repeat(PHP_EOL, $count);

        return $this;
    }

    /**
     * Render a warning message.
     */
    protected function warning(string $message): self
    {
        return $this->line($this->yellow("  ⚠ {$message}"));
    }

    /**
     * Render an error message.
     */
    protected function error(string $message): self
    {
        return $this->line($this->red("  ⚠ {$message}"));
    }

    /**
     * Render an hint message.
     */
    protected function hint(string $message): self
    {
        if ($message === '') {
            return $this;
        }

        $message = $this->truncate($message, $this->prompt->terminal()->cols() - 6);

        return $this->line($this->gray("  {$message}"));
    }

    /**
     * Apply the callback if the given "value" is truthy.
     *
     * @return $this
     */
    protected function when(mixed $value, callable $callback, ?callable $default = null): self
    {
        if ($value) {
            $callback($this);
        } elseif ($default) {
            $default($this);
        }

        return $this;
    }

    /**
     * Render the output with a blank line above and below.
     */
    public function __toString(): string
    {
        return str_repeat(PHP_EOL, max(2 - $this->prompt->newLinesWritten(), 0))
            . $this->output
            . (in_array($this->prompt->state, ['submit', 'cancel'], true) ? PHP_EOL : '');
    }
}
