<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Transport;

use Closure;
use Hypervel\Mcp\Server\Contracts\Transport;
use RuntimeException;

class StdioTransport implements Transport
{
    /**
     * Create a stdio server transport.
     *
     * @param null|(Closure(string): void) $handler
     */
    public function __construct(
        protected ?Closure $handler = null,
    ) {
    }

    /**
     * Register the incoming message handler.
     */
    public function onReceive(Closure $handler): void
    {
        $this->handler = $handler;
    }

    /**
     * Write one protocol message to standard output.
     */
    public function send(string $message): void
    {
        $message .= PHP_EOL;

        if (fwrite(STDOUT, $message) !== strlen($message)) {
            throw new RuntimeException('Could not write the complete MCP message to standard output.');
        }
    }

    /**
     * Read complete messages until standard input closes.
     */
    public function run(): void
    {
        // Blocking reads keep partial lines intact without polling.
        stream_set_blocking(STDIN, true);

        while (($line = fgets(STDIN)) !== false) {
            if (is_callable($this->handler)) {
                ($this->handler)($line);
            }
        }
    }

    /**
     * Run a streamed response on the stdio connection.
     */
    public function stream(Closure $stream): void
    {
        $stream();
    }
}
