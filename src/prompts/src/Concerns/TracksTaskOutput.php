<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Concerns;

use Hypervel\Prompts\Support\Utils;
use Hypervel\Prompts\Themes\Default\Concerns\InteractsWithStrings;

/**
 * Track bounded incremental output owned by a Task operation.
 */
trait TracksTaskOutput
{
    use InteractsWithStrings;

    private const string CONTROL_MODE_ESCAPE = 'escape';

    private const string CONTROL_MODE_CSI_PARAMETERS = 'csi-parameters';

    private const string CONTROL_MODE_CSI_INTERMEDIATES = 'csi-intermediates';

    private const string CONTROL_MODE_OSC = 'osc';

    private const string CONTROL_MODE_STRING = 'string';

    /**
     * The log index where the current partial started, or null if not streaming.
     */
    protected ?int $partialStartIndex = null;

    /**
     * Complete visible lines produced by the current partial.
     *
     * @var list<string>
     */
    private array $partialLines = [];

    /**
     * Visible tokens on the current line.
     *
     * @var list<array{text: string, width: int, codes: string, link: string}>
     */
    private array $partialLineTokens = [];

    private int $partialLineWidth = 0;

    private bool $partialLineUnset = true;

    /**
     * Tokens in the unfinished word.
     *
     * @var list<array{text: string, width: int, codes: string, link: string}>
     */
    private array $partialWordTokens = [];

    private int $partialWordWidth = 0;

    private int $partialWordBytes = 0;

    private string $partialTrailingGrapheme = '';

    /**
     * The real separator awaiting the next word, or null for a long-word cut.
     *
     * @var null|array{text: string, width: int, codes: string, link: string}
     */
    private ?array $partialJoinToken = null;

    private string $partialInputBuffer = '';

    private ?string $partialControlMode = null;

    private string $partialControlBuffer = '';

    private int $partialControlBytes = 0;

    private bool $partialControlEscapePending = false;

    /**
     * Canonical active SGR sequences keyed by terminal attribute.
     *
     * @var array<string, string>
     */
    private array $partialSgr = [];

    private string $partialLink = '';

    private bool $partialHasUnfinishedLine = false;

    /**
     * Add a complete logical log message.
     */
    protected function addLogLines(string $line): void
    {
        $this->resetTaskOutputTracking();
        array_push($this->logs, ...$this->ansiWordwrap(
            str_replace("\r\n", "\n", $line),
            $this->taskOutputWidth(),
            cutLongWords: true,
        ));

        $this->trimTaskLogs();
    }

    /**
     * Replace the in-progress partial with a complete supplied prefix.
     */
    protected function replacePartialLines(string $text): void
    {
        if ($this->partialStartIndex !== null) {
            $this->logs = array_slice($this->logs, 0, $this->partialStartIndex);
        }

        $this->resetTaskOutputTracking();
        $this->consumePartialOutput($text);
    }

    /**
     * Consume one new partial-output delta.
     */
    private function consumePartialOutput(string $chunk): void
    {
        if ($this->partialStartIndex === null) {
            $this->partialStartIndex = count($this->logs);
            $this->partialHasUnfinishedLine = true;
        }

        $this->partialInputBuffer .= $chunk;
        $this->drainPartialInput();
        $this->synchronizePartialLogs();
    }

    /**
     * Commit the current partial output as permanent log lines.
     */
    private function commitPartialOutput(): void
    {
        if ($this->partialStartIndex === null) {
            return;
        }

        $this->drainPartialInput(final: true);

        if ($this->partialHasUnfinishedLine) {
            $this->finishPartialLogicalLine();
        }

        $this->synchronizePartialLogs(includePreview: false);
        $this->resetTaskOutputTracking();
    }

    /**
     * Reset all Task output tracking for a new operation.
     */
    private function resetTaskOutputTracking(): void
    {
        $this->partialStartIndex = null;
        $this->partialLines = [];
        $this->partialLineTokens = [];
        $this->partialLineWidth = 0;
        $this->partialLineUnset = true;
        $this->partialWordTokens = [];
        $this->partialWordWidth = 0;
        $this->partialWordBytes = 0;
        $this->partialTrailingGrapheme = '';
        $this->partialJoinToken = null;
        $this->partialInputBuffer = '';
        $this->resetPartialControl();
        $this->partialSgr = [];
        $this->partialLink = '';
        $this->partialHasUnfinishedLine = false;
    }

