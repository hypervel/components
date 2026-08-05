<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Client;

use Closure;
use Hypervel\Contracts\Engine\Http\V2\ResponseInterface;
use Hypervel\Engine\Channel;
use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Exceptions\ProtocolException;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Metadata;
use Hypervel\Grpc\Protocol\Deadline;
use Hypervel\Grpc\Protocol\FrameDecoder;
use Hypervel\Grpc\Protocol\MediaType;
use Hypervel\Grpc\Protocol\MetadataCodec;
use Hypervel\Grpc\Protocol\StatusCodec;
use Hypervel\Grpc\Status;
use Hypervel\Grpc\StatusCode;
use LogicException;
use SplQueue;
use Throwable;

/**
 * @internal
 */
final class StreamState
{
    private ?int $streamId = null;

    private bool $initialEvent = true;

    private ?int $httpStatus = null;

    private bool $grpcResponse = false;

    private bool $trailersOnly = false;

    private bool $committed = false;

    private bool $abandoned = false;

    private ?FrameDecoder $decoder = null;

    /** @var SplQueue<string> */
    private SplQueue $messages;

    private int $bufferedBytes = 0;

    private int $nonGrpcBodyBytes = 0;

    private ?Metadata $metadata = null;

    private ?Metadata $trailers = null;

    private ?Status $status = null;

    private ?Throwable $failure = null;

    private bool $hasRetryPushback = false;

    /** @var null|list<string>|string */
    private string|array|null $retryPushback = null;

    /** @var array<int, Channel<bool>> */
    private array $waiters = [];

    private int $nextWaiterId = 0;

    /** @var null|Closure(self): void */
    private ?Closure $onAbandon = null;

    public function __construct(
        private readonly Deadline $deadline,
        private readonly int $maxReceiveMessageSize,
        private readonly int $maxMetadataSize,
        private readonly int $maxBufferedMessages,
        private readonly int $maxBufferedBytes,
    ) {
        $this->messages = new SplQueue;
    }

    /**
     * Attach the native stream identifier.
     */
    public function attachStream(int $streamId): void
    {
        if ($this->streamId !== null && $this->streamId !== $streamId) {
            throw new LogicException('The gRPC call is already attached to another HTTP/2 stream.');
        }

        $this->streamId = $streamId;
    }

    /**
     * Return the native stream identifier.
     */
    public function streamId(): ?int
    {
        return $this->streamId;
    }

    /**
     * Register the connection abandonment callback.
     *
     * @param Closure(self): void $callback
     */
    public function onAbandon(Closure $callback): void
    {
        $this->onAbandon = $callback;
    }

    /**
     * Apply one incremental HTTP/2 response event.
     */
    public function handle(ResponseInterface $response): void
    {
        if ($this->isComplete()) {
            throw new LogicException('The completed gRPC stream cannot accept another response event.');
        }

        $headers = $response->getHeaders();
        $endStream = $response->isEndStream();
        $initialEvent = $this->initialEvent;
        $headerSize = MetadataCodec::wireSize($headers);

        if ($initialEvent) {
            $headerSize += strlen(':status') + strlen((string) $response->getStatusCode()) + 32;
        }

        if ($headerSize > $this->maxMetadataSize) {
            throw new ProtocolException('The peer response metadata exceeds the configured limit.');
        }

        if ($initialEvent) {
            $this->initializeResponse($response, $headers, $endStream);
        }

        if (! $this->grpcResponse) {
            $this->handleNonGrpcBody($response->getBody());

            if ($endStream) {
                $this->trailers = Metadata::make();
                $this->status = StatusCodec::fromHttpStatus($this->httpStatus ?? 0);
                $this->onAbandon = null;
            }

            $this->initialEvent = false;
            $this->signalWaiters();

            return;
        }

        $status = StatusCodec::parse($headers, $this->httpStatus ?? 0, $endStream);
        $body = $response->getBody() ?? '';

        if ($this->trailersOnly && $body !== '') {
            throw new ProtocolException('A trailers-only gRPC response cannot contain message data.');
        }

        if ($body !== '') {
            $this->decodeFrames($body);
        }

        if ($this->abandoned) {
            return;
        }

        if ($endStream) {
            $this->decoder?->finish();
            $this->decoder = null;
            $this->trailers = $initialEvent && ! $this->trailersOnly
                ? Metadata::make()
                : MetadataCodec::decode($headers);
            $this->hasRetryPushback = array_key_exists('grpc-retry-pushback-ms', $headers);
            $this->retryPushback = $this->hasRetryPushback
                ? $headers['grpc-retry-pushback-ms']
                : null;
            $this->status = $status ?? throw new LogicException(
                'A final gRPC response event must produce a status.',
            );
            $this->onAbandon = null;
        }

        $this->initialEvent = false;
        $this->signalWaiters();
    }

