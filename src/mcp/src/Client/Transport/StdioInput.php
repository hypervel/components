<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Transport;

use Hypervel\Contracts\Container\Transient;
use Iterator;

/**
 * @internal
 * @implements Iterator<int, string>
 */
class StdioInput implements Iterator, Transient
{
    /** @var list<string> */
    private array $messages = [];

    private bool $open = true;

    /**
     * Queue a message for the subprocess.
     */
    public function write(string $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * Close input after queued messages have drained.
     */
    public function close(): void
    {
        $this->open = false;
    }

    /**
     * Return the currently queued message.
     */
    public function current(): string
    {
        // A suspended generator retains an empty yield even after a new message arrives.
        return $this->messages[0] ?? '';
    }

    /**
     * Remove the message handed to the process pipe buffer.
     */
    public function next(): void
    {
        array_shift($this->messages);
    }

    /**
     * Return the queue head's position.
     */
    public function key(): int
    {
        return 0;
    }

    /**
     * Keep input available until closed and drained.
     */
    public function valid(): bool
    {
        return $this->open || $this->messages !== [];
    }

    /**
     * Preserve the queue when the process begins reading.
     */
    public function rewind(): void
    {
    }
}
