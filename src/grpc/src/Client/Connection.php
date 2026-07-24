<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Client;

use Closure;
use Hypervel\Contracts\Engine\Http\V2\ClientFactoryInterface;
use Hypervel\Contracts\Engine\Http\V2\ClientInterface;
use Hypervel\Contracts\Engine\Http\V2\ResponseInterface;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Grpc\Exceptions\ConnectionException;
use Hypervel\Grpc\Exceptions\ProtocolException;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Protocol\Deadline;
use Hypervel\Grpc\Status;
use Hypervel\Grpc\StatusCode;
use LogicException;
use Throwable;

/**
 * @internal
 */
final class Connection
{
    private ?ClientInterface $client = null;

    private Channel $sendSemaphore;

    private bool $sendSemaphoreClosed = false;

    private ?StreamState $pendingState = null;

    /** @var array<int, StreamState> */
    private array $activeStates = [];

    /** @var array<int, true> */
    private array $abandonedStreamIds = [];

    private bool $receiving = false;

    private ?int $receiverCoroutineId = null;

    private bool $retiring = false;

    // Swoole coroutines switch cooperatively, so each control-transfer boundary rechecks this flag.
    private bool $closed = false;

    private bool $retirementNotified = false;

    /** @var null|Closure(self): void */
    private ?Closure $onRetired;

    /**
     * Create a reusable HTTP/2 connection slot.
     *
     * @param array<string, mixed> $settings
     * @param null|Closure(self): void $onRetired
     */
    public function __construct(
        private readonly ClientFactoryInterface $clientFactory,
        private readonly Endpoint $endpoint,
        private readonly float $connectTimeout,
        private readonly float $writeTimeout,
        private readonly array $settings = [],
        ?Closure $onRetired = null,
    ) {
        $this->sendSemaphore = new Channel(1);
        $this->onRetired = $onRetired;
    }

    /**
     * Start one HTTP/2 stream and route its response asynchronously.
     */
    public function start(Closure $requestFactory, StreamState $state, Deadline $deadline): void
    {
        if (! $this->acquireSendSemaphore($deadline)) {
            if ($deadline->expired()) {
                $state->failWithStatus($this->deadlineStatus());

                return;
            }

            $state->fail($this->closedException());

            return;
        }

        try {
            if ($this->closed || $this->retiring) {
                $state->fail($this->closedException());

                return;
            }

            if ($deadline->expired()) {
                $state->failWithStatus($this->deadlineStatus());

                return;
            }

            try {
                $client = $this->connectedClient($deadline);
            } catch (RpcException $exception) {
                $state->failWithStatus($exception->status());

                return;
            } catch (Throwable $throwable) {
                $failure = $this->connectionException(
                    $throwable,
                    'Unable to connect to the gRPC server.',
                );

                if ($deadline->expired()) {
                    $state->failWithStatus($this->deadlineStatus());
                    $this->terminateWhileLocked($failure);
                } else {
                    $state->fail($this->terminateWhileLocked($failure));
                }

                return;
            }

            if ($deadline->expired()) {
                $state->failWithStatus($this->deadlineStatus());

                return;
            }

            try {
                $request = $requestFactory();
            } catch (RpcException $exception) {
                $state->failWithStatus($exception->status());

                return;
            }

            if ($this->pendingState !== null) {
                $failure = $this->terminateWhileLocked(new ConnectionException(
                    $this->endpoint->peer,
                    'The gRPC connection already has a pending stream registration.',
                ));
                $state->fail($failure);

                return;
            }

            $state->onAbandon($this->abandonState(...));
            $this->pendingState = $state;

            try {
                $streamId = $client->send($request, $this->operationTimeout($deadline));
            } catch (Throwable $throwable) {
                $failure = $this->connectionException(
                    $throwable,
                    'Unable to start the gRPC call.',
                );

                if ($deadline->expired()) {
                    $state->failWithStatus($this->deadlineStatus());
                }

                $this->terminateWhileLocked($failure);

                return;
            }

            $attachedStreamId = $state->streamId();

            if ($attachedStreamId === null) {
                $this->attachState($state, $streamId);
            } elseif ($attachedStreamId !== $streamId) {
                $failure = new ConnectionException(
                    $this->endpoint->peer,
                    "The HTTP/2 stream identifier mismatch [{$attachedStreamId}] != [{$streamId}].",
                );

                // Only the pending attachment is untrusted. Correctly matched completed
                // streams on the same connection retain their settled results.
                $state->discardAndFail($failure);
                $this->terminateWhileLocked($failure);

                return;
            }

            $this->pendingState = null;

            if ($deadline->expired() && ! $state->isComplete()) {
                $state->failWithStatus($this->deadlineStatus());

                if ($this->closed) {
                    return;
                }

                if ($this->activeStates === []) {
                    $this->terminateWhileLocked(new ConnectionException(
                        $this->endpoint->peer,
                        'The gRPC connection was retired after a call deadline elapsed.',
                    ));

                    return;
                }
            }

            try {
                $this->startReceiver();
            } catch (Throwable $throwable) {
                $this->terminateWhileLocked($this->connectionException(
                    $throwable,
                    'Unable to start the gRPC response receiver.',
                ));
            }
        } finally {
            $this->releaseSendSemaphore();
        }
    }

