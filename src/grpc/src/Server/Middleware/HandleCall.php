<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Server\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Google\Protobuf\Internal\Message;
use Hypervel\Coordinator\Timer;
use Hypervel\Engine\Coroutine;
use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Exceptions\ProtocolException;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Protocol\Deadline;
use Hypervel\Grpc\Protocol\FrameDecoder;
use Hypervel\Grpc\Protocol\MessageSerializer;
use Hypervel\Grpc\Protocol\MetadataCodec;
use Hypervel\Grpc\Protocol\Timeout;
use Hypervel\Grpc\Server\CallContextStore;
use Hypervel\Grpc\Server\GrpcStreamedResponse;
use Hypervel\Grpc\Server\ResponseFactory;
use Hypervel\Grpc\Server\ServerCallContext;
use Hypervel\Grpc\StatusCode;
use Hypervel\Http\Request;
use Hypervel\Routing\Route;
use InvalidArgumentException;
use LogicException;
use Swoole\Coroutine\CanceledException;

/**
 * Decode and scope one supported inbound gRPC call.
 *
 * @internal
 */
class HandleCall
{
    public function __construct(
        private readonly CallContextStore $contexts,
        private readonly Timer $timer,
        private readonly int $maxReceiveMessageSize,
        private readonly Compression $preferredCompression,
    ) {
        if ($maxReceiveMessageSize <= 0) {
            throw new InvalidArgumentException('The gRPC receive message limit must be positive.');
        }
    }

    /**
     * Handle one decoded gRPC request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            throw new LogicException('A gRPC call requires a matched route.');
        }

        $grpc = $route->getAction('_grpc');

        if (! is_array($grpc)
            || ! is_string($grpc['service'] ?? null)
            || ! is_string($grpc['method'] ?? null)
            || ! is_string($grpc['request_parameter'] ?? null)
            || ! is_string($grpc['request_class'] ?? null)
            || ! is_a($grpc['request_class'], Message::class, true)) {
            throw new LogicException('The matched route has an invalid gRPC protocol marker.');
        }

        $requestEncoding = $this->requestEncoding($request);
        $responseCompression = $this->responseCompression($request);
        $request->attributes->set(ResponseFactory::COMPRESSION_ATTRIBUTE, $responseCompression);
        $metadata = MetadataCodec::decode($request->headers->all());
        [$deadline, $wallDeadline] = $this->deadline($request);
        $previousAttempts = $this->previousAttempts($request);
        $decoder = new FrameDecoder($requestEncoding, $this->maxReceiveMessageSize);
        $payload = null;
        $messageCount = 0;

        foreach ($decoder->push($request->getContent()) as $messagePayload) {
            ++$messageCount;

            if ($messageCount === 1) {
                $payload = $messagePayload;
            }
        }

        $decoder->finish();

        if ($messageCount !== 1 || $payload === null) {
            throw new ProtocolException('A supported gRPC server call requires exactly one request message.');
        }

        $requestClass = $grpc['request_class'];
        $message = MessageSerializer::deserialize([$requestClass, 'decode'], $payload);
        $route->setParameter($grpc['request_parameter'], $message);
        $context = new ServerCallContext(
            $metadata,
            $grpc['service'],
            $grpc['method'],
            $this->peer($request),
            $wallDeadline,
            $deadline,
            $previousAttempts,
        );

        if ($context->deadlineExceeded()) {
            throw $this->deadlineFailure();
        }

        $this->contexts->set($context);
        $timerId = null;
        $streaming = false;

        try {
            if (($remaining = $context->timeRemaining()) !== null) {
                $handlerId = Coroutine::id();
                $timerId = $this->timer->after(
                    $remaining,
                    static function (bool $closing) use ($handlerId): void {
                        if (! $closing) {
                            Coroutine::cancelById($handlerId, true);
                        }
                    },
                );
            }

            try {
                $response = $next($request);
            } catch (CanceledException $exception) {
                if (! $context->deadlineExceeded()) {
                    throw $exception;
                }

                return $this->deadlineFailure();
            }

            if ($context->deadlineExceeded()) {
                $response instanceof GrpcStreamedResponse && $response->complete();

                return $this->deadlineFailure();
            }

            if ($response instanceof GrpcStreamedResponse) {
                $response->completeUsing(function () use ($timerId): void {
                    $this->completeCall($timerId);
                });
                $streaming = true;
            }

            return $response;
        } finally {
            if (! $streaming) {
                $this->completeCall($timerId);
            }
        }
    }

    /**
     * Release the timer and coroutine-local state for a completed call.
     */
    private function completeCall(?int $timerId): void
    {
        try {
            if ($timerId !== null) {
                $this->timer->clear($timerId);
            }
        } finally {
            $this->contexts->forget();
        }
    }

