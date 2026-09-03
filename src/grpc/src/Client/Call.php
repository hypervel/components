<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Client;

use Closure;
use Google\Protobuf\Internal\Message;
use Hypervel\Engine\Channel;
use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Exceptions\ProtocolException;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\GrpcOperationHandle;
use Hypervel\Grpc\GrpcOperationResult;
use Hypervel\Grpc\Metadata;
use Hypervel\Grpc\Protocol\Deadline;
use Hypervel\Grpc\Protocol\FrameEncoder;
use Hypervel\Grpc\Protocol\MessageSerializer;
use Hypervel\Grpc\Status;
use Hypervel\Grpc\StatusCode;
use LogicException;
use Swoole\Coroutine\CanceledException;
use Throwable;

abstract class Call
{
    private StreamState $state;

    /** @var array{class-string<Message>, string}|Closure(string): Message */
    private array|Closure $deserialize;

    private ?Throwable $failure = null;

    private bool $finished = false;

    private int $attempts = 1;

    private ?Channel $attemptSemaphore = null;

    private bool $attemptSemaphoreClosed = false;

    private ?Channel $retryDelayChannel = null;

    private ?Status $cancellationStatus = null;

    /** @var null|Closure(int): StreamState */
    private ?Closure $attemptFactory;

    private ?RetryBackoff $retryBackoff;

    private ?Channel $completionSemaphore = null;

    private bool $completionSemaphoreClosed = false;

    private bool $unaryResponseResolved = false;

    private ?Message $unaryResponse = null;

    private ?Throwable $unaryResponseFailure = null;

    private bool $hasPendingPayload = false;

    private string $pendingPayload = '';

    private bool $reading = false;

    private ?Connection $writeConnection = null;

    private ?FrameEncoder $requestEncoder = null;

    private Compression $requestCompression = Compression::Identity;

    private ?Channel $writeSemaphore = null;

    private bool $writeSemaphoreClosed = false;

    private bool $writesDone = false;

    /**
     * Create an active call around its initial transport attempt.
     *
     * @param array{class-string<Message>, string}|callable(string): Message $deserialize
     * @param null|Closure(int): StreamState $attemptFactory
     */
    protected function __construct(
        StreamState $state,
        private readonly string $method,
        private readonly string $peer,
        array|callable $deserialize,
        private readonly Deadline $deadline,
        private readonly ?RetryPolicy $retryPolicy = null,
        ?Closure $attemptFactory = null,
        ?RetryBackoff $retryBackoff = null,
        private readonly ?GrpcOperationHandle $operationHandle = null,
    ) {
        MessageSerializer::validate($deserialize);

        if (($retryPolicy === null) !== ($attemptFactory === null)) {
            throw new LogicException('A retryable gRPC call requires both a policy and an attempt factory.');
        }

        if ($retryPolicy === null && $retryBackoff !== null) {
            throw new LogicException('A gRPC retry backoff requires a retry policy.');
        }

        $this->state = $state;
        $this->deserialize = $this->normalizeDeserializer($deserialize);
        $this->attemptFactory = $attemptFactory;
        $this->retryBackoff = $retryPolicy === null
            ? null
            : ($retryBackoff ?? new RetryBackoff($retryPolicy));

        if ($retryPolicy !== null) {
            $this->attemptSemaphore = new Channel(1);
        }
    }

    /**
     * Return the server's initial metadata.
     */
    public function metadata(): Metadata
    {
        while (true) {
            $this->throwStoredFailure();
            $state = $this->state;

            try {
                $metadata = $state->metadata();
            } catch (Throwable $throwable) {
                $this->storeFailure($throwable);

                throw $throwable;
            }

            $this->throwStoredFailure();

            if ($state !== $this->state) {
                continue;
            }

            if (! $state->trailersOnly()) {
                return $metadata;
            }

            try {
                $status = $state->status();
            } catch (Throwable $throwable) {
                $this->storeFailure($throwable);

                throw $throwable;
            }

            if ($this->retry($state, $status)) {
                continue;
            }

            $status = $this->resolveStatus($status);
            $this->finish($status);

            return $metadata;
        }
    }

    /**
     * Return the server's trailing metadata.
     */
    public function trailers(): Metadata
    {
        while (true) {
            $this->throwStoredFailure();
            $state = $this->state;

            try {
                $trailers = $state->trailers();
                $status = $state->status();
            } catch (Throwable $throwable) {
                $this->storeFailure($throwable);

                throw $throwable;
            }

            $this->throwStoredFailure();

            if ($state !== $this->state || $this->retry($state, $status)) {
                continue;
            }

            $status = $this->resolveStatus($status);
            $this->finish($status);

            return $trailers;
        }
    }

