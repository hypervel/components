<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Server;

use Composer\InstalledVersions;
use Generator;
use Google\Protobuf\Internal\Message;
use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Metadata;
use Hypervel\Grpc\Protocol\FrameEncoder;
use Hypervel\Grpc\Protocol\MediaType;
use Hypervel\Grpc\Protocol\MessageSerializer;
use Hypervel\Grpc\Protocol\MetadataCodec;
use Hypervel\Grpc\Protocol\StatusCodec;
use Hypervel\Grpc\Status;
use Hypervel\Grpc\StatusCode;
use Hypervel\Http\Request;
use Hypervel\Routing\Route;
use InvalidArgumentException;
use LogicException;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use UnexpectedValueException;

/**
 * Convert service values and failures into protocol-owned gRPC responses.
 *
 * @internal
 */
class ResponseFactory
{
    /** @internal */
    public const COMPRESSION_ATTRIBUTE = '_grpc.response_compression';

    /** @var list<string> */
    private const STATUS_TRAILER_NAMES = [
        'grpc-status',
        'grpc-message',
        'grpc-status-details-bin',
    ];

    private const MAX_SERVER_FIELD_NAME_LENGTH = 127;

    private readonly FrameEncoder $frames;

    private readonly string $serverHeader;

    public function __construct(
        private readonly ExceptionMapper $exceptions,
        private readonly CallContextStore $contexts,
        int $maxSendMessageSize,
        private readonly int $maxMetadataSize,
    ) {
        if ($maxSendMessageSize <= 0 || $maxMetadataSize <= 0) {
            throw new InvalidArgumentException('The gRPC response size limits must be positive.');
        }

        $this->frames = new FrameEncoder($maxSendMessageSize);
        $this->serverHeader = self::serverHeader();

        if ($this->maxMetadataSize < self::minimumMetadataSize()) {
            throw new InvalidArgumentException(
                'The gRPC metadata limit is too small to emit a protocol error response.',
            );
        }
    }

    /**
     * Calculate the smallest metadata limit that preserves the compact fallback.
     *
     * @internal
     */
    public static function minimumMetadataSize(): int
    {
        return MetadataCodec::wireSize([
            ':status' => '200',
            'content-type' => MediaType::PROTOBUF,
            'grpc-accept-encoding' => 'identity,gzip',
            'server' => self::serverHeader(),
            'date' => gmdate('D, d M Y H:i:s \G\M\T'),
            ...StatusCodec::encode(new Status(
                StatusCode::ResourceExhausted,
                'The outbound gRPC metadata exceeds the configured limit.',
            )),
            'content-length' => '0',
        ]);
    }

    /**
     * Convert one service result according to its registered call shape.
     */
    public function make(Request $request, mixed $value): GrpcHttpResponse|GrpcStreamedResponse
    {
        if ($value instanceof RpcException) {
            return $this->trailersOnly($value);
        }

        $route = $request->route();

        if (! $route instanceof Route) {
            throw new LogicException('A gRPC response requires a matched route.');
        }

        $serverStreaming = $route->getAction('_grpc.server_streaming');

        if (! is_bool($serverStreaming)) {
            throw new LogicException('The matched route is missing its gRPC call-shape marker.');
        }

        $compression = $request->attributes->get(self::COMPRESSION_ATTRIBUTE);

        if (! $compression instanceof Compression) {
            throw new LogicException('The active gRPC call has no negotiated response compression.');
        }

        return $serverStreaming
            ? $this->streaming($value, $compression)
            : $this->unary($value, $compression);
    }

    /**
     * Build a trailers-only response for a failure outside route dispatch.
     *
     * @internal
     */
    public function error(RpcException $failure): GrpcHttpResponse
    {
        return $this->trailersOnly($failure);
    }

    /**
     * Validate the exact response state that will be emitted by the bridge.
     */
    public function finalizeForEmission(Response $response): GrpcHttpResponse|GrpcStreamedResponse
    {
        if (! $response instanceof GrpcHttpResponse && ! $response instanceof GrpcStreamedResponse) {
            return $this->invalidFinalResponse($response, 'Middleware replaced the protocol-owned gRPC response.');
        }

        if (! $response->protocolStateIsIntact()) {
            return $this->invalidFinalResponse($response, 'Middleware mutated the protocol-owned gRPC response.');
        }

        if (! $this->initialBlockFits($response)) {
            $response instanceof GrpcStreamedResponse && $response->complete();

            return $this->metadataLimitResponse();
        }

        if ($response instanceof GrpcHttpResponse && ! $this->trailerBlockFits($response->trailers())) {
            return $this->metadataLimitResponse();
        }

        return $response;
    }

