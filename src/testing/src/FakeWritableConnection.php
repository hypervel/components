<?php

declare(strict_types=1);

namespace Hypervel\Testing;

use Hypervel\Contracts\Engine\Http\Writable;

/**
 * A fake writable connection for use in tests.
 *
 * Records all streamed content written via Response::stream()
 * so it can be retrieved for assertions in TestResponse.
 */
class FakeWritableConnection implements Writable
{
    public string $written = '';

    public function __construct(
        private readonly FakeSwooleSocket $socket = new FakeSwooleSocket,
    ) {
    }

    public function getSocket(): FakeSwooleSocket
    {
        return $this->socket;
    }

    public function write(string $data): bool
    {
        $this->written .= $data;

        return true;
    }

    public function end(): ?bool
    {
        return true;
    }

    /**
     * Get the content that was written to this connection.
     */
    public function getWrittenContent(): string
    {
        return $this->written;
    }

    /**
     * Reset the written content buffer.
     */
    public function reset(): void
    {
        $this->written = '';
    }
}