    /**
     * Return the final gRPC status without throwing for an RPC error.
     */
    public function status(): Status
    {
        while (true) {
            $this->throwStoredFailure();
            $state = $this->state;

            try {
                $status = $state->status();
            } catch (Throwable $throwable) {
                $this->storeFailure($throwable);

                throw $throwable;
            }

            $this->throwStoredFailure();

            if ($state !== $this->state || $this->retry($state, $status)) {
                continue;
            }

            $status = $this->resolveStatus($status);
            $this->finish($status);

            return $status;
        }
    }

    /**
     * Return the normalized peer authority selected for this call.
     */
    public function peer(): string
    {
        return $this->peer;
    }

    /**
     * Cancel this logical call.
     */
    public function cancel(): void
    {
        if ($this->finished) {
            return;
        }

        $state = $this->state;

        if (! $state->isComplete()) {
            $this->clearPendingPayload();
            $status = new Status(
                StatusCode::Cancelled,
                'The gRPC call was canceled.',
            );
            $state->failWithStatus($status);
            $this->finish($status);

            return;
        }

        $status = $state->finalStatus();

        if ($status === null) {
            $this->finish(exception: $state->finalFailure());

            return;
        }

        if (! $this->retryEligible($state, $status)) {
            $this->finish($status);

            return;
        }

        $this->clearPendingPayload();
        $this->cancellationStatus = new Status(
            StatusCode::Cancelled,
            'The gRPC call was canceled.',
        );
        $this->finish($this->cancellationStatus);
    }

    /**
     * Resolve and cache a unary response for every concurrent waiter.
     */
    protected function waitForUnaryResponse(): Message
    {
        if ($this->unaryResponseResolved) {
            return $this->resolvedUnaryResponse();
        }

        $completionSemaphore = $this->completionSemaphore ??= new Channel(1);

        if (! $completionSemaphore->push(true)) {
            if ($completionSemaphore->isCanceled()) {
                throw new CanceledException('Waiting to resolve the unary gRPC response was canceled.');
            }

            return $this->resolvedUnaryResponse();
        }

        try {
            try {
                $firstPayload = $this->retainNextPayload();

                if ($firstPayload === null) {
                    throw new ProtocolException('A unary gRPC response must contain exactly one message.');
                }

                if ($this->nextPayload() !== null) {
                    throw new ProtocolException('A unary gRPC response cannot contain multiple messages.');
                }

                $this->unaryResponse = $this->deserializePendingPayload();
            } catch (CanceledException $throwable) {
                throw $throwable;
            } catch (Throwable $throwable) {
                if ($throwable instanceof ProtocolException) {
                    $this->storeFailure($throwable);
                }

                $this->unaryResponseFailure = $throwable;
            }

            $this->unaryResponseResolved = true;
            $this->closeCompletionSemaphore();
        } finally {
            $completionSemaphore->pop(0.0);
        }

        return $this->resolvedUnaryResponse();
    }

    /**
     * Read and deserialize the next response message.
     */
    protected function readResponse(): ?Message
    {
        $payload = $this->retainNextPayload();

        return $payload === null ? null : $this->deserializePendingPayload();
    }

    /**
     * Claim this call's single streaming response reader.
     */
    protected function beginReading(): void
    {
        if ($this->reading) {
            throw new LogicException('A gRPC response stream supports only one active reader.');
        }

        $this->reading = true;
    }

    /**
     * Release this call's streaming response reader.
     */
    protected function endReading(): void
    {
        $this->reading = false;
    }

    /**
     * Configure client-streaming request writes.
     */
    protected function configureWriter(
        Connection $connection,
        FrameEncoder $requestEncoder,
        Compression $requestCompression,
    ): void {
        if ($this->writeSemaphore !== null) {
            throw new LogicException('The gRPC request writer is already configured.');
        }

        if ($this->retryPolicy !== null) {
            throw new LogicException('A retryable gRPC call cannot expose streaming request writes.');
        }

        $this->writeConnection = $connection;
        $this->requestEncoder = $requestEncoder;
        $this->requestCompression = $requestCompression;
        $this->writeSemaphore = new Channel(1);
    }

