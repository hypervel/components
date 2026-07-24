<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc\Fixtures;

use Closure;
use Hypervel\Contracts\Engine\Http\V2\ClientInterface;
use Hypervel\Contracts\Engine\Http\V2\RequestInterface;
use Hypervel\Contracts\Engine\Http\V2\ResponseInterface;
use Hypervel\Engine\Channel;
use LogicException;
use Throwable;

class ClientCallClient implements ClientInterface
{
    public bool $connected = true;

    public int $closeCount = 0;

    public int $writeDelayMicroseconds = 0;

    public int $maximumConcurrentWrites = 0;

    /** @var list<RequestInterface> */
    public array $sentRequests = [];

    /** @var list<?float> */
    public array $sendTimeouts = [];

    /** @var list<array{stream_id: int, data: string, end: bool}> */
    public array $writes = [];

    /** @var list<?float> */
    public array $writeTimeouts = [];

    /** @var array<int, true> */
    private array $streams = [];

    private int $nextStreamId = 1;

    private int $concurrentWrites = 0;

    private Channel $events;

    /** @var null|Closure(): void */
    private ?Closure $writeCallback = null;

    public function __construct()
    {
        $this->events = new Channel(128);
    }

    /**
     * Configure behavior inside the serialized write operation.
     */
    public function writeUsing(Closure $callback): void
    {
        $this->writeCallback = $callback;
    }

    /**
     * Deliver one response event to the connection receiver.
     */
    public function respond(ResponseInterface $response): void
    {
        if ($response->isEndStream()) {
            unset($this->streams[$response->getStreamId()]);
        } else {
            $this->streams[$response->getStreamId()] = true;
        }

        $this->events->push($response);
    }

    /**
     * Send an initial HTTP/2 request.
     */
    public function send(RequestInterface $request, ?float $timeout = null): int
    {
        if (! $this->connected) {
            throw new LogicException('The fake HTTP/2 client is closed.');
        }

        $streamId = $this->nextStreamId;
        $this->nextStreamId += 2;
        $this->sentRequests[] = $request;
        $this->sendTimeouts[] = $timeout;
        $this->streams[$streamId] = true;

        return $streamId;
    }

    /**
     * Receive one queued response event.
     */
    public function recv(float $timeout = 0): ?ResponseInterface
    {
        $event = $this->events->pop($timeout);

        if ($event === false) {
            return null;
        }

        if ($event instanceof Throwable) {
            throw $event;
        }

        return $event;
    }

    /**
     * Write one request DATA operation.
     */
    public function write(
        int $streamId,
        string $data,
        bool $end = false,
        ?float $timeout = null,
    ): void {
        ++$this->concurrentWrites;
        $this->maximumConcurrentWrites = max(
            $this->maximumConcurrentWrites,
            $this->concurrentWrites,
        );

        try {
            if ($this->writeDelayMicroseconds > 0) {
                usleep($this->writeDelayMicroseconds);
            }

            ($this->writeCallback ?? static function (): void {
            })();
            $this->writes[] = [
                'stream_id' => $streamId,
                'data' => $data,
                'end' => $end,
            ];
            $this->writeTimeouts[] = $timeout;
        } finally {
            --$this->concurrentWrites;
        }
    }

    /**
     * Close the fake connection and wake its receiver.
     */
    public function close(): void
    {
        ++$this->closeCount;
        $this->connected = false;
        $this->streams = [];

        if (! $this->events->isClosing()) {
            $this->events->close();
        }
    }

    /**
     * Determine whether the fake connection is open.
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Determine whether the fake native stream remains open.
     */
    public function isStreamOpen(int $streamId): bool
    {
        return isset($this->streams[$streamId]);
    }
}