    /**
     * Build a unary response.
     */
    private function unary(mixed $value, Compression $compression): GrpcHttpResponse
    {
        $initialMetadata = Metadata::make();
        $trailingMetadata = Metadata::make();

        if ($value instanceof GrpcResponse) {
            if ($value->isStreaming()) {
                return $this->invalidServiceResponse(
                    'A unary gRPC route returned a server-streaming response.',
                );
            }

            $message = $value->message();
            $initialMetadata = $value->initialMetadata();
            $trailingMetadata = $value->trailingMetadata();
        } elseif ($value instanceof Message) {
            $message = $value;
        } else {
            return $this->invalidServiceResponse(
                'A unary gRPC route did not return a Protocol Buffers message.',
            );
        }

        try {
            $frame = $this->encode($message, $compression);
        } catch (Throwable $throwable) {
            return $this->trailersOnly(
                $this->exceptions->map($throwable),
                $initialMetadata,
                $trailingMetadata,
            );
        }

        $status = new Status(StatusCode::Ok);
        $trailers = $this->trailers($status, $trailingMetadata);

        return new GrpcHttpResponse(
            $frame,
            $this->initialHeaders($initialMetadata, $compression),
            $this->trailerNames($trailingMetadata),
            $trailers,
        );
    }

    /**
     * Build a server-streaming response after priming exactly one item.
     */
    private function streaming(mixed $value, Compression $compression): GrpcHttpResponse|GrpcStreamedResponse
    {
        $initialMetadata = Metadata::make();
        $trailingMetadata = Metadata::make();

        if ($value instanceof GrpcResponse) {
            if (! $value->isStreaming()) {
                return $this->invalidServiceResponse(
                    'A server-streaming gRPC route returned a unary response.',
                );
            }

            $messages = $value->messages();
            $initialMetadata = $value->initialMetadata();
            $trailingMetadata = $value->trailingMetadata();
        } elseif (is_iterable($value)) {
            $messages = $value;
        } else {
            return $this->invalidServiceResponse(
                'A server-streaming gRPC route did not return an iterable of Protocol Buffers messages.',
            );
        }

        $context = $this->contexts->get();
        $iterator = (static function () use ($messages): iterable {
            yield from $messages;
        })();

        try {
            $iterator->rewind();

            if ($context->deadlineExceeded()) {
                throw $this->deadlineFailure();
            }
        } catch (Throwable $throwable) {
            return $this->trailersOnly(
                $this->mapStreamFailure($throwable, $context),
                $initialMetadata,
                $trailingMetadata,
            );
        }

        // Rewinding the yield-from wrapper invokes the inner iterator's rewind,
        // valid, and current callbacks; the outer calls below read cached state.
        if (! $iterator->valid()) {
            return $this->trailersOnly(
                null,
                $initialMetadata,
                $trailingMetadata,
            );
        }

        $firstMessage = $iterator->current();

        if (! $firstMessage instanceof Message) {
            return $this->invalidServiceResponse(
                'A server-streaming gRPC route yielded a value that is not a Protocol Buffers message.',
                $initialMetadata,
                $trailingMetadata,
            );
        }

        try {
            $firstFrame = $this->encode($firstMessage, $compression);

            if ($context->deadlineExceeded()) {
                throw $this->deadlineFailure();
            }
        } catch (Throwable $throwable) {
            return $this->trailersOnly(
                $this->mapStreamFailure($throwable, $context),
                $initialMetadata,
                $trailingMetadata,
            );
        }

        $failure = null;
        $chunks = $this->streamChunks(
            $iterator,
            $firstFrame,
            $compression,
            $context,
            $failure,
        );

        return new GrpcStreamedResponse(
            $chunks,
            $this->initialHeaders($initialMetadata, $compression),
            $this->trailerNames($trailingMetadata),
            function () use (&$failure, $trailingMetadata): array {
                $metadata = $failure === null
                    ? $trailingMetadata
                    : $trailingMetadata->merge($failure->trailers());
                $status = $failure?->status() ?? new Status(StatusCode::Ok);

                return $this->validatedTrailers(
                    $status,
                    $metadata,
                    $failure?->retryPushbackMilliseconds(),
                );
            },
        );
    }