    /**
     * Write one client-streaming request message.
     */
    protected function writeRequest(Message $message): void
    {
        if ($this->writesDone) {
            throw new LogicException('The gRPC request stream has already been closed.');
        }

        $writeSemaphore = $this->writeSemaphore ?? throw new LogicException(
            'This gRPC call does not support streaming request writes.',
        );

        if (! $writeSemaphore->push(true)) {
            if ($writeSemaphore->isCanceled()) {
                throw new CanceledException('Waiting to write a gRPC request was canceled.');
            }

            $this->throwCompletedWrite();

            throw new LogicException('The gRPC request stream has already been closed.');
        }

        try {
            $this->throwCompletedWrite();

            try {
                $frame = ($this->requestEncoder ?? throw new LogicException(
                    'The gRPC request encoder is unavailable.',
                ))->encode(
                    MessageSerializer::serialize($message),
                    $this->requestCompression,
                );
            } catch (RpcException $exception) {
                $this->state->failWithStatus($exception->status());
                $this->finish($exception->status());

                throw $this->rpcException($this->state, $exception->status());
            } catch (ProtocolException $exception) {
                $this->storeFailure($exception);

                throw $exception;
            }

            try {
                ($this->writeConnection ?? throw new LogicException(
                    'The gRPC request connection is unavailable.',
                ))->write($this->state, $frame, false, $this->deadline);
            } catch (RpcException) {
                $this->throwCompletedWrite();
            } catch (LogicException $exception) {
                $this->throwCompletedWrite();

                throw $exception;
            } catch (Throwable $throwable) {
                $this->storeFailure($throwable);

                throw $throwable;
            }
        } finally {
            $writeSemaphore->pop(0.0);
        }
    }

    /**
     * Half-close the client-streaming request body.
     */
    protected function finishWrites(): void
    {
        if ($this->writesDone) {
            return;
        }

        $writeSemaphore = $this->writeSemaphore ?? throw new LogicException(
            'This gRPC call does not support streaming request writes.',
        );

        if (! $writeSemaphore->push(true)) {
            if ($writeSemaphore->isCanceled()) {
                throw new CanceledException('Waiting to finish gRPC request writes was canceled.');
            }

            return;
        }

        try {
            $this->writesDone = true;

            try {
                if ($this->throwFailedCompletion()) {
                    return;
                }

                ($this->writeConnection ?? throw new LogicException(
                    'The gRPC request connection is unavailable.',
                ))->write($this->state, '', true, $this->deadline);
            } catch (RpcException) {
                $this->throwFailedCompletion();
            } catch (LogicException $exception) {
                // The receiver can complete and release this stream while the
                // half-close waits for the connection send boundary.
                if ($this->throwFailedCompletion()) {
                    return;
                }

                throw $exception;
            } catch (Throwable $throwable) {
                $this->storeFailure($throwable);

                throw $throwable;
            } finally {
                $this->closeWriteSemaphore();
            }
        } finally {
            $writeSemaphore->pop(0.0);
        }
    }

    /**
     * Return the current transport attempt.
     */
    private function nextPayload(): ?string
    {
        while (true) {
            $this->throwStoredFailure();
            $state = $this->state;

            try {
                $payload = $state->nextMessage();
            } catch (Throwable $throwable) {
                $this->storeFailure($throwable);

                throw $throwable;
            }

            $this->throwStoredFailure();

            if ($state !== $this->state) {
                continue;
            }

            if ($payload !== null) {
                return $payload;
            }

            try {
                $status = $state->status();
            } catch (Throwable $throwable) {
                $this->storeFailure($throwable);

                throw $throwable;
            }

            if ($this->retry($state, $status)) {
                continue;
            }

            $status = $this->resolveStatus($status);
            $this->finish($status);

            if (! $status->isOk()) {
                throw $this->rpcException($state, $status);
            }

            return null;
        }
    }

    /**
     * Retain the next payload until its deserialization succeeds.
     */
    private function retainNextPayload(): ?string
    {
        if ($this->hasPendingPayload) {
            return $this->pendingPayload;
        }

        $payload = $this->nextPayload();

        if ($payload !== null) {
            $this->pendingPayload = $payload;
            $this->hasPendingPayload = true;
        }

        return $payload;
    }

    /**
     * Deserialize and release the retained response payload.
     */
    private function deserializePendingPayload(): Message
    {
        if (! $this->hasPendingPayload) {
            throw new LogicException('The gRPC response payload is unavailable.');
        }

        $message = $this->deserialize($this->pendingPayload);
        $this->clearPendingPayload();

        return $message;
    }

    /**
     * Release the retained response payload.
     */
    private function clearPendingPayload(): void
    {
        $this->hasPendingPayload = false;
        $this->pendingPayload = '';
    }

