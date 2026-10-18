<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\TransferStats;
use Hypervel\Events\CallQueuedListener;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\RequestException;
use Hypervel\Http\Client\Response;
use Hypervel\Notifications\Events\NotificationDelivered;
use Hypervel\Notifications\Events\NotificationFailed;
use Hypervel\Notifications\Events\NotificationSent;
use Hypervel\Notifications\Notification;
use Hypervel\Tests\Notifications\Fixtures\LegacySerializableTransportException;
use Hypervel\Tests\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Symfony\Component\Mailer\Exception\TransportException;
use Throwable;
use TypeError;

class NotificationTransportSerializationTest extends TestCase
{
    #[DataProvider('successEvents')]
    public function testResponseApisAndSharedTransportStateSurviveQueueSerialization(string $eventClass, bool $wrapped): void
    {
        $request = (new Request('POST', 'https://slack.test/original', ['X-Request' => 'fixture'], '{"text":"hello"}'))
            ->withoutHeader('Host');
        $message = new PsrResponse(429, ['Mixed-CASE' => ['one', 'two']], '{"ok":false,"large":12345678901234567890123}', '1.0', 'Custom reason');
        $response = new NotificationTransportResponse($message);
        $response->cookies = CookieJar::fromArray(['session' => 'fixture'], 'slack.test');
        $response->transferStats = new TransferStats($request, $message, 0.125, null, ['primary_ip' => '127.0.0.1']);
        $response->json(flags: JSON_BIGINT_AS_STRING);
        $message->getBody()->seek(3);
        $request->getBody()->seek(2);

        $original = $wrapped ? $response : $message;
        $restored = $this->roundTrip(new $eventClass(new stdClass, new Notification, 'slack', $original))->response;
        $restoredMessage = $wrapped ? $restored->toPsrResponse() : $restored;

        $this->assertSame($original::class, $restored::class);
        $this->assertSame($message->getHeaders(), $restoredMessage->getHeaders());
        $this->assertSame(429, $restoredMessage->getStatusCode());
        $this->assertSame('1.0', $restoredMessage->getProtocolVersion());
        $this->assertSame('Custom reason', $restoredMessage->getReasonPhrase());
        $this->assertSame(3, $message->getBody()->tell());
        $this->assertSame(3, $restoredMessage->getBody()->tell());
        $this->assertSame(substr('{"ok":false,"large":12345678901234567890123}', 3), $restoredMessage->getBody()->getContents());

        if ($wrapped) {
            $this->assertSame('application state', $restored->applicationValue());
            $this->assertSame('12345678901234567890123', $restored->json('large', flags: JSON_BIGINT_AS_STRING));
            $this->assertSame($response->cookies->toArray(), $restored->cookies()->toArray());
            $this->assertSame($response->handlerStats(), $restored->handlerStats());
            $this->assertSame(0.125, $restored->transferStats->getTransferTime());
            $this->assertSame($restoredMessage, $restored->transferStats->getResponse());
            $restoredRequest = $restored->transferStats->getRequest();
            $this->assertFalse($restoredRequest->hasHeader('Host'));
            $this->assertSame($request->getHeaders(), $restoredRequest->getHeaders());
            $this->assertSame(2, $restoredRequest->getBody()->tell());
            $this->assertSame('/changed', $restoredRequest->withUri(new Uri('https://slack.test/changed'))->getRequestTarget());
            $this->assertSame('/original', $request->getRequestTarget());
            $this->assertSame(2, $request->getBody()->tell());
            $this->assertSame('https://slack.test/original', (string) $restored->effectiveUri());
        }
    }

    /**
     * Provide both terminal success events and channel response types.
     */
    public static function successEvents(): array
    {
        return [
            'sent HTTP client' => [NotificationSent::class, true],
            'sent webhook' => [NotificationSent::class, false],
            'delivered HTTP client' => [NotificationDelivered::class, true],
            'delivered webhook' => [NotificationDelivered::class, false],
        ];
    }

    public function testExplicitRequestTargetsAndLazyBodiesRemainExplicitAndLazy(): void
    {
        $request = (new Request('POST', 'https://slack.test/original'))->withRequestTarget('/explicit');
        $response = new Response(new PsrResponse);
        $response->transferStats = new TransferStats($request);
        $restored = $this->roundTrip(new NotificationSent(new stdClass, new Notification, 'slack', $response))->response;

        $this->assertSame('/explicit', $restored->transferStats->getRequest()->withUri(new Uri('https://slack.test/changed'))->getRequestTarget());
        $this->assertSame('', $restored->body());
        $this->assertSame('', (string) $restored->transferStats->getRequest()->getBody());
    }