    /**
     * Yield framed stream items while retaining the final failure as trailers.
     *
     * @param Generator<int, mixed, mixed, void> $iterator
     */
    private function streamChunks(
        Generator $iterator,
        string $firstFrame,
        Compression $compression,
        ServerCallContext $context,
        ?RpcException &$failure,
    ): iterable {
        if ($context->deadlineExceeded()) {
            $failure = $this->deadlineFailure();

            return;
        }

        yield $firstFrame;

        while (true) {
            try {
                $iterator->next();

                if ($context->deadlineExceeded()) {
                    throw $this->deadlineFailure();
                }

                if (! $iterator->valid()) {
                    return;
                }

                $message = $iterator->current();

                if (! $message instanceof Message) {
                    $failure = $this->exceptions->invalidResponse(new UnexpectedValueException(
                        'A server-streaming gRPC route yielded a value that is not a Protocol Buffers message.',
                    ));

                    return;
                }

                $frame = $this->encode($message, $compression);

                if ($context->deadlineExceeded()) {
                    throw $this->deadlineFailure();
                }
            } catch (Throwable $throwable) {
                $failure = $this->mapStreamFailure($throwable, $context);

                return;
            }

            yield $frame;
        }
    }

    /**
     * Build a true one-block Trailers-Only response.
     */
    private function trailersOnly(
        ?RpcException $failure,
        ?Metadata $initialMetadata = null,
        ?Metadata $trailingMetadata = null,
    ): GrpcHttpResponse {
        $metadata = ($initialMetadata ?? Metadata::make())
            ->merge($trailingMetadata ?? Metadata::make());

        if ($failure !== null) {
            $metadata = $metadata->merge($failure->trailers());
        }

        $status = $failure?->status() ?? new Status(StatusCode::Ok);
        $headers = [
            ...$this->baseHeaders(),
            ...MetadataCodec::encode($metadata),
            ...StatusCodec::encode($status),
        ];

        if (($pushback = $failure?->retryPushbackMilliseconds()) !== null) {
            $headers['grpc-retry-pushback-ms'] = (string) $pushback;
        }

        return new GrpcHttpResponse('', $headers, [], []);
    }

    /**
     * Build a reported Internal response for an invalid service value.
     */
    private function invalidServiceResponse(
        string $message,
        ?Metadata $initialMetadata = null,
        ?Metadata $trailingMetadata = null,
    ): GrpcHttpResponse {
        return $this->trailersOnly(
            $this->exceptions->invalidResponse(new UnexpectedValueException($message)),
            $initialMetadata,
            $trailingMetadata,
        );
    }

    /**
     * Build the initial response headers.
     */
    private function initialHeaders(Metadata $metadata, Compression $compression): array
    {
        $headers = [
            ...$this->baseHeaders(),
            ...MetadataCodec::encode($metadata),
        ];

        if ($compression !== Compression::Identity) {
            $headers['grpc-encoding'] = $compression->value;
        }

        return $headers;
    }

    /**
     * Build headers common to every valid gRPC response.
     */
    private function baseHeaders(): array
    {
        return [
            'content-type' => 'application/grpc+proto',
            'grpc-accept-encoding' => 'identity,gzip',
            'server' => $this->serverHeader,
            'date' => gmdate('D, d M Y H:i:s \G\M\T'),
        ];
    }

    /**
     * Build the stable server identity header.
     */
    private static function serverHeader(): string
    {
        $version = InstalledVersions::isInstalled('hypervel/grpc')
            ? InstalledVersions::getPrettyVersion('hypervel/grpc')
            : null;

        return 'grpc-php-hypervel/' . ($version ?? 'unknown');
    }

    /**
     * Encode one Protocol Buffers message frame.
     */
    private function encode(Message $message, Compression $compression): string
    {
        return $this->frames->encode(
            MessageSerializer::serialize($message),
            $compression,
        );
    }