    /**
     * Transition one eligible trailers-only failure to the next attempt.
     */
    private function retry(StreamState $state, Status $status): bool
    {
        // A stale identity read only costs one lock acquisition. Attempt counters and
        // eligibility must stay under the semaphore so observers cannot see a half-published retry.
        if ($state !== $this->state) {
            return true;
        }

        $attemptSemaphore = $this->attemptSemaphore;

        if ($attemptSemaphore === null) {
            return false;
        }

        if (! $attemptSemaphore->push(true)) {
            if ($attemptSemaphore->isCanceled()) {
                throw new CanceledException('Waiting to retry the gRPC call was canceled.');
            }

            $this->throwStoredFailure();

            return $state !== $this->state;
        }

        $backoffCheckpoint = null;

        try {
            if ($state !== $this->state) {
                return true;
            }

            if ($this->isLogicallyCanceled()) {
                return false;
            }

            if (! $this->retryEligible($state, $status)) {
                $this->closeAttemptSemaphore();

                return false;
            }

            $backoffCheckpoint = $this->retryBackoff?->checkpoint();
            $delay = $this->retryDelay($state);

            if ($delay === null) {
                $this->closeAttemptSemaphore();

                return false;
            }

            if ($delay > 0) {
                // The channel lets logical call cancellation wake this wait. Sleep cannot
                // be interrupted without cancelling the coroutine that owns the retry.
                $retryDelayChannel = $this->retryDelayChannel ??= new Channel(1);

                if (! $retryDelayChannel->pop($delay) && $retryDelayChannel->isCanceled()) {
                    throw new CanceledException('Waiting to retry the gRPC call was canceled.');
                }
            }

            if ($this->isLogicallyCanceled()) {
                $this->retryBackoff?->restore($backoffCheckpoint);

                return false;
            }

            $previousAttempts = $this->attempts;
            $replacementState = ($this->attemptFactory ?? throw new LogicException(
                'The retryable gRPC call has no attempt factory.',
            ))($previousAttempts);

            if ($this->isLogicallyCanceled()) {
                $this->retryBackoff?->restore($backoffCheckpoint);
                $replacementState->abandonIfIncomplete();

                return false;
            }

            $this->state = $replacementState;
            ++$this->attempts;

            return true;
        } catch (CanceledException $throwable) {
            if ($backoffCheckpoint !== null) {
                $this->retryBackoff?->restore($backoffCheckpoint);
            }

            throw $throwable;
        } catch (Throwable $throwable) {
            if ($this->isLogicallyCanceled()) {
                if ($backoffCheckpoint !== null) {
                    $this->retryBackoff?->restore($backoffCheckpoint);
                }

                return false;
            }

            $this->storeFailure($throwable);

            throw $throwable;
        } finally {
            $attemptSemaphore->pop(0.0);
        }
    }

    /**
     * Determine whether the observed final status can be replayed.
     */
    private function retryEligible(StreamState $state, Status $status): bool
    {
        return $this->retryPolicy !== null
            && $this->attempts < $this->retryPolicy->maxAttempts
            && ! $state->committed()
            && $state->trailersOnly()
            && in_array($status->code(), $this->retryPolicy->retryableStatusCodes, true);
    }

    /**
     * Resolve peer pushback or the next jittered retry delay.
     */
    private function retryDelay(StreamState $state): ?float
    {
        $remainingSeconds = $this->deadline->remainingSeconds();
        $backoff = $this->retryBackoff ?? throw new LogicException(
            'The retryable gRPC call has no backoff calculator.',
        );

        if (! $state->hasRetryPushback()) {
            return $backoff->nextDelay($remainingSeconds);
        }

        $pushback = $state->retryPushback();

        if ($pushback === null || ($delay = $backoff->pushbackDelay($pushback)) === null) {
            return null;
        }

        return $remainingSeconds === null ? $delay : min($delay, $remainingSeconds);
    }

    /**
     * Resolve the logical call status over one transport attempt's status.
     */
    private function resolveStatus(Status $status): Status
    {
        return $this->cancellationStatus ?? $status;
    }

    /**
     * Determine whether this call was canceled during a retry transition.
     *
     * @phpstan-impure Another coroutine may cancel the call while this one is suspended.
     */
    private function isLogicallyCanceled(): bool
    {
        return $this->cancellationStatus !== null;
    }

    /**
     * Deserialize one buffered response payload.
     */
    private function deserialize(string $payload): Message
    {
        try {
            return MessageSerializer::deserialize($this->deserialize, $payload);
        } catch (ProtocolException $exception) {
            $this->storeFailure($exception);

            throw $exception;
        }
    }