    /**
     * Write framed data to an accepted HTTP/2 stream.
     */
    public function write(
        StreamState $state,
        string $frame,
        bool $end,
        Deadline $deadline,
    ): void {
        if (! $this->acquireSendSemaphore($deadline)) {
            if ($deadline->expired()) {
                $state->failWithStatus($this->deadlineStatus());

                throw $this->deadlineException();
            }

            throw $this->closedException();
        }

        try {
            if ($this->closed) {
                throw $this->closedException();
            }

            if ($deadline->expired()) {
                $state->failWithStatus($this->deadlineStatus());

                throw $this->deadlineException();
            }

            $streamId = $state->streamId();

            if ($streamId === null || ($this->activeStates[$streamId] ?? null) !== $state) {
                throw new LogicException('The gRPC call no longer owns an active HTTP/2 stream.');
            }

            $client = $this->client;

            if ($client === null || ! $client->isConnected()) {
                $failure = new ConnectionException(
                    $this->endpoint->peer,
                    'The gRPC connection is no longer connected.',
                );

                throw $this->terminateWhileLocked($failure);
            }

            try {
                $client->write(
                    $streamId,
                    $frame,
                    $end,
                    $this->operationTimeout($deadline),
                );
            } catch (Throwable $throwable) {
                $failure = $this->connectionException(
                    $throwable,
                    'Unable to write the gRPC request message.',
                );

                if ($deadline->expired()) {
                    $state->failWithStatus($this->deadlineStatus());
                    $this->terminateWhileLocked($failure);

                    throw $this->deadlineException();
                }

                throw $this->terminateWhileLocked($failure);
            }

            if ($deadline->expired() && ! $state->isComplete()) {
                $state->failWithStatus($this->deadlineStatus());

                throw $this->deadlineException();
            }
        } finally {
            $this->releaseSendSemaphore();
        }
    }

    /**
     * Determine whether this connection can accept a new call.
     */
    public function isAccepting(): bool
    {
        return ! $this->closed && ! $this->retiring;
    }

    /**
     * Determine whether this connection has terminated.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Close the connection and fail every incomplete call.
     */
    public function close(): void
    {
        if ($this->closed || ! $this->acquireSendSemaphore()) {
            return;
        }

        try {
            if ($this->closed) {
                return;
            }

            $failure = new ConnectionException(
                $this->endpoint->peer,
                'The gRPC connection was closed.',
            );
            $terminalFailure = $this->terminateWhileLocked($failure);

            if ($terminalFailure !== $failure) {
                throw $terminalFailure;
            }
        } finally {
            $this->releaseSendSemaphore();
        }
    }