    /**
     * Mark a transport or protocol failure.
     */
    public function fail(Throwable $failure, bool $abandon = false): void
    {
        if ($this->isComplete()) {
            return;
        }

        $this->failure = $failure;

        if ($abandon) {
            $this->releaseBuffers();
            $this->abandon();
        } else {
            $this->onAbandon = null;
        }

        $this->signalWaiters();
    }

    /**
     * Discard an untrusted response and replace it with a terminal failure.
     *
     * This is only valid while terminating a connection whose pending stream was
     * attached to a different identifier than the native send operation returned.
     *
     * @internal
     */
    public function discardAndFail(Throwable $failure): void
    {
        $this->initialEvent = true;
        $this->httpStatus = null;
        $this->grpcResponse = false;
        $this->trailersOnly = false;
        $this->committed = false;
        $this->abandoned = true;
        $this->nonGrpcBodyBytes = 0;
        $this->metadata = null;
        $this->trailers = null;
        $this->status = null;
        $this->failure = $failure;
        $this->hasRetryPushback = false;
        $this->retryPushback = null;
        $this->onAbandon = null;
        $this->releaseBuffers();
        $this->signalWaiters();
    }

    /**
     * Expire the call when its monotonic deadline has elapsed.
     */
    public function expireIfNeeded(): bool
    {
        if ($this->isComplete() || ! $this->deadline->expired()) {
            return false;
        }

        $this->failWithStatus(new Status(
            StatusCode::DeadlineExceeded,
            'The gRPC deadline was exceeded.',
        ));

        return true;
    }

    /**
     * Mark a local protocol status and abandon the native stream.
     */
    public function failWithStatus(Status $status): void
    {
        if ($status->isOk()) {
            throw new LogicException('A locally failed gRPC stream requires a non-OK status.');
        }

        if ($this->isComplete()) {
            return;
        }

        $this->metadata ??= Metadata::make();
        $this->trailers = Metadata::make();
        $this->status = $status;
        $this->releaseBuffers();
        $this->abandon();
        $this->signalWaiters();
    }

    /**
     * Wait for and return initial response metadata.
     */
    public function metadata(): Metadata
    {
        $this->await(fn (): bool => $this->metadata !== null);

        return $this->metadata ?? throw new LogicException('The response metadata is unavailable.');
    }

    /**
     * Wait for and return final trailing metadata.
     */
    public function trailers(): Metadata
    {
        $this->await(fn (): bool => $this->trailers !== null);

        return $this->trailers ?? throw new LogicException('The response trailers are unavailable.');
    }

    /**
     * Wait for and return the final gRPC status.
     */
    public function status(): Status
    {
        $this->await(fn (): bool => $this->status !== null);

        return $this->status ?? throw new LogicException('The response status is unavailable.');
    }

    /**
     * Wait for and remove the next serialized response message.
     */
    public function nextMessage(): ?string
    {
        $this->await(fn (): bool => ! $this->messages->isEmpty() || $this->status !== null);

        if ($this->messages->isEmpty()) {
            return null;
        }

        $message = $this->messages->dequeue();
        $this->bufferedBytes -= strlen($message);

        return $message;
    }

    /**
     * Determine whether response headers or a message committed the attempt.
     */
    public function committed(): bool
    {
        return $this->committed;
    }

    /**
     * Determine whether the final response was trailers-only.
     */
    public function trailersOnly(): bool
    {
        return $this->trailersOnly;
    }

    /**
     * Determine whether the call has locally abandoned its native stream.
     */
    public function isAbandoned(): bool
    {
        return $this->abandoned;
    }

    /**
     * Determine whether the state has a final status or failure.
     */
    public function isComplete(): bool
    {
        return $this->status !== null || $this->failure !== null;
    }

    /**
     * Determine whether retry pushback was present.
     */
    public function hasRetryPushback(): bool
    {
        return $this->hasRetryPushback;
    }

    /**
     * Return the raw retry-pushback value.
     *
     * @return null|list<string>|string
     */
    public function retryPushback(): string|array|null
    {
        return $this->retryPushback;
    }

    /**
     * Return the number of buffered response messages.
     */
    public function bufferedMessageCount(): int
    {
        return $this->messages->count();
    }

    /**
     * Return the buffered response payload bytes.
     */
    public function bufferedBytes(): int
    {
        return $this->bufferedBytes;
    }