    #[DataProvider('failures')]
    public function testFailureExceptionsRetainTheirTypesAndTransportApis(string $kind): void
    {
        $request = new Request('POST', 'https://slack.test', [], '{"text":"hello"}');
        $message = new PsrResponse(429, ['Retry-After' => '5'], '{"error":"ratelimited"}');
        $response = new Response($message);
        $transport = new ConnectException('Connection refused', $request);
        $exception = match ($kind) {
            'HTTP client' => new RequestException($response),
            'webhook' => GuzzleRequestException::create($request, $message),
            'connection' => new ConnectionException('Connection refused', 0, $transport),
            'webhook connection' => $transport,
            'application wrapper' => new NotificationTransportException('application state', new ConnectionException('Connection refused', 0, $transport)),
        };
        if ($exception instanceof NotificationTransportException) {
            $exception->related = $exception;
        }
        $restored = $this->roundTrip(new NotificationFailed(new stdClass, new Notification, 'slack', ['exception' => $exception]))->data['exception'];

        $this->assertSame($exception::class, $restored::class);
        $this->assertSame($exception->getMessage(), $restored->getMessage());
        $this->assertSame($exception->getCode(), $restored->getCode());
        $this->assertSame($exception->getFile(), $restored->getFile());
        $this->assertSame($exception->getLine(), $restored->getLine());
        $this->assertSame($this->withoutTraceArguments($exception), $restored->getTrace());

        if ($restored instanceof RequestException) {
            $this->assertSame(429, $restored->response->status());
            $this->assertSame('ratelimited', $restored->response->json('error'));
        } elseif ($restored instanceof GuzzleRequestException) {
            $this->assertSame(429, $restored->getResponse()->getStatusCode());
            $this->assertSame('{"text":"hello"}', (string) $restored->getRequest()->getBody());
        } elseif ($restored instanceof ConnectException) {
            $this->assertSame('{"text":"hello"}', (string) $restored->getRequest()->getBody());
        } else {
            $this->assertSame($exception->getPrevious()::class, $restored->getPrevious()::class);
            if ($restored instanceof NotificationTransportException) {
                $this->assertSame('application state', $restored->applicationValue());
                $this->assertSame($restored, $restored->related);
                $restored = $restored->getPrevious();
            }
            $this->assertInstanceOf(ConnectException::class, $restored->getPrevious());
            $this->assertSame('{"text":"hello"}', (string) $restored->getPrevious()->getRequest()->getBody());
        }
    }

