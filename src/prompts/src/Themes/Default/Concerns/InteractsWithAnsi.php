<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Themes\Default\Concerns;

use Hypervel\Prompts\Support\Utils;

trait InteractsWithAnsi
{
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
        $plainText = implode('', array_column($segments, 'text'));
        $chars = $this->ansiCharacters($segments);

        // Word wrap the plain text
        $wrappedLines = $this->mbWordwrap($plainText, $width, "\n", $cutLongWords);
        $plainLines = explode("\n", $wrappedLines);

        // Rebuild each wrapped line with ANSI codes
        $result = [];
        $charIndex = 0;

        foreach ($plainLines as $plainLine) {
            $result[] = $this->rebuildAnsiLine($chars, $plainLine, $charIndex);
        }

        return $result;
    }

    /**
     * Truncate visible ANSI text without cutting a control sequence.
     */
    protected function truncateAnsi(string $text, int $width): string
    {
        $segments = $this->parseAnsiText($text);
        $plainText = implode('', array_column($segments, 'text'));

        if (mb_strwidth($plainText) <= $width) {
            return Utils::sanitizeTerminalFormatting($text);
        }

        $characterIndex = 0;

        return $this->rebuildAnsiLine(
            $this->ansiCharacters($segments),
            mb_strimwidth($plainText, 0, $width - 1),
            $characterIndex,
        ) . '…';
    }

    /**
     * Associate visible characters with their terminal formatting.
     *
     * @param array<int, array{text: string, codes: string, link: string}> $segments
     * @return list<array{char: string, codes: string, link: string}>
     */
    private function ansiCharacters(array $segments): array
    {
        $characters = [];

        foreach ($segments as $segment) {
            foreach (mb_str_split($segment['text']) as $character) {
                $characters[] = ['char' => $character, 'codes' => $segment['codes'], 'link' => $segment['link']];
            }
        }

        return $characters;
    }

    /**
     * Rebuild a visible line and close its terminal styles and hyperlink.
     *
     * @param list<array{char: string, codes: string, link: string}> $chars
     */
    private function rebuildAnsiLine(array $chars, string $plainLine, int &$charIndex): string
    {
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

        return $line;
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
