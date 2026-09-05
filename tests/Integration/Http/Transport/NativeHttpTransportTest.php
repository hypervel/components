<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Transport;

use Hypervel\Http\Client\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Coroutine\parallel;

class NativeHttpTransportTest extends TransportIntegrationTestCase
{
    protected int $serverPort = 19530;

    public function testAutoUsesNativeAndCurlUsesTheFallback(): void
    {
        $factory = $this->factory();

        $native = $factory->transport('auto')->get($this->serverUrl('/up'));
        $fallback = $factory->transport('curl')->get($this->serverUrl('/up'));

        $this->assertSame('ok', $native->body());
        $this->assertSame('swoole', $native->handlerStats()['transport']);
        $this->assertSame('ok', $fallback->body());
        $this->assertSame('guzzle', $fallback->handlerStats()['transport']);
    }

    public function testNativeTransportPreservesLiteralResponseEvidence(): void
    {
        $response = $this->factory()
            ->transport('swoole')
            ->get($this->serverUrl('/mixed-headers'));

        $headers = $response->headers();

        $this->assertSame('headers', $response->body());
        $this->assertArrayHasKey('X-MiXeD-CaSe', $headers);
        $this->assertSame(['preserved'], $headers['X-MiXeD-CaSe']);
        $this->assertSame(['first', 'second'], $response->toPsrResponse()->getHeader('X-Repeated'));
        $this->assertSame([
            'first=one; Path=/',
            'second=two; Path=/',
        ], $response->toPsrResponse()->getHeader('Set-Cookie'));
        $this->assertSame('swoole', $response->handlerStats()['transport']);
    }

    public function testNativeTransportResetsCookiesAndReusesTheConnection(): void
    {
        $factory = $this->factory();
        $factory->setDefaultTransport('swoole');

        $firstConnection = $factory->get($this->serverUrl('/connection-id'))->body();
        $cookieResponse = $factory
            ->withHeaders(['Cookie' => 'session=first'])
            ->get($this->serverUrl('/echo'));
        $cleanResponse = $factory->get($this->serverUrl('/echo'));
        $secondConnection = $factory->get($this->serverUrl('/connection-id'))->body();

        $this->assertSame('session=first', $cookieResponse->json('cookie'));
        $this->assertNull($cleanResponse->json('cookie'));
        $this->assertSame($firstConnection, $secondConnection);
    }

    public function testNativeTransportResetsCompressionAcrossPooledRequests(): void
    {
        $factory = $this->factory();

        $decoded = $factory->transport('swoole')->get($this->serverUrl('/compressed'));
        $encoded = $factory
            ->transport('swoole')
            ->withOptions(['decode_content' => false])
            ->get($this->serverUrl('/compressed'));
        $decodedAgain = $factory
            ->transport('swoole')
            ->withOptions(['decode_content' => 'gzip'])
            ->get($this->serverUrl('/compressed'));

        $this->assertSame('compressed response', $decoded->body());
        $this->assertSame('gzip', $decoded->header('x-encoded-content-encoding'));
        $this->assertSame('', $decoded->header('Content-Encoding'));
        $this->assertSame('gzip', $encoded->header('Content-Encoding'));
        $this->assertSame('compressed response', gzdecode($encoded->body()));
        $this->assertSame('compressed response', $decodedAgain->body());
        $this->assertSame('gzip', $decodedAgain->header('x-encoded-content-encoding'));
    }

    public function testNativeTransportPreservesBinaryBodies(): void
    {
        $response = $this->factory()
            ->transport('swoole')
            ->get($this->serverUrl('/binary'));

        $this->assertSame("\x00\x01Hypervel\xff", $response->body());
    }

    public function testNativeTransportCannotFabricateACustomWireReasonPhrase(): void
    {
        $response = $this->factory()
            ->transport('swoole')
            ->get($this->serverUrl('/custom-reason'));

        $this->assertSame(299, $response->status());
        $this->assertSame('', $response->toPsrResponse()->getReasonPhrase());
    }

    #[DataProvider('nativeFailureProvider')]
    public function testNativeFailureIsNormalizedAndDoesNotPoisonThePool(
        string $path,
        array $options,
    ): void {
        $factory = $this->factory();
        $exception = null;

        try {
            $factory
                ->transport('swoole')
                ->withOptions($options)
                ->get($this->serverUrl($path));
        } catch (ConnectionException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(ConnectionException::class, $exception);
        $this->assertSame(
            'ok',
            $factory->transport('swoole')->get($this->serverUrl('/up'))->body(),
        );
    }

    public static function nativeFailureProvider(): array
    {
        return [
            'read timeout' => ['/delay?milliseconds=200', ['timeout' => 0.05]],
            'pre-header reset' => ['/reset', []],
            'truncated response' => ['/truncated', []],
        ];
    }

    public function testNativePoolBoundsRealConcurrentRequests(): void
    {
        $factory = $this->factory();
        $factory->setDefaultTransport('swoole');
        $factory->setDefaultPoolOptions([
            'max_objects' => 2,
            'wait_timeout' => 1.0,
        ]);

        $responses = parallel(array_fill(
            0,
            6,
            fn (): string => $factory
                ->get($this->serverUrl('/delay?milliseconds=50'))
                ->body(),
        ));

        $this->assertSame(array_fill(0, 6, 'ok'), $responses);
    }

    public function testNativePoolExhaustionIsNormalizedWithoutLeakingTheLease(): void
    {
        $factory = $this->factory();
        $factory->setDefaultTransport('swoole');
        $factory->setDefaultPoolOptions([
            'max_objects' => 1,
            'wait_timeout' => 0.05,
        ]);

        [$first, $second] = parallel([
            fn (): string => $factory
                ->get($this->serverUrl('/delay?milliseconds=500'))
                ->body(),
            function () use ($factory): ConnectionException|string {
                usleep(100_000);

                try {
                    return $factory->get($this->serverUrl('/up'))->body();
                } catch (ConnectionException $exception) {
                    return $exception;
                }
            },
        ]);

        $this->assertSame('ok', $first);
        $this->assertInstanceOf(ConnectionException::class, $second);
        $this->assertSame('ok', $factory->get($this->serverUrl('/up'))->body());
    }
}