    /**
     * Initialize response representation and metadata from the first event.
     *
     * @param array<string, list<string>|string> $headers
     */
    private function initializeResponse(
        ResponseInterface $response,
        array $headers,
        bool $endStream,
    ): void {
        $this->httpStatus = $response->getStatusCode();
        $contentType = $headers['content-type'] ?? null;

        if ($contentType !== null && ! is_string($contentType)) {
            throw new ProtocolException('The peer returned multiple gRPC content types.');
        }

        $mediaType = is_string($contentType) ? MediaType::parse($contentType) : null;

        if ($mediaType === null) {
            $this->metadata = Metadata::make();
            $this->grpcResponse = false;
            $this->committed = ! $endStream;

            return;
        }

        if (! $mediaType->isProtobuf()) {
            throw new ProtocolException('The peer returned an unsupported gRPC representation.');
        }

        $encodingValue = $headers['grpc-encoding'] ?? Compression::Identity->value;

        if (! is_string($encodingValue) || str_contains($encodingValue, ',')) {
            throw new ProtocolException('The peer returned an invalid gRPC response encoding.');
        }

        $encoding = Compression::tryFrom(strtolower(trim($encodingValue)));

        if ($encoding === null) {
            throw new ProtocolException(
                "The peer returned unsupported gRPC response encoding [{$encodingValue}].",
            );
        }

        $this->grpcResponse = true;
        $this->trailersOnly = StatusCodec::isTrailersOnly($headers, true, $endStream);
        $this->metadata = $this->trailersOnly
            ? Metadata::make()
            : MetadataCodec::decode($headers);
        $this->committed = ! $this->trailersOnly && ! $endStream;
        $this->decoder = new FrameDecoder($encoding, $this->maxReceiveMessageSize);
    }

    /**
     * Count and discard a non-gRPC response body.
     */
    private function handleNonGrpcBody(?string $body): void
    {
        $this->nonGrpcBodyBytes += strlen($body ?? '');

        if ($this->nonGrpcBodyBytes > $this->maxReceiveMessageSize) {
            throw new ProtocolException(
                'The non-gRPC response body exceeds the configured receive limit.',
            );
        }
    }

    /**
     * Decode and buffer every completed message in a response event.
     */
    private function decodeFrames(string $body): void
    {
        $decoder = $this->decoder ?? throw new LogicException(
            'The gRPC response decoder has not been initialized.',
        );

        try {
            foreach ($decoder->push($body) as $message) {
                if (
                    $this->messages->count() >= $this->maxBufferedMessages
                    || strlen($message) > $this->maxBufferedBytes - $this->bufferedBytes
                ) {
                    $this->failWithStatus(new Status(
                        StatusCode::ResourceExhausted,
                        'The buffered gRPC response exceeds the configured limit.',
                    ));

                    break;
                }

                $this->messages->enqueue($message);
                $this->bufferedBytes += strlen($message);
                $this->committed = true;
            }
        } catch (RpcException $exception) {
            $this->failWithStatus($exception->status());
        }
    }

    /**
     * Wait until an observed state predicate becomes true.
     *
     * @param Closure(): bool $predicate
     */
    private function await(Closure $predicate): void
    {
        while (! $this->conditionMet($predicate)) {
            if ($this->failure !== null) {
                throw $this->failure;
            }

            $this->expireIfNeeded();

            if ($this->conditionMet($predicate)) {
                return;
            }

            $waiterId = ++$this->nextWaiterId;
            $waiter = new Channel(1);
            $this->waiters[$waiterId] = $waiter;

            try {
                if ($this->conditionMet($predicate) || $this->failure !== null) {
                    continue;
                }

                $remainingSeconds = $this->deadline->remainingSeconds();

                if ($remainingSeconds === 0.0) {
                    $this->expireIfNeeded();

                    continue;
                }

                $waiter->pop($remainingSeconds ?? -1);
            } finally {
                unset($this->waiters[$waiterId]);
                $waiter->close();
            }
        }

        if ($this->failure !== null && ! $this->conditionMet($predicate)) {
            throw $this->failure;
        }
    }

    /**
     * Determine whether an observed state condition has been met.
     *
     * @param Closure(): bool $predicate
     * @phpstan-impure
     */
    private function conditionMet(Closure $predicate): bool
    {
        return $predicate();
    }

    /**
     * Wake every current observer without blocking the receiver.
     */
    private function signalWaiters(): void
    {
        foreach ($this->waiters as $waiter) {
            // Registered observers are already suspended on empty capacity-one channels.
            $waiter->push(true);
        }
    }

    /**
     * Release payload and decoder memory.
     */
    private function releaseBuffers(): void
    {
        $this->messages = new SplQueue;
        $this->bufferedBytes = 0;
        $this->decoder = null;
    }

    /**
     * Retire the native stream exactly once.
     */
    private function abandon(): void
    {
        if ($this->abandoned) {
            return;
        }

        $this->abandoned = true;
        $onAbandon = $this->onAbandon;
        $this->onAbandon = null;

        if ($onAbandon !== null) {
            $onAbandon($this);
        }
    }
}
