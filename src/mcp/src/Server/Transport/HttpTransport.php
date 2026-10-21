<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Transport;

use Closure;
use Generator;
use Hypervel\Http\IterableStreamedResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Mcp\Enums\ErrorCode;
use Hypervel\Mcp\Server\Contracts\StreamsMessages;
use Hypervel\Mcp\Server\Contracts\Transport;
use ReflectionFunction;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HttpTransport implements Transport, StreamsMessages
{
    protected bool $streaming = false;

    /** @var null|list<string> */
    protected ?array $messages = null;

    /**
     * Create an HTTP transport for one request.
     *
     * @param null|(Closure(string): void) $handler
     */
    public function __construct(
        protected Request $request,
        protected ?Closure $handler = null,
        protected ?string $reply = null,
        protected ?Closure $stream = null,
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
     * Send an encoded protocol message.
     */
    public function send(string $message): void
    {
        if ($this->messages !== null) {
            $this->messages[] = $this->captureStreamMessage($message);
        } elseif ($this->streaming || $this->stream instanceof Closure) {
            $this->sendStreamMessage($message);
        }

        $this->reply = $message;
    }

    /**
     * Dispatch the request and transfer stream ownership to its response.
     */
    public function run(): Response|StreamedResponse
    {
        try {
            if (is_callable($this->handler)) {
                ($this->handler)($this->request->getContent());
            }
        } finally {
            // The handler retains the server, which in turn owns this transport.
            $this->handler = null;
        }

        if ($this->stream instanceof Closure) {
            $stream = $this->stream;
            $headers = $this->getHeaders();
            $this->stream = null;
            $this->streaming = true;

            if ($this->messages !== null) {
                return (new IterableStreamedResponse($this->messageStream($stream), 200, $headers))
                    ->cancelOnDisconnect();
            }

            if ((new ReflectionFunction($stream))->isGenerator()) {
                return new IterableStreamedResponse($this->streamPayloads($stream()), 200, $headers);
            }

            return response()->stream(function () use (&$stream): void {
                try {
                    $result = $stream();

                    if (! is_iterable($result)) {
                        return;
                    }

                    foreach ($result as $message) {
                        $this->sendStreamMessage((string) $message);
                    }
                } finally {
                    $stream = null;
                    $this->streaming = false;
                }
            }, 200, $headers);
        }

        return response()->make($this->reply, $this->statusCode(), $this->getHeaders());
    }

    /**
     * Determine the status of a non-streaming response.
     */
    protected function statusCode(): int
    {
        // Must be 202 - https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http#sending-messages
        if ($this->reply === null) {
            return 202;
        }

        $reply = json_decode($this->reply, true);

        if (! is_array($reply) || ! is_array($reply['error'] ?? null)) {
            return 200;
        }

        return match ($reply['error']['code'] ?? null) {
            ErrorCode::METHOD_NOT_FOUND->value => 404,
            ErrorCode::INTERNAL_ERROR->value => 500,
            default => 400,
        };
    }

    /**
     * Register a streaming callback.
     *
     * The callback may echo SSE-formatted output directly or return an iterable of message payloads.
     *
     * @param Closure(): (iterable<string>|void) $stream
     */
    public function stream(Closure $stream): void
    {
        $this->stream = $stream;
    }

    /**
     * Stream messages with the negotiated protocol's disconnect behavior.
     *
     * @param Closure(): iterable<mixed> $producer
     */
    public function streamMessages(Closure $producer, bool $cancelOnDisconnect): void
    {
        if (! $cancelOnDisconnect) {
            // Legacy requests finish their work even when the response cannot be written.
            $this->stream(static function () use ($producer): void {
                foreach ($producer() as $message) {
                    // Messages are sent by the producer through send().
                }
            });

            return;
        }

        $this->messages = [];
        $this->stream($producer);
    }

    /**
     * Yield messages after each producer advancement without retaining its callback.
     *
     * @param Closure(): iterable<mixed> $producer
     * @return Generator<int, string>
     */
    protected function messageStream(Closure $producer): Generator
    {
        try {
            $result = $producer();
            unset($producer);

            foreach ($result as $message) {
                yield from $this->drainMessages();
            }

            yield from $this->drainMessages();
        } finally {
            $this->messages = null;
            $this->streaming = false;
        }
    }

    /**
     * Frame direct generator output without bypassing the message hook.
     *
     * @param iterable<string> $messages
     * @return Generator<int, string>
     */
    protected function streamPayloads(iterable $messages): Generator
    {
        try {
            foreach ($messages as $message) {
                yield $this->captureStreamMessage((string) $message);
            }
        } finally {
            $this->streaming = false;
        }
    }

    /**
     * Drain messages before advancing the producer again.
     *
     * @return Generator<int, string>
     */
    protected function drainMessages(): Generator
    {
        $messages = $this->messages;
        $this->messages = [];

        foreach ($messages as $message) {
            yield $message;
        }
    }

    /**
     * Capture one message hook invocation, including explicitly flushed output.
     */
    private function captureStreamMessage(string $message): string
    {
        $chunk = '';

        ob_start(static function (string $buffer) use (&$chunk): string {
            $chunk .= $buffer;

            return '';
        });

        try {
            $this->sendStreamMessage($message);
        } finally {
            ob_end_flush();
        }

        return $chunk;
    }

    /**
     * Write one event to a callback-based stream.
     */
    protected function sendStreamMessage(string $message): void
    {
        echo 'data: ' . $message . "\n\n";

        if (ob_get_level() !== 0) {
            ob_flush();
        }

        flush();
    }

    /**
     * Return the response headers for the current delivery mode.
     *
     * @return array<string, string>
     */
    protected function getHeaders(): array
    {
        $headers = [
            'Content-Type' => $this->stream instanceof Closure ? 'text/event-stream' : 'application/json',
        ];

        if ($this->stream instanceof Closure) {
            $headers['X-Accel-Buffering'] = 'no';
        }

        return $headers;
    }
}
