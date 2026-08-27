<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Themes\Default\Concerns;

use Hypervel\Prompts\Support\Utils;

trait InteractsWithStrings
{
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
        // Measurement and undecorated rendering must interpret escapes identically.
        return Utils::stripEscapeSequences($text);
    }

    /**
     * Multi-byte version of wordwrap.
     *
     * @param non-empty-string $break
     */
    protected function mbWordwrap(
        string $string,
        int $width = 75,
        string $break = "\n",
        bool $cut_long_words = false
    ): string {
        $width = max(1, $width);
        $lines = explode($break, $string);
        $result = [];

        foreach ($lines as $originalLine) {
            if (mb_strwidth($originalLine) <= $width
                && strlen($originalLine) <= Utils::MAX_UNBREAKABLE_BYTES) {
                $result[] = $originalLine;

                continue;
            }

            $words = explode(' ', $originalLine);
            $line = null;
            $lineWidth = 0;

            if ($cut_long_words) {
                foreach ($words as $index => $word) {
                    if (mb_strwidth($word) <= $width
                        && strlen($word) <= Utils::MAX_UNBREAKABLE_BYTES) {
                        continue;
                    }

                    $strings = [];
                    $str = '';

                    foreach (Utils::graphemes($word) as $character) {
                        $tmp = $str . $character;

                        if ($str !== '' && (mb_strwidth($tmp) > $width
                            || strlen($tmp) > Utils::MAX_UNBREAKABLE_BYTES)) {
                            $strings[] = $str;

                            $str = $character;
                        } else {
                            $str = $tmp;
                        }
                    }

                    if ($str !== '') {
                        $strings[] = $str;
                    }

                    $words[$index] = implode(' ', $strings);
                }

                $words = explode(' ', implode(' ', $words));
            }

            foreach ($words as $word) {
                $tmp = ($line === null) ? $word : $line . ' ' . $word;
                $wordWidth = mb_strwidth($word);

                $lineWidth += $wordWidth;

                if ($line !== null) {
                    // Space between words
                    ++$lineWidth;
                }

                if ($line === null || $lineWidth <= $width) {
                    $line = $tmp;
                } else {
                    if ($line !== '') {
                        $result[] = $line;
                    }

                    $line = $word;
                    $lineWidth = $wordWidth;
                }
            }

            if ($line !== '') {
                $result[] = $line;
            }

            $line = null;
        }

        return implode($break, $result);
    }

    /**
     * Word wrap text while preserving ANSI escape sequences.
     *
     * @return array<int, string>
     */
    protected function ansiWordwrap(string $text, int $width, bool $cutLongWords = false): array
    {
        // Parse segments and build character array with codes
        $segments = $this->parseAnsiText($text);
        $plainText = $this->stripEscapeSequences($text);
        $chars = [];

        foreach ($segments as $segment) {
            $segmentChars = mb_str_split($segment['text']);

            foreach ($segmentChars as $char) {
                $chars[] = ['char' => $char, 'codes' => $segment['codes'], 'link' => $segment['link']];
            }
        }

        // Word wrap the plain text
        $wrappedLines = $this->mbWordwrap($plainText, $width, "\n", $cutLongWords);
        $plainLines = explode("\n", $wrappedLines);

        // Rebuild each wrapped line with ANSI codes
        $result = [];
        $charIndex = 0;

        foreach ($plainLines as $plainLine) {
            $line = '';
            $lastCodes = '';
            $lastLink = '';
            $lineChars = mb_str_split($plainLine);

            foreach ($lineChars as $lineChar) {
                // Find the matching source character after wrapping removes separators.
                while ($charIndex < count($chars) && $chars[$charIndex]['char'] !== $lineChar) {
                    // mbWordwrap never emits a source newline inside a returned line.
                    if ($chars[$charIndex]['char'] === ' ' || $chars[$charIndex]['char'] === "\n") {
                        ++$charIndex;
                    } else {
                        break;
                    }
                }

                if ($charIndex < count($chars)) {
                    $link = $chars[$charIndex]['link'];
                    $codes = $chars[$charIndex]['codes'];

                    if ($link !== $lastLink) {
                        if ($lastLink !== '') {
                            $line .= "\e]8;;\e\\";
                        }

                        if ($link !== '') {
                            $line .= $link;
                        }

                        $lastLink = $link;
                    }

                    if ($codes !== $lastCodes) {
                        if ($lastCodes !== '') {
                            $line .= "\e[0m";
                        }

                        if ($codes !== '') {
                            $line .= $codes;
                        }

                        $lastCodes = $codes;
                    }

                    $line .= $lineChar;
                    ++$charIndex;
                } else {
                    $line .= $lineChar;
                }
            }

            // Close any open ANSI codes
            if ($lastCodes !== '' && ! str_ends_with($line, "\e[0m")) {
                $line .= "\e[0m";
            }

            if ($lastLink !== '') {
                $line .= "\e]8;;\e\\";
            }

            $result[] = $line;
        }

        return $result;
    }

    /**
     * Parse text into segments with their associated ANSI codes.
     *
     * @return array<int, array{text: string, codes: string, link: string}>
     */
    protected function parseAnsiText(string $text): array
    {
        $text = Utils::sanitizeTerminalFormatting($text);
        $segments = [];
        $activeSgr = [];
        $currentLink = '';
        $currentText = '';
        $i = 0;
        $textLength = strlen($text);

        while ($i < $textLength) {
            if ($text[$i] === "\e" && ($i + 1 < $textLength)) {
                if ($text[$i + 1] === '[') {
                    // Save current segment if it has text
                    if ($currentText !== '') {
                        $segments[] = ['text' => $currentText, 'codes' => implode('', $activeSgr), 'link' => $currentLink];
                        $currentText = '';
                    }

                    $escapeSequence = "\e[";
                    $i += 2;

                    while ($i < $textLength && ord($text[$i]) >= 0x30 && ord($text[$i]) <= 0x3F) {
                        $escapeSequence .= $text[$i];
                        ++$i;
                    }

                    while ($i < $textLength && ord($text[$i]) >= 0x20 && ord($text[$i]) <= 0x2F) {
                        $escapeSequence .= $text[$i];
                        ++$i;
                    }

                    if ($i < $textLength && ord($text[$i]) >= 0x40 && ord($text[$i]) <= 0x7E) {
                        $final = $text[$i];
                        $escapeSequence .= $final;
                        ++$i;

                        if ($final === 'm') {
                            $this->updateSgrState($activeSgr, $escapeSequence);
                        }

                        continue;
                    }

                    $currentText .= $escapeSequence;

                    continue;
                }

                if ($text[$i + 1] === ']') {
                    // Save current segment if it has text
                    if ($currentText !== '') {
                        $segments[] = ['text' => $currentText, 'codes' => implode('', $activeSgr), 'link' => $currentLink];
                        $currentText = '';
                    }

                    $escapeSequence = "\e]";
                    $i += 2;
                    $terminated = false;

                    while ($i < $textLength) {
                        if ($text[$i] === "\x07") {
                            $escapeSequence .= "\x07";
                            ++$i;
                            $terminated = true;
                            break;
                        }

                        if ($text[$i] === "\e" && ($i + 1 < $textLength) && $text[$i + 1] === '\\') {
                            $escapeSequence .= "\e\\";
                            $i += 2;
                            $terminated = true;
                            break;
                        }

                        $escapeSequence .= $text[$i];
                        ++$i;
                    }

                    if (! $terminated) {
                        $currentText .= $escapeSequence;

                        continue;
                    }

                    $link = Utils::resolveOsc8Link($escapeSequence);

                    if ($link !== null) {
                        $currentLink = $link;
                    }

                    continue;
                }
            }

            $currentText .= $text[$i];
            ++$i;
        }

        // Add final segment
        if ($currentText !== '') {
            $segments[] = ['text' => $currentText, 'codes' => implode('', $activeSgr), 'link' => $currentLink];
        }

        return $segments;
    }

    /**
     * Update the bounded set of active SGR attributes.
     *
     * @param array<string, string> $activeSgr
     */
    protected function updateSgrState(array &$activeSgr, string $escape): void
    {
        $parameters = substr($escape, 2, -1);
        $codes = $parameters === '' ? ['0'] : explode(';', $parameters);

        for ($index = 0; $index < count($codes); ++$index) {
            $rawCode = $codes[$index] === '' ? '0' : $codes[$index];
            $colon = str_contains($rawCode, ':');
            $code = (int) ($colon ? strstr($rawCode, ':', before_needle: true) : $rawCode);

            if ($code === 0) {
                $activeSgr = [];

                continue;
            }

            if (! $colon && ($code === 38 || $code === 48 || $code === 58)) {
                $count = ($codes[$index + 1] ?? null) === '2' ? 5 : 3;
                $value = implode(';', array_slice($codes, $index, $count));
                $attribute = match ($code) {
                    38 => 'foreground',
                    48 => 'background',
                    58 => 'underlineColor',
                };
                $activeSgr[$attribute] = "\e[{$value}m";
                $index += $count - 1;

                continue;
            }

            $attribute = match (true) {
                $code === 1 || $code === 2 || $code === 22 => 'intensity',
                $code === 3 || $code === 23 => 'italic',
                $code === 4 || $code === 21 || $code === 24 => 'underline',
                $code === 5 || $code === 6 || $code === 25 => 'blink',
                $code === 7 || $code === 27 => 'reverse',
                $code === 8 || $code === 28 => 'conceal',
                $code === 9 || $code === 29 => 'strike',
                $code >= 10 && $code <= 19 => 'font',
                ($code >= 30 && $code <= 38) || ($code >= 90 && $code <= 97) || $code === 39 => 'foreground',
                ($code >= 40 && $code <= 48) || ($code >= 100 && $code <= 107) || $code === 49 => 'background',
                $code === 51 || $code === 52 || $code === 54 => 'frame',
                $code === 53 || $code === 55 => 'overline',
                $code === 58 || $code === 59 => 'underlineColor',
                default => 'other',
            };

            if (in_array($code, [22, 23, 24, 25, 27, 28, 29, 39, 49, 54, 55, 59], true)) {
                unset($activeSgr[$attribute]);
            } else {
                $value = $colon ? $rawCode : (string) $code;
                $activeSgr[$attribute] = "\e[{$value}m";
            }
        }
    }
}