    /**
     * Build final response trailers.
     */
    private function trailers(
        Status $status,
        Metadata $metadata,
        ?int $retryPushbackMilliseconds = null,
    ): array {
        $trailers = [
            ...MetadataCodec::encode($metadata),
            ...StatusCodec::encode($status),
        ];

        if ($retryPushbackMilliseconds !== null) {
            $trailers['grpc-retry-pushback-ms'] = (string) $retryPushbackMilliseconds;
        }

        return $trailers;
    }

    /**
     * Build the trailer announcement for metadata known before streaming.
     *
     * @return list<string>
     */
    private function trailerNames(Metadata $metadata): array
    {
        return [
            ...self::STATUS_TRAILER_NAMES,
            ...array_keys(MetadataCodec::encode($metadata)),
        ];
    }

    /**
     * Replace oversized dynamic trailers with a compact final status.
     */
    private function validatedTrailers(
        Status $status,
        Metadata $metadata,
        ?int $retryPushbackMilliseconds = null,
    ): array {
        $trailers = $this->trailers($status, $metadata, $retryPushbackMilliseconds);

        return $this->trailerBlockFits($trailers)
            ? $trailers
            : StatusCodec::encode($this->metadataLimitStatus());
    }

    /**
     * Determine whether the complete initial response block fits the configured limit.
     */
    private function initialBlockFits(GrpcHttpResponse|GrpcStreamedResponse $response): bool
    {
        $headers = [
            ':status' => '200',
            ...$response->headers->all(),
        ];
        $trailerNames = $response->trailerNames();

        if ($trailerNames !== []) {
            $headers['trailer'] = implode(', ', $trailerNames);
        }

        if ($response instanceof GrpcHttpResponse) {
            $headers['content-length'] = (string) strlen((string) $response->getContent());
        }

        return $this->fieldNamesFit($headers)
            && $this->fieldNamesFit(array_fill_keys($trailerNames, ''))
            && MetadataCodec::wireSize($headers) <= $this->maxMetadataSize;
    }

    /**
     * Determine whether one final trailer block fits the configured limit.
     *
     * @param array<string, string> $trailers
     */
    private function trailerBlockFits(array $trailers): bool
    {
        return $this->fieldNamesFit($trailers)
            && MetadataCodec::wireSize($trailers) <= $this->maxMetadataSize;
    }

    /**
     * Determine whether every outbound field name fits Swoole's server limit.
     *
     * @param array<string, mixed> $fields
     */
    private function fieldNamesFit(array $fields): bool
    {
        foreach (array_keys($fields) as $name) {
            if (strlen($name) > self::MAX_SERVER_FIELD_NAME_LENGTH) {
                return false;
            }
        }

        return true;
    }

    /**
     * Map one stream failure, preserving unrelated coroutine cancellation.
     */
    private function mapStreamFailure(
        Throwable $throwable,
        ServerCallContext $context,
    ): RpcException {
        if ($throwable instanceof CanceledException) {
            if ($context->deadlineExceeded()) {
                return $this->deadlineFailure();
            }

            throw $throwable;
        }

        return $this->exceptions->map($throwable);
    }

    /**
     * Build the local deadline failure.
     */
    private function deadlineFailure(): RpcException
    {
        return new RpcException(StatusCode::DeadlineExceeded, 'The gRPC deadline was exceeded.');
    }

    /**
     * Build the compact metadata-limit status.
     */
    private function metadataLimitStatus(): Status
    {
        return new Status(
            StatusCode::ResourceExhausted,
            'The outbound gRPC metadata exceeds the configured limit.',
        );
    }

    /**
     * Build the compact metadata-limit response.
     */
    private function metadataLimitResponse(): GrpcHttpResponse
    {
        return $this->trailersOnly(new RpcException(
            StatusCode::ResourceExhausted,
            'The outbound gRPC metadata exceeds the configured limit.',
        ));
    }

    /**
     * Report and replace a middleware-produced invalid response.
     */
    private function invalidFinalResponse(Response $response, string $message): GrpcHttpResponse
    {
        if ($response instanceof GrpcStreamedResponse) {
            $response->complete();
        }

        return $this->trailersOnly(
            $this->exceptions->invalidResponse(new UnexpectedValueException($message)),
        );
    }
}