    /**
     * Return a completed unary result or rethrow its cached failure.
     */
    private function resolvedUnaryResponse(): Message
    {
        if (! $this->unaryResponseResolved) {
            throw new LogicException('The unary gRPC response has not been resolved.');
        }

        if ($this->unaryResponseFailure !== null) {
            throw $this->unaryResponseFailure;
        }

        return $this->unaryResponse ?? throw new LogicException(
            'The resolved unary gRPC response is unavailable.',
        );
    }

    /**
     * Throw the terminal response when a request write can no longer proceed.
     */
    private function throwCompletedWrite(): void
    {
        if ($this->throwFailedCompletion()) {
            throw new LogicException('The gRPC call has already completed.');
        }
    }

    /**
     * Throw any terminal failure and return whether the call completed successfully.
     */
    private function throwFailedCompletion(): bool
    {
        $this->throwStoredFailure();

        if (! $this->state->isComplete()) {
            return false;
        }

        try {
            $status = $this->state->status();
        } catch (Throwable $throwable) {
            $this->storeFailure($throwable);

            throw $throwable;
        }

        $this->finish($status);

        if (! $status->isOk()) {
            throw $this->rpcException($this->state, $status);
        }

        return true;
    }

    /**
     * Store one transport or protocol failure and abandon incomplete native work.
     */
    private function storeFailure(Throwable $failure): void
    {
        if ($failure instanceof CanceledException) {
            throw $failure;
        }

        $this->failure ??= $failure;
        $this->clearPendingPayload();
        $this->state->fail($this->failure, abandon: ! $this->state->isComplete());
        $this->finish(exception: $this->failure);
    }

    /**
     * Rethrow the call's stored transport or protocol failure.
     */
    private function throwStoredFailure(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    /**
     * Build a metadata-rich RPC exception for this call.
     */
    private function rpcException(StreamState $state, Status $status): RpcException
    {
        return RpcException::fromCall(
            $status,
            $state->metadata(),
            $state->trailers(),
            $this->method,
            $this->peer,
        );
    }

    /**
     * Normalize a validated deserializer to a storable callable shape.
     *
     * @param array{class-string<Message>, string}|callable(string): Message $deserialize
     * @return array{class-string<Message>, string}|Closure(string): Message
     */
    private function normalizeDeserializer(array|callable $deserialize): array|Closure
    {
        if (
            is_array($deserialize)
            && count($deserialize) === 2
            && ($deserialize[1] ?? null) === 'decode'
        ) {
            return $deserialize;
        }

        return Closure::fromCallable($deserialize);
    }

    /**
     * Close call-owned synchronization once no transition remains possible.
     */
    private function finish(?Status $status = null, ?Throwable $exception = null): void
    {
        // Several terminal paths can observe the same logical result, but its
        // synchronization and operation observer must be completed only once.
        if ($this->finished) {
            return;
        }

        $this->finished = true;
        $this->closeRetryDelayChannel();
        $this->closeAttemptSemaphore();
        $this->closeWriteSemaphore();
        $this->operationHandle?->finish(new GrpcOperationResult(
            $status,
            $exception,
            $this->attempts,
        ));
    }

    /**
     * Wake and close the retry delay channel.
     */
    private function closeRetryDelayChannel(): void
    {
        if ($this->retryDelayChannel === null || $this->retryDelayChannel->isClosing()) {
            return;
        }

        $this->retryDelayChannel->close();
    }

    /**
     * Close the retry transition semaphore.
     */
    private function closeAttemptSemaphore(): void
    {
        if ($this->attemptSemaphore === null || $this->attemptSemaphoreClosed) {
            return;
        }

        $this->attemptSemaphoreClosed = true;
        $this->attemptSemaphore->close();
    }

    /**
     * Close the unary completion semaphore after publishing its result.
     */
    private function closeCompletionSemaphore(): void
    {
        if ($this->completionSemaphore === null || $this->completionSemaphoreClosed) {
            return;
        }

        $this->completionSemaphoreClosed = true;
        $this->completionSemaphore->close();
    }

    /**
     * Close the request-write semaphore after half-close or terminal completion.
     */
    private function closeWriteSemaphore(): void
    {
        $this->writesDone = true;

        if ($this->writeSemaphore === null || $this->writeSemaphoreClosed) {
            return;
        }

        $this->writeSemaphoreClosed = true;
        $this->writeSemaphore->close();
    }

    /**
     * Release unfinished native call resources.
     */
    public function __destruct()
    {
        try {
            $this->state->abandonIfIncomplete();
        } catch (Throwable) {
            // Destructors cannot safely surface cleanup failures during process shutdown.
        }
    }
}
