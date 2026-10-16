<?php

declare(strict_types=1);

namespace Workbench\App\Support;

class StreamingMarkdown
{
    private string $pending = '';

    private string $delimiter = '';

    private string $headingPrefix = '';

    private bool $asterisk = false;

    private bool $lineStart = true;

    private bool $heading = false;

    private int $backticks = 0;

    private bool $fenced = false;

    private bool $closingFence = false;

    /**
     * Format text incrementally without treating chunk boundaries as line boundaries.
     */
    public function push(string $delta): string
    {
        $output = '';
        $length = strlen($delta);

        for ($index = 0; $index < $length; ++$index) {
            $character = $delta[$index];

            if ($character !== '`') {
                $output .= $this->flushBackticks();
            }

            if ($character === "\n") {
                $output .= $this->flush() . "\n";

                continue;
            }

            if ($this->lineStart && ($character === ' ' || $character === "\t")) {
                $output .= $character;

                continue;
            }

            if ($this->fenced) {
                if ($this->lineStart && $character === '`') {
                    ++$this->backticks;
                } else {
                    if ($character !== ' ' && $character !== "\t" && $character !== "\r") {
                        $this->closingFence = false;
                    }

                    $output .= $character;
                    $this->lineStart = false;
                }

                continue;
            }

            if ($this->delimiter !== '') {
                $this->pending .= $character;

                $delimiterLength = strlen($this->delimiter);

                if (strlen($this->pending) > $delimiterLength * 2 && str_ends_with($this->pending, $this->delimiter)) {
                    $content = substr($this->pending, $delimiterLength, -$delimiterLength);
                    if ($this->delimiter === '**') {
                        $content = preg_replace('/`([^`]+)`/', "\e[36m$1\e[39m", $content);
                    }

                    $output .= $this->delimiter === '`'
                        ? "\e[36m{$content}\e[39m"
                        : "\e[1m{$content}\e[22m";
                    $output .= $this->heading ? "\e[1;36m" : '';
                    $this->pending = $this->delimiter = '';
                }

                continue;
            }

            if ($this->headingPrefix !== '') {
                if ($character === '#' && strlen($this->headingPrefix) < 6) {
                    $this->headingPrefix .= $character;

                    continue;
                }

                if ($character === ' ' || $character === "\t") {
                    $this->headingPrefix = '';
                    $this->heading = true;
                    $output .= "\e[1;36m";

                    continue;
                }

                $output .= $this->headingPrefix;
                $this->headingPrefix = '';
            }

            if ($this->asterisk) {
                $this->asterisk = false;

                if ($character === '*') {
                    $this->pending = $this->delimiter = '**';

                    continue;
                }

                $output .= '*';
            }

            if ($this->lineStart && $character === '#') {
                $this->headingPrefix = '#';
            } elseif ($character === '*') {
                $this->asterisk = true;
            } elseif ($character === '`') {
                ++$this->backticks;

                continue;
            } else {
                $output .= $character;
            }

            $this->lineStart = false;
        }

        return $output;
    }

    /**
     * Preserve complete backtick runs even when they arrive in separate chunks.
     */
    private function flushBackticks(): string
    {
        if ($this->backticks === 0) {
            return '';
        }

        $output = str_repeat('`', $this->backticks);

        if ($this->lineStart && $this->backticks >= 3) {
            $this->closingFence = $this->fenced;
            $this->fenced = true;
        } elseif ($this->backticks === 1 && ! $this->fenced) {
            $this->pending = $this->delimiter = '`';
            $output = '';
        }

        $this->backticks = 0;
        $this->lineStart = false;

        return $output;
    }

    /**
     * Emit unfinished markup literally and close the current line's styles.
     */
    public function flush(): string
    {
        $output = $this->flushBackticks() . $this->pending . $this->headingPrefix . ($this->asterisk ? '*' : '') . ($this->heading ? "\e[22;39m" : '');

        if ($this->closingFence) {
            $this->fenced = false;
        }

        $this->closingFence = false;
        $this->pending = $this->delimiter = $this->headingPrefix = '';
        $this->asterisk = $this->heading = false;
        $this->lineStart = true;

        return $output;
    }
}
