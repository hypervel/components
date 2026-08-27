<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Concerns;

use Hypervel\Prompts\Themes\Default\Concerns\InteractsWithStrings;

/**
 * Track bounded incremental output owned by a Task operation.
 */
trait TracksTaskOutput
{
    use InteractsWithStrings;

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

    /**
     * The real separator awaiting the next word, or null for a long-word cut.
     *
     * @var null|array{text: string, width: int, codes: string, link: string}
     */
    private ?array $partialJoinToken = null;

    private string $partialInputBuffer = '';

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
        $width = $this->taskOutputWidth();
        $logicalLines = preg_split('/\r\n|\n/', $line);

        foreach ($logicalLines as $logicalLine) {
            array_push($this->logs, ...$this->ansiWordwrap($logicalLine, $width, cutLongWords: true));
        }

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
        $this->partialJoinToken = null;
        $this->partialInputBuffer = '';
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
            if ($buffer[$cursor] === "\e") {
                $escape = $this->readPartialEscape($buffer, $cursor, $final);

                if ($escape === null) {
                    break;
                }

                if ($escape['complete']) {
                    $this->applyPartialEscape($escape['sequence']);
                } else {
                    $this->consumePartialText($escape['sequence']);
                }

                $cursor += $escape['length'];

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
    }

    /**
     * Read one terminal escape sequence without mutating the input buffer.
     *
     * @return null|array{sequence: string, length: int, complete: bool}
     */
    private function readPartialEscape(string $buffer, int $offset, bool $final): ?array
    {
        $length = strlen($buffer);
        $remainingLength = $length - $offset;

        if ($remainingLength === 1) {
            if (! $final) {
                return null;
            }

            return ['sequence' => "\e", 'length' => 1, 'complete' => false];
        }

        if ($buffer[$offset + 1] === '[') {
            $position = $offset + 2;

            while ($position < $length && ord($buffer[$position]) >= 0x30 && ord($buffer[$position]) <= 0x3F) {
                ++$position;
            }

            while ($position < $length && ord($buffer[$position]) >= 0x20 && ord($buffer[$position]) <= 0x2F) {
                ++$position;
            }

            if ($position >= $length || ord($buffer[$position]) < 0x40 || ord($buffer[$position]) > 0x7E) {
                if (! $final) {
                    return null;
                }

                return [
                    'sequence' => substr($buffer, $offset),
                    'length' => $remainingLength,
                    'complete' => false,
                ];
            }

            $escapeLength = $position - $offset + 1;

            return [
                'sequence' => substr($buffer, $offset, $escapeLength),
                'length' => $escapeLength,
                'complete' => true,
            ];
        }

        if ($buffer[$offset + 1] === ']') {
            $bell = strpos($buffer, "\x07", $offset + 2);
            $stringTerminator = strpos($buffer, "\e\\", $offset + 2);
            $end = match (true) {
                $bell === false => $stringTerminator === false ? null : $stringTerminator + 2,
                $stringTerminator === false => $bell + 1,
                default => min($bell + 1, $stringTerminator + 2),
            };

            if ($end === null) {
                if (! $final) {
                    return null;
                }

                return [
                    'sequence' => substr($buffer, $offset),
                    'length' => $remainingLength,
                    'complete' => false,
                ];
            }

            $escapeLength = $end - $offset;

            return [
                'sequence' => substr($buffer, $offset, $escapeLength),
                'length' => $escapeLength,
                'complete' => true,
            ];
        }

        return [
            'sequence' => substr($buffer, $offset, 2),
            'length' => 2,
            'complete' => true,
        ];
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
            for ($suffixLength = 1; $suffixLength <= min(3, strlen($text)); ++$suffixLength) {
                $prefix = substr($text, 0, -$suffixLength);

                if ($prefix !== '' && mb_check_encoding($prefix, 'UTF-8')) {
                    return strlen($prefix);
                }
            }

            return 0;
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

        $link = $this->resolveOsc8Link($escape);

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

        if ($this->partialWordWidth + $token['width'] <= $this->taskOutputWidth()) {
            $this->appendPartialWordToken($token);

            return;
        }

        if (preg_match_all('/\X/u', $text, $matches) === false) {
            $matches = [str_split($text)];
        }

        foreach ($matches[0] as $character) {
            $token = $this->partialToken($character);

            if ($this->partialWordTokens !== []
                && $this->partialWordWidth + $token['width'] > $this->taskOutputWidth()) {
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
            $this->storePartialLine();
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

        $lines = [$this->renderPartialTokens($this->partialLineTokens)];

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