    /**
     * Resolve an existing connected engine client or create one lazily.
     */
    private function connectedClient(Deadline $deadline): ClientInterface
    {
        if ($this->client !== null && $this->client->isConnected()) {
            return $this->client;
        }

        if ($this->activeStates !== []) {
            throw new ConnectionException(
                $this->endpoint->peer,
                'The multiplexed gRPC connection disconnected with active calls.',
            );
        }

        $remainingSeconds = $deadline->remainingSeconds();

        if ($remainingSeconds === 0.0) {
            throw $this->deadlineException();
        }

        $settings = [
            'connect_timeout' => $remainingSeconds === null
                ? $this->connectTimeout
                : min($this->connectTimeout, $remainingSeconds),
            'write_timeout' => $remainingSeconds === null
                ? $this->writeTimeout
                : min($this->writeTimeout, $remainingSeconds),
            ...$this->settings,
        ];

        $this->client = $this->clientFactory->make(
            $this->endpoint->host,
            $this->endpoint->port,
            $this->endpoint->tls,
            $settings,
        );

        return $this->client;
    }

    /**
     * Attach a state to its native stream identifier.
     */
    private function attachState(StreamState $state, int $streamId): void
    {
        $state->attachStream($streamId);

        if ($state->isAbandoned()) {
            $this->abandonedStreamIds[$streamId] = true;
            $this->retiring = true;

            return;
        }

        $this->activeStates[$streamId] = $state;
    }

    /**
     * Start the one receive coroutine when this connection has work.
     */
    private function startReceiver(): void
    {
        if (
            $this->receiving
            || $this->closed
            || ($this->activeStates === [] && $this->abandonedStreamIds === [])
        ) {
            return;
        }

        $this->receiving = true;

        try {
            $receiver = Coroutine::create($this->receive(...));
            $receiverCoroutineId = $receiver->getId();

            // create() may run the receiver to completion before returning, so only
            // publish its ID while that receiver is still active.
            if (Coroutine::exists($receiverCoroutineId)) {
                $this->receiverCoroutineId = $receiverCoroutineId;
            }
        } catch (Throwable $throwable) {
            $this->receiving = false;
            $this->receiverCoroutineId = null;

            throw $throwable;
        }
    }

    /**
     * Receive and route response events until the connection becomes quiescent.
     */
    private function receive(): void
    {
        try {
            while (! $this->closed) {
                try {
                    $client = $this->client;

                    if ($client === null) {
                        return;
                    }

                    $response = $client->recv(1.0);

                    if ($this->isClosed()) {
                        return;
                    }

                    if ($response !== null) {
                        $this->handleResponse($response);
                    }

                    if ($this->isClosed()) {
                        return;
                    }

                    $this->auditStreams($client);
                } catch (Throwable $throwable) {
                    if ($this->isClosed()) {
                        return;
                    }

                    $this->failConnection($this->connectionException(
                        $throwable,
                        'The gRPC response receiver failed.',
                    ));

                    return;
                }

                if ($this->closeRetiredConnectionIfIdle()) {
                    return;
                }

                if (
                    $this->pendingState === null
                    && $this->activeStates === []
                    && $this->abandonedStreamIds === []
                ) {
                    return;
                }
            }
        } finally {
            // This is the authoritative signal that the connection no longer owns a receiver.
            $this->receiverCoroutineId = null;
            $this->receiving = false;
        }
    }