    /**
     * Parse the request message encoding.
     */
    private function requestEncoding(Request $request): Compression
    {
        $encoding = $this->singletonHeader($request, 'grpc-encoding');

        if ($encoding === null || strtolower($encoding) === Compression::Identity->value) {
            return Compression::Identity;
        }

        if (strtolower($encoding) === Compression::Gzip->value) {
            return Compression::Gzip;
        }

        throw new RpcException(
            StatusCode::Unimplemented,
            "The gRPC message encoding [{$encoding}] is not supported.",
        );
    }

    /**
     * Negotiate the response message compression.
     */
    private function responseCompression(Request $request): Compression
    {
        if ($this->preferredCompression === Compression::Identity) {
            return Compression::Identity;
        }

        $acceptedValues = $request->headers->all('grpc-accept-encoding');

        if ($acceptedValues === []) {
            return Compression::Identity;
        }

        foreach (explode(',', strtolower(implode(',', $acceptedValues))) as $encoding) {
            if (trim($encoding, " \t") === $this->preferredCompression->value) {
                return $this->preferredCompression;
            }
        }

        return Compression::Identity;
    }

    /**
     * Parse the optional peer deadline and its wall-clock projection.
     *
     * @return array{Deadline, null|CarbonImmutable}
     */
    private function deadline(Request $request): array
    {
        $timeout = $this->singletonHeader($request, 'grpc-timeout');

        if ($timeout === null) {
            return [Deadline::fromTimeout(null), null];
        }

        try {
            $deadline = Deadline::fromPeerTimeout(Timeout::decode($timeout));
        } catch (InvalidArgumentException $exception) {
            throw new ProtocolException('The grpc-timeout request header is malformed.', previous: $exception);
        }

        $remaining = $deadline->remainingSeconds();

        return [
            $deadline,
            CarbonImmutable::now()->addMicroseconds((int) ceil($remaining * 1_000_000)),
        ];
    }

    /**
     * Parse the number of completed previous RPC attempts.
     */
    private function previousAttempts(Request $request): int
    {
        $value = $this->singletonHeader($request, 'grpc-previous-rpc-attempts');

        if ($value === null) {
            return 0;
        }

        if (preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new ProtocolException('The grpc-previous-rpc-attempts request header is malformed.');
        }

        $normalized = ltrim($value, '0') ?: '0';
        $maximum = (string) PHP_INT_MAX;

        if (strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            throw new ProtocolException('The grpc-previous-rpc-attempts request header is too large.');
        }

        return (int) $normalized;
    }

    /**
     * Return one transport-observable singleton header value.
     */
    private function singletonHeader(Request $request, string $name): ?string
    {
        $values = $request->headers->all($name);

        if ($values === []) {
            return null;
        }

        if (count($values) !== 1) {
            throw new ProtocolException("The {$name} request header must appear exactly once.");
        }

        return $values[0];
    }

    /**
     * Build the normalized remote peer address.
     */
    private function peer(Request $request): string
    {
        $address = (string) $request->server->get('REMOTE_ADDR', '');
        $port = $request->server->get('REMOTE_PORT');

        if ($port === null || $port === '') {
            return $address;
        }

        return (str_contains($address, ':') ? "[{$address}]" : $address) . ':' . $port;
    }

    /**
     * Build the local deadline failure.
     */
    private function deadlineFailure(): RpcException
    {
        return new RpcException(StatusCode::DeadlineExceeded, 'The gRPC deadline was exceeded.');
    }
}
