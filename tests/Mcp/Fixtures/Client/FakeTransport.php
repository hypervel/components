<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures\Client;

use Hypervel\Mcp\Client\Contracts\Transport;
use Hypervel\Mcp\Client\Contracts\UsesProtocol;
use Hypervel\Mcp\Client\Exceptions\TimeoutException;
use Hypervel\Mcp\Enums\ProtocolVersion;
use RuntimeException;
use Throwable;

class FakeTransport implements Transport, UsesProtocol
{
    public bool $connected = false;

    /** @var array<int, string> */
    public array $sent = [];

    /** @var array<int, array<string, string>> */
    public array $sentHeaders = [];

    /** @var array<int, string> */
    public array $responses = [];

    public float $timeoutSeconds = 30.0;

    public ?ProtocolVersion $protocolVersion = null;

    public bool $negotiates = false;

    public bool $timesOutOnDiscover = false;

    public ?Throwable $throwOnNextSend = null;

    protected string|int|null $pendingId = null;

    /**
     * Mark the transport as connected.
     */
    public function connect(): void
    {
        $this->connected = true;
    }

    /**
     * Mark the transport as disconnected.
     */
    public function disconnect(): void
    {
        $this->connected = false;
    }

    /**
     * Record a message and prepare its queued response.
     *
     * @param array<string, string> $headers
     */
    public function send(string $message, array $headers = []): void
    {
        if ($this->throwOnNextSend instanceof Throwable) {
            $throwable = $this->throwOnNextSend;
            $this->throwOnNextSend = null;

            throw $throwable;
        }

        $frame = json_decode($message, true);
        $frame = is_array($frame) ? $frame : [];

        $id = $frame['id'] ?? null;

        if (is_string($frame['method'] ?? null) && (is_string($id) || is_int($id))) {
            $this->pendingId = $id;
        }

        if ($this->timesOutOnDiscover && ($frame['method'] ?? null) === 'server/discover') {
            $this->connected = false;

            throw new TimeoutException('Timed out while waiting for server response.');
        }

        if (! $this->negotiates && ($frame['method'] ?? null) === 'server/discover') {
            array_unshift($this->responses, (string) json_encode([
                'jsonrpc' => '2.0',
                'id' => $this->pendingId,
                'error' => ['code' => -32601, 'message' => 'Method not found.'],
            ]));

            return;
        }

        $this->sent[] = $message;
        $this->sentHeaders[] = $headers;
    }

    /**
     * Set the transport timeout.
     */
    public function setTimeoutSeconds(float $seconds): void
    {
        $this->timeoutSeconds = $seconds;
    }

    /**
     * Set the selected protocol version.
     */
    public function useProtocol(ProtocolVersion $protocolVersion): void
    {
        $this->protocolVersion = $protocolVersion;
    }

    /**
     * Get the fake transport recipe.
     *
     * @return array<string, mixed>
     */
    public function recipe(): array
    {
        return ['driver' => 'fake'];
    }

    /**
     * Receive the next queued response.
     */
    public function receive(): string
    {
        if ($this->responses === []) {
            throw new RuntimeException('No queued responses in FakeTransport.');
        }

        return $this->answering(array_shift($this->responses));
    }

    /**
     * Match a queued terminal response to the pending request.
     */
    protected function answering(string $raw): string
    {
        $frame = json_decode($raw, true);

        if (! is_array($frame) || ! array_key_exists('id', $frame)) {
            return $raw;
        }

        if (! array_key_exists('result', $frame) && ! array_key_exists('error', $frame)) {
            return $raw;
        }

        return (string) json_encode([...$frame, 'id' => $this->pendingId]);
    }
}