    /**
     * Route one response event to its active or pending state.
     */
    private function handleResponse(ResponseInterface $response): void
    {
        $streamId = $response->getStreamId();
        $state = $this->activeStates[$streamId] ?? null;

        if ($state === null && isset($this->abandonedStreamIds[$streamId])) {
            if ($response->isEndStream()) {
                unset($this->abandonedStreamIds[$streamId]);
            }

            return;
        }

        if ($state === null) {
            $state = $this->pendingState;

            if ($state === null || $state->streamId() !== null) {
                throw new ConnectionException(
                    $this->endpoint->peer,
                    "The peer returned an unsolicited HTTP/2 stream [{$streamId}].",
                );
            }

            $this->attachState($state, $streamId);

            if ($state->isAbandoned()) {
                if ($response->isEndStream()) {
                    unset($this->abandonedStreamIds[$streamId]);
                }

                return;
            }
        }

        $wasRetiring = $this->retiring;

        try {
            $state->handle($response);
        } catch (ProtocolException $exception) {
            $state->fail($exception, abandon: ! $response->isEndStream());
        }

        if ($this->isClosed()) {
            return;
        }

        if (! $response->isEndStream()) {
            return;
        }

        unset($this->activeStates[$streamId], $this->abandonedStreamIds[$streamId]);

        if (! $wasRetiring && $this->abandonedStreamIds === []) {
            $this->retiring = false;
        }
    }

    /**
     * Expire deadlines and detect native streams removed by peer reset.
     */
    private function auditStreams(ClientInterface $client): void
    {
        $activeStates = $this->activeStates;

        foreach ($activeStates as $streamId => $state) {
            if ($this->closed) {
                return;
            }

            if (($this->activeStates[$streamId] ?? null) !== $state) {
                continue;
            }

            if ($state->expireIfNeeded()) {
                if ($this->isClosed()) {
                    return;
                }

                continue;
            }

            if (! $client->isStreamOpen($streamId)) {
                unset($this->activeStates[$streamId]);
                $state->fail(new ConnectionException(
                    $this->endpoint->peer,
                    "The peer closed HTTP/2 stream [{$streamId}].",
                ));

                if ($this->isClosed()) {
                    return;
                }
            }
        }

        foreach (array_keys($this->abandonedStreamIds) as $streamId) {
            if (! $client->isStreamOpen($streamId)) {
                unset($this->abandonedStreamIds[$streamId]);
            }
        }
    }

    /**
     * Mark a locally terminal stream for connection retirement.
     */
    private function abandonState(StreamState $state): void
    {
        $streamId = $state->streamId();

        if ($streamId !== null && ($this->activeStates[$streamId] ?? null) === $state) {
            unset($this->activeStates[$streamId]);
            $this->abandonedStreamIds[$streamId] = true;
        }

        $this->retiring = true;
    }

    /**
     * Close a retiring connection once no healthy accepted stream remains.
     */
    private function closeRetiredConnectionIfIdle(): bool
    {
        if (
            ! $this->retiring
            || $this->closed
            || $this->pendingState !== null
            || $this->activeStates !== []
        ) {
            return false;
        }

        if (! $this->acquireSendSemaphore()) {
            return $this->closed;
        }

        try {
            if (
                ! $this->retiring
                || $this->closed
                || $this->pendingState !== null
                || $this->activeStates !== []
            ) {
                return $this->closed;
            }

            $failure = new ConnectionException(
                $this->endpoint->peer,
                'The retired gRPC connection was closed.',
            );
            $terminalFailure = $this->terminateWhileLocked($failure);

            if ($terminalFailure !== $failure) {
                throw $terminalFailure;
            }

            return true;
        } finally {
            $this->releaseSendSemaphore();
        }
    }

    /**
     * Fail the whole connection from outside the serialized send section.
     */
    private function failConnection(ConnectionException $failure): void
    {
        if (! $this->acquireSendSemaphore()) {
            return;
        }

        try {
            if (! $this->closed) {
                $this->terminateWhileLocked($failure);
            }
        } finally {
            $this->releaseSendSemaphore();
        }
    }