    /**
     * Parse every complete token currently buffered.
     */
    private function drainPartialInput(bool $final = false): void
    {
        $buffer = $this->partialInputBuffer;
        $bufferLength = strlen($buffer);
        $cursor = 0;

        while ($cursor < $bufferLength) {
            if ($this->partialControlMode !== null) {
                if ($this->consumePartialControlByte($buffer[$cursor])) {
                    ++$cursor;
                }

                continue;
            }

            if ($buffer[$cursor] === "\e") {
                $this->startPartialControl();
                ++$cursor;

                continue;
            }

            $escapePosition = strpos($buffer, "\e", $cursor);
            $rawTextEnd = $escapePosition === false ? $bufferLength : $escapePosition;
            $textEnd = $rawTextEnd;

            // A trailing carriage return may be the first half of a split CRLF.
            if (! $final && $textEnd === $bufferLength && $buffer[$textEnd - 1] === "\r") {
                --$textEnd;
            }

            $text = substr($buffer, $cursor, $textEnd - $cursor);
            $validLength = $this->validPartialTextLength($text, $final);

            if ($validLength === 0) {
                break;
            }

            $this->consumePartialText(substr($text, 0, $validLength));
            $cursor += $validLength;

            if ($cursor < $textEnd || $textEnd < $rawTextEnd) {
                break;
            }
        }

        $this->partialInputBuffer = substr($buffer, $cursor);

        if ($final) {
            $this->resetPartialControl();
        }
    }

    /**
     * Start one terminal control at an ESC byte.
     */
    private function startPartialControl(): void
    {
        $this->partialControlMode = self::CONTROL_MODE_ESCAPE;
        $this->partialControlBuffer = "\e";
        $this->partialControlBytes = 1;
        $this->partialControlEscapePending = false;
    }

    /**
     * Consume one byte of the active terminal control.
     *
     * Return false when the byte must be reprocessed after a local abort.
     */
    private function consumePartialControlByte(string $byte): bool
    {
        $ordinal = ord($byte);

        if ($this->partialControlMode === self::CONTROL_MODE_ESCAPE) {
            if ($this->partialControlBuffer === "\e") {
                if ($byte === '[') {
                    $this->appendPartialControlByte($byte);
                    $this->partialControlMode = self::CONTROL_MODE_CSI_PARAMETERS;

                    return true;
                }

                if ($byte === ']') {
                    $this->appendPartialControlByte($byte);
                    $this->partialControlMode = self::CONTROL_MODE_OSC;

                    return true;
                }

                if (in_array($byte, ['P', 'X', '^', '_'], true)) {
                    $this->appendPartialControlByte($byte);
                    $this->partialControlMode = self::CONTROL_MODE_STRING;

                    return true;
                }
            }

            if ($ordinal >= 0x20 && $ordinal <= 0x2F) {
                $this->appendPartialControlByte($byte);

                return true;
            }

            if ($ordinal >= 0x30 && $ordinal <= 0x7E) {
                $this->appendPartialControlByte($byte);
                $this->completePartialControl();

                return true;
            }

            $this->resetPartialControl();

            return false;
        }

        if ($this->partialControlMode === self::CONTROL_MODE_CSI_PARAMETERS) {
            if ($ordinal >= 0x30 && $ordinal <= 0x3F) {
                $this->appendPartialControlByte($byte);

                return true;
            }

            if ($ordinal >= 0x20 && $ordinal <= 0x2F) {
                $this->appendPartialControlByte($byte);
                $this->partialControlMode = self::CONTROL_MODE_CSI_INTERMEDIATES;

                return true;
            }

            if ($ordinal >= 0x40 && $ordinal <= 0x7E) {
                $this->appendPartialControlByte($byte);
                $this->completePartialControl();

                return true;
            }

            $this->resetPartialControl();

            return false;
        }

        if ($this->partialControlMode === self::CONTROL_MODE_CSI_INTERMEDIATES) {
            if ($ordinal >= 0x20 && $ordinal <= 0x2F) {
                $this->appendPartialControlByte($byte);

                return true;
            }

            if ($ordinal >= 0x40 && $ordinal <= 0x7E) {
                $this->appendPartialControlByte($byte);
                $this->completePartialControl();

                return true;
            }

            $this->resetPartialControl();

            return false;
        }

        if ($this->partialControlEscapePending) {
            if ($byte === '\\') {
                $this->appendPartialControlByte($byte);
                $this->completePartialControl();

                return true;
            }

            // The pending ESC starts a new control when it is not an ST terminator.
            $this->resetPartialControl();
            $this->startPartialControl();

            return false;
        }

        if ($byte === "\x07") {
            $this->appendPartialControlByte($byte);
            $this->completePartialControl();

            return true;
        }

        $this->appendPartialControlByte($byte);

        if ($byte === "\e") {
            $this->partialControlEscapePending = true;
        }

        return true;
    }