    /**
     * Provide response and connection failures from both channel transports.
     */
    public static function failures(): array
    {
        return array_map(static fn (string $kind): array => [$kind], [
            'HTTP client', 'webhook', 'connection', 'webhook connection', 'application wrapper',
        ]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTraceArgumentsAreOmittedWithoutMutatingTheOriginalException(): void
    {
        ini_set('zend.exception_ignore_args', '0');
        $exception = $this->sensitiveFailure('fixture secret');
        $trace = $exception->getTrace();
        $restored = $this->roundTrip(new NotificationFailed(new stdClass, new Notification, 'slack', ['exception' => $exception]))->data['exception'];

        $this->assertArrayHasKey('args', $trace[0]);
        $this->assertSame($trace, $exception->getTrace());
        $this->assertSame($this->withoutTraceArguments($exception), $restored->getTrace());
        $this->assertSame($exception->getMessage(), $restored->getMessage());
    }

    #[IgnoreDeprecations('LegacySerializableTransportException implements the Serializable interface')]
    public function testNativeExceptionSerializationContractsAreRespected(): void
    {
        foreach ([new NotificationTransportCustomException('custom'), new LegacySerializableTransportException('legacy')] as $exception) {
            $restored = $this->roundTrip(new NotificationFailed(new stdClass, new Notification, 'slack', ['exception' => $exception]))->data['exception'];

            $this->assertSame($exception::class, $restored::class);
            $this->assertTrue($restored->restored);
            $this->assertFalse($exception->restored);
            $this->assertSame($exception->getMessage(), $restored->getMessage());
        }
    }

    public function testOrdinaryExceptionsAndUnrelatedRecursiveDataRemainUsable(): void
    {
        $data = ['message' => 'kept'];
        $data['recursive'] = &$data;
        $exception = new TransportException('SMTP failure', 17);
        $exception->appendDebug('SMTP diagnostic');
        $event = new NotificationFailed(new stdClass, new Notification, 'mail', ['exception' => $exception, 'details' => $data]);
        $restored = $this->roundTrip($event);

        $this->assertSame(TransportException::class, $restored->data['exception']::class);
        $this->assertSame('SMTP diagnostic', $restored->data['exception']->getDebug());
        $this->assertSame(17, $restored->data['exception']->getCode());
        $this->assertSame('kept', $restored->data['details']['recursive']['recursive']['message']);
        $this->assertSame('kept', $event->data['details']['message']);
        $ordinary = $this->roundTrip(new NotificationSent(new stdClass, new Notification, 'database', ['id' => 7]));
        $this->assertSame(['id' => 7], $ordinary->response);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCustomWakeupHooksRetainNativeOrderingAndRunOnce(): void
    {
        ini_set('zend.exception_ignore_args', '1');

        foreach ([NotificationTransportWakingException::class, NotificationTransportInheritedWakingException::class] as $exceptionClass) {
            $exception = new $exceptionClass('outer', 0, new $exceptionClass('inner'));
            $native = unserialize(serialize($exception));
            $restored = $this->roundTrip(new NotificationFailed(new stdClass, new Notification, 'custom', ['exception' => $exception]))->data['exception'];

            $this->assertSame($native::class, $restored::class);
            $this->assertTrue($native->previousWasRestored);
            $this->assertSame($native->previousWasRestored, $restored->previousWasRestored);
            $this->assertSame(1, $restored->wakeups);
            $this->assertSame(1, $restored->getPrevious()->wakeups);
            $this->assertTrue($restored->getPrevious()->restored);
            $this->assertFalse($exception->restored);
            $this->assertFalse($exception->getPrevious()->restored);
            $this->assertSame(0, $exception->wakeups);
        }
    }

    public function testCustomResponseWakeupHooksRemainNative(): void
    {
        $response = new NotificationTransportWakingResponse(new PsrResponse);
        $native = unserialize(serialize($response));
        $restored = $this->roundTrip(new NotificationSent(new stdClass, new Notification, 'custom', $response))->response;

        $this->assertSame($native::class, $restored::class);
        $this->assertSame(1, $native->wakeups);
        $this->assertSame($native->wakeups, $restored->wakeups);
        $this->assertSame($native->status(), $restored->status());
        $this->assertSame(0, $response->wakeups);
    }

    public function testNativeErrorsRetainTheirBehavior(): void
    {
        $error = new TypeError('native error');
        $restored = $this->roundTrip(new NotificationFailed(new stdClass, new Notification, 'slack', ['exception' => $error]))->data['exception'];
        $this->assertSame(TypeError::class, $restored::class);
        $this->assertSame('native error', $restored->getMessage());
        $this->assertSame($error->getFile(), $restored->getFile());
        $this->assertSame($error->getLine(), $restored->getLine());
    }

    public function testCustomJsonDecoderRemainsAvailableAfterRestoration(): void
    {
        $prefix = 'decoded:';
        $response = new Response(new PsrResponse(200, [], '{"text":"hello"}'));
        $response->decodeUsing(static function (string $body, bool $asObject) use ($prefix): array|object {
            $data = ['text' => $prefix . json_decode($body, true)['text']];

            return $asObject ? (object) $data : $data;
        });
        $restored = $this->roundTrip(new NotificationSent(new stdClass, new Notification, 'slack', $response))->response;

        $this->assertSame('decoded:hello', $restored->json('text'));
        $this->assertSame('decoded:hello', $restored->object()->text);
        $this->assertSame('decoded:hello', $response->json('text'));
    }

    #[DataProvider('bufferStates')]
    public function testBufferMetadataPositionAndEofStateArePreserved(string $uri, bool $eof): void
    {
        $resource = fopen($uri, 'w+');
        fwrite($resource, 'abcdef');
        $stream = new Stream($resource, ['metadata' => [0 => 'retained index', 'fixture' => 'retained', 'uri' => 'custom origin']]);
        $stream->rewind();
        $eof ? $stream->getContents() : $stream->seek(2);
        $position = $stream->tell();
        $restored = $this->roundTrip(new NotificationSent(new stdClass, new Notification, 'slack', new PsrResponse(200, [], $stream)))->response->getBody();

        $this->assertSame($position, $stream->tell());
        $this->assertSame($position, $restored->tell());
        $this->assertSame($eof, $stream->eof());
        $this->assertSame($eof, $restored->eof());
        $this->assertSame($stream->getMetadata(), $restored->getMetadata());
        $this->assertSame(6, $restored->getSize());
        $this->assertTrue($restored->isWritable());
        $restored->seek(2);
        $restored->write('XY');
        $this->assertSame('abXYef', (string) $restored);
        $this->assertSame('abcdef', (string) $stream);
    }

    /**
     * Provide each default buffer kind before and after reaching EOF.
     */
    public static function bufferStates(): array
    {
        return [['php://memory', false], ['php://memory', true], ['php://temp', false], ['php://temp', true]];
    }

    #[DataProvider('closedStreams')]
    public function testClosedAndDetachedStreamsRetainTheirCapabilityFlags(string $operation): void
    {
        $stream = Utils::streamFor('body');
        $resource = $stream->{$operation}();
        try {
            $restored = $this->roundTrip(new NotificationSent(new stdClass, new Notification, 'slack', new PsrResponse(200, [], $stream)))->response->getBody();
            $this->assertSame(Stream::class, $restored::class);
            $this->assertFalse($restored->isReadable());
            $this->assertFalse($restored->isWritable());
            $this->assertFalse($restored->isSeekable());
            $this->assertSame([], $restored->getMetadata());
            $this->assertNull($restored->getSize());
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageIs('Stream is detached');
            $restored->getContents();
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    /**
     * Provide the two normal ways to release a stream resource.
     */
    public static function closedStreams(): array
    {
        return [['close'], ['detach']];
    }

    public function testNonseekableBodiesAreNotConsumedBySerialization(): void
    {
        $stream = new NoSeekStream(Utils::streamFor('unread body'));
        $event = new NotificationSent(new stdClass, new Notification, 'slack', new PsrResponse(200, [], $stream));

        try {
            $this->expectException(LogicException::class);
            serialize($event);
        } finally {
            $this->assertSame(0, $stream->tell());
            $stream->close();
        }
    }

    #[DataProvider('unsupportedBuffers')]
    public function testUnsupportedBufferOriginsAndCapabilitiesAreRejectedWithoutConsumption(bool $fileBacked): void
    {
        $resource = $fileBacked ? tmpfile() : fopen('php://memory', 'r');
        if ($fileBacked) {
            fwrite($resource, 'unread body');
        }
        $stream = new Stream($resource, ['metadata' => ['uri' => 'php://memory']]);
        $stream->seek(2);
        $position = $stream->tell();
        $eof = $stream->eof();
        $event = new NotificationSent(new stdClass, new Notification, 'custom', new PsrResponse(200, [], $stream));

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessageIs('Cannot serialize notification transport bodies outside writable memory buffers.');
            serialize($event);
        } finally {
            $this->assertSame($position, $stream->tell());
            $this->assertSame($eof, $stream->eof());
            $stream->close();
        }
    }

    /**
     * Provide a disguised file-backed buffer and a read-only memory buffer.
     */
    public static function unsupportedBuffers(): array
    {
        return [[true], [false]];
    }

    /**
     * Serialize and restore an actual queued listener payload.
     */
    private function roundTrip(NotificationSent|NotificationDelivered|NotificationFailed $event): object
    {
        return unserialize(serialize(new CallQueuedListener(stdClass::class, 'handle', [$event])))->data[0];
    }

    /**
     * Return the exception trace without argument values.
     */
    private function withoutTraceArguments(Throwable $exception): array
    {
        $trace = $exception->getTrace();
        foreach ($trace as &$frame) {
            unset($frame['args']);
        }

        return $trace;
    }

    /**
     * Create an exception whose trace includes an unserializable sensitive argument.
     */
    private function sensitiveFailure(#[SensitiveParameter] string $secret): RuntimeException
    {
        return new RuntimeException('Connection refused');
    }
}

class NotificationTransportResponse extends Response
{
    private readonly string $applicationValue;

    /**
     * Create a response with application-owned subclass state.
     */
    public function __construct(ResponseInterface $response)
    {
        parent::__construct($response);
        $this->applicationValue = 'application state';
    }

    /**
     * Return the retained subclass state.
     */
    public function applicationValue(): string
    {
        return $this->applicationValue;
    }
}

class NotificationTransportException extends RuntimeException
{
    public ?self $related = null;

    /**
     * Create an application exception around a transport failure.
     */
    public function __construct(private readonly string $applicationValue, Throwable $previous)
    {
        parent::__construct('Application failure', 19, $previous);
    }

    /**
     * Return the retained subclass state.
     */
    public function applicationValue(): string
    {
        return $this->applicationValue;
    }
}

class NotificationTransportCustomException extends RuntimeException
{
    public bool $restored = false;

    /**
     * Serialize the exception using its own contract.
     */
    public function __serialize(): array
    {
        return ['message' => $this->getMessage()];
    }

    /**
     * Restore the custom exception state.
     */
    public function __unserialize(array $state): void
    {
        $this->message = $state['message'];
        $this->restored = true;
    }
}

class NotificationTransportWakingException extends RuntimeException
{
    public bool $restored = false;

    public bool $previousWasRestored = false;

    public int $wakeups = 0;

    /**
     * Run the native exception wakeup contract and record its invocation.
     */
    public function __wakeup(): void
    {
        parent::__wakeup();
        /** @var null|self $previous */
        $previous = $this->getPrevious();
        $this->previousWasRestored = $previous === null || $previous->restored;
        $this->restored = true;
        ++$this->wakeups;
    }
}

class NotificationTransportInheritedWakingException extends NotificationTransportWakingException
{
}

class NotificationTransportWakingResponse extends Response
{
    public int $wakeups = 0;

    /**
     * Record invocation of the native response wakeup hook.
     */
    public function __wakeup(): void
    {
        ++$this->wakeups;
    }
}