    /**
     * Terminate native state while the send semaphore is held.
     */
    private function terminateWhileLocked(ConnectionException $failure): ConnectionException
    {
        if ($this->closed) {
            return $failure;
        }

        $this->closed = true;
        $this->retiring = true;
        $client = $this->client;
        $pendingState = $this->pendingState;
        $activeStates = $this->activeStates;
        $this->client = null;
        $this->pendingState = null;
        $this->activeStates = [];
        $this->abandonedStreamIds = [];

        if (! $this->sendSemaphoreClosed) {
            $this->sendSemaphore->close();
            $this->sendSemaphoreClosed = true;
        }

        // Publishing the closed state first makes observer-triggered re-entrant close calls no-ops.
        $pendingState?->fail($failure);

        foreach ($activeStates as $state) {
            $state->fail($failure);
        }

        // cancelById() can clear the ownership property before yielding deferred cleanup
        // finishes. The captured ID remains required to join the still-live receiver.
        $receiverCoroutineId = $this->receiverCoroutineId;

        if ($receiverCoroutineId !== null && $receiverCoroutineId !== Coroutine::id()) {
            Coroutine::cancelById($receiverCoroutineId);

            // Native close must wait for physical receiver and engine-client cleanup.
            // A bounded join could still leave the socket owned when its timeout elapsed.
            Coroutine::join([$receiverCoroutineId]);
        }

        if ($client !== null) {
            try {
                if ($client->isConnected()) {
                    $client->close();
                }
            } catch (Throwable $throwable) {
                $failure = new ConnectionException(
                    $this->endpoint->peer,
                    $failure->getMessage() . ' The HTTP/2 client also failed to close: '
                        . ($throwable->getMessage() ?: 'unknown transport failure'),
                    $throwable->getCode() !== 0 ? $throwable->getCode() : null,
                    $failure,
                );
            }
        }

        $this->notifyRetired();

        return $failure;
    }

    /**
     * Acquire exclusive access to connection writes and terminal state.
     *
     * @phpstan-impure
     */
    private function acquireSendSemaphore(?Deadline $deadline = null): bool
    {
        while (! $this->closed && ! $this->sendSemaphoreClosed) {
            $remainingSeconds = $deadline?->remainingSeconds();

            if ($remainingSeconds === 0.0) {
                return false;
            }

            if ($this->sendSemaphore->push(true, $remainingSeconds ?? -1)) {
                return true;
            }

            if ($deadline === null || $deadline->expired()) {
                return false;
            }
        }

        return false;
    }

    /**
     * Release exclusive connection access.
     */
    private function releaseSendSemaphore(): void
    {
        $this->sendSemaphore->pop(0.0);
    }

    /**
     * Notify the owning client that a retiring connection has terminated.
     */
    private function notifyRetired(): void
    {
        if ($this->retirementNotified || ! $this->retiring || $this->onRetired === null) {
            return;
        }

        $this->retirementNotified = true;
        ($this->onRetired)($this);
        $this->onRetired = null;
    }

    /**
     * Normalize a lower transport failure for this endpoint.
     */
    private function connectionException(Throwable $throwable, string $fallback): ConnectionException
    {
        if ($throwable instanceof ConnectionException) {
            return $throwable;
        }

        return new ConnectionException(
            $this->endpoint->peer,
            $throwable->getMessage() ?: $fallback,
            $throwable->getCode() !== 0 ? $throwable->getCode() : null,
            $throwable,
        );
    }

    /**
     * Return the native write timeout bounded by the current call deadline.
     */
    private function operationTimeout(Deadline $deadline): float
    {
        $remainingSeconds = $deadline->remainingSeconds();

        if ($remainingSeconds === 0.0) {
            throw $this->deadlineException();
        }

        return $remainingSeconds === null
            ? $this->writeTimeout
            : min($this->writeTimeout, $remainingSeconds);
    }

    /**
     * Create the local deadline status.
     */
    private function deadlineStatus(): Status
    {
        return new Status(
            StatusCode::DeadlineExceeded,
            'The gRPC deadline was exceeded.',
        );
    }

    /**
     * Create the local deadline exception.
     */
    private function deadlineException(): RpcException
    {
        return new RpcException(
            StatusCode::DeadlineExceeded,
            'The gRPC deadline was exceeded.',
        );
    }

    /**
     * Create the terminal connection-state exception.
     */
    private function closedException(): ConnectionException
    {
        return new ConnectionException(
            $this->endpoint->peer,
            'The gRPC connection is closed and cannot accept more work.',
        );
    }
}