    /**
     * Append a byte while retaining no more than the fixed control ceiling.
     */
    private function appendPartialControlByte(string $byte): void
    {
        ++$this->partialControlBytes;

        if ($this->partialControlBuffer === '') {
            return;
        }

        if ($this->partialControlBytes > Utils::MAX_UNBREAKABLE_BYTES) {
            $this->partialControlBuffer = '';

            return;
        }

        $this->partialControlBuffer .= $byte;
    }

    /**
     * Apply and clear one complete terminal control.
     */
    private function completePartialControl(): void
    {
        $sequence = $this->partialControlBuffer;
        $this->resetPartialControl();

        if ($sequence !== '') {
            $this->applyPartialEscape($sequence);
        }
    }

    /**
     * Reset the active terminal-control parser.
     */
    private function resetPartialControl(): void
    {
        $this->partialControlMode = null;
        $this->partialControlBuffer = '';
        $this->partialControlBytes = 0;
        $this->partialControlEscapePending = false;
    }

    /**
     * Return the complete UTF-8 prefix length available for parsing.
     */
    private function validPartialTextLength(string $text, bool $final): int
    {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return strlen($text);
        }

        if (! $final) {
            $length = strlen($text);

            for ($suffixLength = min(3, $length); $suffixLength >= 1; --$suffixLength) {
                $leadPosition = $length - $suffixLength;
                $requiredLength = match (true) {
                    ord($text[$leadPosition]) >= 0xC2 && ord($text[$leadPosition]) <= 0xDF => 2,
                    ord($text[$leadPosition]) >= 0xE0 && ord($text[$leadPosition]) <= 0xEF => 3,
                    ord($text[$leadPosition]) >= 0xF0 && ord($text[$leadPosition]) <= 0xF4 => 4,
                    default => null,
                };

                if ($requiredLength === null || $suffixLength >= $requiredLength) {
                    continue;
                }

                for ($position = $leadPosition + 1; $position < $length; ++$position) {
                    if (ord($text[$position]) < 0x80 || ord($text[$position]) > 0xBF) {
                        continue 2;
                    }
                }

                return $leadPosition;
            }
        }

