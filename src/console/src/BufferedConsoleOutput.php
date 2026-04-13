<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Override;
use Symfony\Component\Console\Output\ConsoleOutput;

class BufferedConsoleOutput extends ConsoleOutput
{
    /**
     * The current buffer.
     */
    protected string $buffer = '';

    /**
     * Empty the buffer and return its content.
     */
    public function fetch(): string
    {
        return tap($this->buffer, function () {
            $this->buffer = '';
        });
    }

    #[Override]
    protected function doWrite(string $message, bool $newline): void
    {
        $this->buffer .= $message;

        if ($newline) {
            $this->buffer .= PHP_EOL;
        }

        parent::doWrite($message, $newline);
    }
}
