<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Themes\Default\Concerns;

use Hypervel\Prompts\Support\Utils;
use Hypervel\Prompts\Themes\Default\Renderer;

trait InteractsWithStrings
{
    use InteractsWithAnsi;

    /**
     * Get the length of the longest line.
     *
     * @param array<string> $lines
     */
    protected function longest(array $lines, int $padding = 0): int
    {
        return max(
            $this->minWidth,
            count($lines) > 0 ? max(array_map(fn ($line) => mb_strwidth($this->stripEscapeSequences($line)) + $padding, $lines)) : null
        );
    }

    /**
     * Pad text ignoring ANSI escape sequences.
     */
    protected function pad(string $text, int $length, string $char = ' '): string
    {
        $rightPadding = str_repeat($char, max(0, $length - mb_strwidth($this->stripEscapeSequences($text))));

        return "{$text}{$rightPadding}";
    }

    /**
     * Replace the last visible grapheme while preserving trailing formatting.
     */
    protected function replaceLastVisibleGrapheme(string $line, string $replacement): string
    {
        if ($line === '' || ! mb_check_encoding($line, 'UTF-8')) {
            return $line;
        }

        if (mb_strwidth($this->stripEscapeSequences($line)) === 0) {
            return $line;
        }

        // Scrollbar input is raw application text, not output from the ANSI parser.
        $line = Utils::sanitizeTerminalFormatting($line);
        $pattern = '/(?<grapheme>\X)(?<suffix>(?:' . Utils::TERMINAL_FORMATTING_PATTERN . '|<\/(?:info|comment|question|error)>|<\/>)*)$/u';

        if (preg_match($pattern, $line, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $line;
        }

        [$grapheme, $offset] = $matches['grapheme'];
        $suffix = $matches['suffix'][0];
        $padding = str_repeat(' ', max(
            0,
            mb_strwidth($grapheme) - mb_strwidth($this->stripEscapeSequences($replacement)),
        ));

        return substr($line, 0, $offset) . $padding . $replacement . $suffix;
    }

    /**
     * Strip ANSI escape sequences from the given text.
     */
    protected function stripEscapeSequences(string $text): string
    {
        // Renderer concerns re-import this trait, which would shadow a base-class override.
        // Other consumers retain formatter-free measurement and the same protected helpers.
        return $this instanceof Renderer
            ? $this->visibleText($text)
            : Utils::stripEscapeSequences($text);
    }
}