        return strlen($text);
    }

    /**
     * Consume visible text using the current terminal attributes.
     */
    private function consumePartialText(string $text): void
    {
        preg_match_all('/\r\n|\n| /', $text, $matches, PREG_OFFSET_CAPTURE);
        $offset = 0;

        foreach ($matches[0] as [$separator, $separatorOffset]) {
            if ($separatorOffset > $offset) {
                $this->appendPartialWordText(substr($text, $offset, $separatorOffset - $offset));
            }

            if ($separator === ' ') {
                $this->completePartialWord(includeEmpty: true);
                $this->partialJoinToken = $this->partialToken(' ');
                $this->partialHasUnfinishedLine = true;
            } else {
                $this->finishPartialLogicalLine();
                $this->partialHasUnfinishedLine = true;
            }

            $offset = $separatorOffset + strlen($separator);
        }

        if ($offset < strlen($text)) {
            $this->appendPartialWordText(substr($text, $offset));
        }
    }

    /**
     * Apply a complete terminal escape sequence to subsequent visible text.
     */
    private function applyPartialEscape(string $escape): void
    {
        if (str_starts_with($escape, "\e[") && str_ends_with($escape, 'm')) {
            $this->updateSgrState($this->partialSgr, $escape);

            return;
        }

        $link = Utils::resolveOsc8Link($escape);

        if ($link !== null) {
            $this->partialLink = $link;
        }
    }

    /**
     * Append text to the unfinished word, cutting only when it exceeds the width.
     */
    private function appendPartialWordText(string $text): void
    {
        $token = $this->partialToken($text);

        if ($this->partialWordWidth + $token['width'] <= $this->taskOutputWidth()
            && $this->partialWordBytes + strlen($text) <= Utils::MAX_UNBREAKABLE_BYTES) {
            $this->appendPartialWordToken($token);

            return;
        }

        $graphemes = Utils::graphemes($this->partialTrailingGrapheme . $text);
        $consumedBytes = strlen($this->partialTrailingGrapheme);

        foreach ($graphemes as $character) {
            if ($consumedBytes >= strlen($character)) {
                $consumedBytes -= strlen($character);

                continue;
            }

            if ($consumedBytes > 0) {
                $character = substr($character, $consumedBytes);
                $consumedBytes = 0;
            }

            $token = $this->partialToken($character);
            $exceedsWidth = $this->partialWordWidth + $token['width'] > $this->taskOutputWidth();
            $continuedGraphemeBytes = null;

            if ($this->partialWordTokens !== [] && ($exceedsWidth
                || $this->partialWordBytes + strlen($character) > Utils::MAX_UNBREAKABLE_BYTES)) {
                $continuedGraphemeBytes = Utils::continuedGraphemeBytes(
                    $this->partialTrailingGrapheme,
                    $character,
                );
            }

            if ($this->partialWordTokens !== []
                && (($exceedsWidth && $continuedGraphemeBytes === null)
                    || ($continuedGraphemeBytes !== null
                        && $continuedGraphemeBytes > Utils::MAX_UNBREAKABLE_BYTES))) {
                $this->completePartialWord();
            }

            $this->appendPartialWordToken($token);
        }
    }

    /**
     * Append one token to the unfinished word.
     *
     * @param array{text: string, width: int, codes: string, link: string} $token
     */
    private function appendPartialWordToken(array $token): void
    {
        $lastIndex = array_key_last($this->partialWordTokens);

        if ($lastIndex !== null
            && $this->partialWordTokens[$lastIndex]['codes'] === $token['codes']
            && $this->partialWordTokens[$lastIndex]['link'] === $token['link']) {
            $this->partialWordTokens[$lastIndex]['text'] .= $token['text'];
            $this->partialWordTokens[$lastIndex]['width'] += $token['width'];
        } else {
            $this->partialWordTokens[] = $token;
        }

        $this->partialWordWidth += $token['width'];
        $this->partialWordBytes += strlen($token['text']);
        $grapheme = $this->partialTrailingGrapheme . $token['text'];
        $this->partialTrailingGrapheme = preg_match('/\X\z/u', $grapheme, $matches) === 1
            ? $matches[0]
            : '';
        $this->partialHasUnfinishedLine = true;
    }

    /**
     * Complete the current word using mbWordwrap's implicit separator model.
     */
    private function completePartialWord(bool $includeEmpty = false): bool
    {
        if ($this->partialWordTokens === [] && ! $includeEmpty) {
            return false;
        }

        $candidateWidth = $this->partialWordWidth;

        if (! $this->partialLineUnset) {
            $candidateWidth += $this->partialLineWidth + 1;
        }

        $wrapped = ! $this->partialLineUnset && $candidateWidth > $this->taskOutputWidth();

        if ($wrapped) {
            if ($this->partialLineTokens === []) {
                $this->resetPartialLine();
            } else {
                $this->storePartialLine();
            }

            $this->partialLineTokens = $this->partialWordTokens;
            $this->partialLineWidth = $this->partialWordWidth;
        } else {
            if (! $this->partialLineUnset && $this->partialJoinToken !== null) {
                $this->partialLineTokens[] = $this->partialJoinToken;
            }

            array_push($this->partialLineTokens, ...$this->partialWordTokens);
            $this->partialLineWidth = $candidateWidth;
        }

        $this->partialLineUnset = false;
        $this->partialWordTokens = [];
        $this->partialWordWidth = 0;
        $this->partialWordBytes = 0;
        $this->partialTrailingGrapheme = '';
        $this->partialJoinToken = null;
        $this->partialHasUnfinishedLine = true;

        return $wrapped;
    }

    /**
     * Finish one explicit or final logical line.
     */
    private function finishPartialLogicalLine(): void
    {
        $wrapped = $this->completePartialWord(includeEmpty: $this->partialJoinToken !== null);

        // A trailing separator that caused a wrap does not create another blank line.
        if (! $wrapped || $this->partialLineTokens !== []) {
            $this->storePartialLine();
        } else {
            $this->resetPartialLine();
        }
    }

    /**
     * Create one visible token using the active terminal state.
     *
     * @return array{text: string, width: int, codes: string, link: string}
     */
    private function partialToken(string $text): array
    {
        return [
            'text' => $text,
            'width' => mb_strwidth($text),
            'codes' => implode('', $this->partialSgr),
            'link' => $this->partialLink,
        ];
    }

    /**
     * Store the current visible line in the bounded partial region.
     */
    private function storePartialLine(): void
    {
        $this->partialLines[] = $this->renderPartialTokens($this->partialLineTokens);
        $this->resetPartialLine();

        if (count($this->partialLines) > $this->limit) {
            array_shift($this->partialLines);
        }
    }

    /**
     * Reset the current visible line.
     */
    private function resetPartialLine(): void
    {
        $this->partialLineTokens = [];
        $this->partialLineWidth = 0;
        $this->partialLineUnset = true;
        $this->partialHasUnfinishedLine = false;
    }

    /**
     * Synchronize the bounded partial region into Task's visible log buffer.
     */
    private function synchronizePartialLogs(bool $includePreview = true): void
    {
        $prefix = array_slice($this->logs, 0, $this->partialStartIndex ?? count($this->logs));
        $partial = $this->partialLines;

        if ($includePreview && $this->partialHasUnfinishedLine) {
            array_push($partial, ...$this->partialPreviewLines());
        }

        $this->logs = [...$prefix, ...$partial];
        $this->partialStartIndex = count($prefix);
        $this->trimTaskLogs();
    }

    /**
     * Render the unfinished word without mutating its cross-chunk state.
     *
     * @return list<string>
     */
    private function partialPreviewLines(): array
    {
        if ($this->partialLineUnset) {
            return [$this->renderPartialTokens($this->partialWordTokens)];
        }

        $candidateWidth = $this->partialLineWidth + 1 + $this->partialWordWidth;

        if ($candidateWidth <= $this->taskOutputWidth()) {
            $tokens = $this->partialLineTokens;

            if ($this->partialJoinToken !== null) {
                $tokens[] = $this->partialJoinToken;
            }

            array_push($tokens, ...$this->partialWordTokens);

            return [$this->renderPartialTokens($tokens)];
        }

        $lines = $this->partialLineTokens === []
            ? []
            : [$this->renderPartialTokens($this->partialLineTokens)];

        if ($this->partialWordTokens !== []) {
            $lines[] = $this->renderPartialTokens($this->partialWordTokens);
        }

        return $lines;
    }

    /**
     * Render styled visible tokens into one terminal line.
     *
     * @param list<array{text: string, width: int, codes: string, link: string}> $tokens
     */
    private function renderPartialTokens(array $tokens): string
    {
        $line = '';
        $codes = '';
        $link = '';

        foreach ($tokens as $token) {
            if ($token['link'] !== $link) {
                if ($link !== '') {
                    $line .= "\e]8;;\e\\";
                }

                if ($token['link'] !== '') {
                    $line .= $token['link'];
                }

                $link = $token['link'];
            }

            if ($token['codes'] !== $codes) {
                if ($codes !== '') {
                    $line .= "\e[0m";
                }

                if ($token['codes'] !== '') {
                    $line .= $token['codes'];
                }

                $codes = $token['codes'];
            }

            $line .= $token['text'];
        }

        if ($codes !== '') {
            $line .= "\e[0m";
        }

        if ($link !== '') {
            $line .= "\e]8;;\e\\";
        }

        return $line;
    }

    /**
     * Trim visible logs and keep the protected partial boundary aligned.
     */
    private function trimTaskLogs(): void
    {
        while (count($this->logs) > $this->limit) {
            array_shift($this->logs);

            if ($this->partialStartIndex !== null && $this->partialStartIndex > 0) {
                --$this->partialStartIndex;
            } elseif ($this->partialStartIndex === 0 && $this->partialLines !== []) {
                array_shift($this->partialLines);
            }
        }
    }

    /**
     * Return the usable width for Task log output.
     */
    private function taskOutputWidth(): int
    {
        return max(1, $this->terminal()->cols() - 10);
    }
}
