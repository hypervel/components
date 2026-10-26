<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Multiplexing;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\TransportSharing;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\Guzzle\HandlerFactory;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request as ServerRequest;
use Swoole\Http\Response as ServerResponse;
use Throwable;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

class HttpConnectionTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testConnectionReusesOneLowLevelHandlerAcrossFreshClientsAndConcurrentRequests(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->registerConnection('api');

        $firstHandler = $factory->getConnectionHandler('api');
        $secondHandler = $factory->getConnectionHandler('api');

        $this->assertSame($firstHandler, $secondHandler);
        $this->assertCount(1, $factory->createdHandlerOptions);

        $firstClient = $factory->connection('api')->buildClient();
        $secondClient = $factory->connection('api')->buildClient();

        $this->assertNotSame($firstClient, $secondClient);

        $responses = null;
        run(function () use ($factory, &$responses): void {
            $responses = parallel(array_map(
                fn (int $index) => fn () => $factory->connection('api')->get("https://example.com/{$index}")->body(),
                range(1, 10),
            ));
        });

        $this->assertSame(array_fill(0, 10, 'handler-1'), $responses);
        $this->assertCount(1, $factory->createdHandlerOptions);
        $this->assertCount(10, $factory->invocations);
    }

    #[DataProvider('pooledRequestOptions')]
    public function testBufferedRequestsReuseConnectionsAcrossConcurrentBursts(bool $named, bool $async, array $options): void
    {
        $bursts = [];
        $failure = null;

        run(function () use ($named, $async, $options, &$bursts, &$failure): void {
            $server = new Server('127.0.0.1', 0, false, false);
            $ready = new Channel(4);
            $arrived = 0;
            $factory = (new Factory)->registerConnection('api');

            $server->handle('/', static function (ServerRequest $request, ServerResponse $response) use ($ready, &$arrived): void {
                // Hold each burst until all four requests own an active connection.
                if (++$arrived % 4 === 0) {
                    for ($index = 0; $index < 4; ++$index) {
                        $ready->push(true);
                    }
                }

                if ($ready->pop(2) !== true) {
                    $response->status(503);
                    $response->end('Not all requests reached the barrier.');

                    return;
                }

                $response->end((string) $request->server['remote_port']);
            });
            Coroutine::create(fn (): bool => $server->start());
            $url = 'http://127.0.0.1:' . $server->port;

            try {
                for ($burst = 0; $burst < 2; ++$burst) {
                    $bursts[] = parallel(array_fill(0, 4, function () use ($factory, $named, $async, $options, $url): string {
                        $request = $named ? $factory->connection('api') : $factory->createPendingRequest();
                        $response = $request->async($async)
                            ->withOptions(['proxy' => '', 'version' => '1.1', ...$options])
                            ->timeout(3)->get($url);

                        return ($async ? $response->wait() : $response)->throw()->body();
                    }));
                }
            } catch (Throwable $exception) {
                $failure = $exception;
            } finally {
                $factory->forgetConnectionHandlers();
                $server->shutdown();
                $ready->close();
            }
        });

        if ($failure !== null) {
            throw $failure;
        }

        $this->assertCount(4, array_unique($bursts[0]));
        $this->assertCount(4, array_unique($bursts[1]));
        $this->assertCount(4, array_unique(array_merge(...$bursts)), 'The second burst opened another connection instead of reusing all four warm connections.');
    }

    /**
     * Provide default and named requests using both transports.
     */
    public static function pooledRequestOptions(): array
    {
        return [
            'default sync' => [false, false, []],
            'default async' => [false, true, []],
            'named sync' => [true, false, []],
            'named async' => [true, true, []],
            'explicit TLS version' => [true, false, ['crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT]],
        ];
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testEachRequestKeepsItsOwnMiddlewareStack(bool $named): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->registerConnection('api');

        ($named ? $factory->connection('api') : $factory->createPendingRequest())
            ->withRequestMiddleware(fn (RequestInterface $request) => $request->withHeader('X-Request', 'first'))
            ->get('https://example.com/first');

        ($named ? $factory->connection('api') : $factory->createPendingRequest())
            ->withRequestMiddleware(fn (RequestInterface $request) => $request->withHeader('X-Request', 'second'))
            ->get('https://example.com/second');

        $this->assertSame('first', $factory->invocations[0]['request']->getHeaderLine('X-Request'));
        $this->assertSame('second', $factory->invocations[1]['request']->getHeaderLine('X-Request'));
    }

    public function testRequestHandlerOverridesTheConnectionTransport(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->registerConnection('api', ['timeout' => 12]);
        $receivedOptions = null;

        $response = $factory->connection('api')
            ->setHandler(function (RequestInterface $request, array $options) use (&$receivedOptions): PromiseInterface {
                $receivedOptions = $options;

                return Create::promiseFor(new Psr7Response(200, [], 'custom'));
            })
            ->get('https://example.com');

        $this->assertSame('custom', $response->body());
        $this->assertSame(12, $receivedOptions['timeout']);
        $this->assertCount(0, $factory->createdHandlerOptions);
    }

    public function testOptionLayersHaveDeterministicPrecedenceRegardlessOfChainingOrder(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->globalOptions([
            'timeout' => 1,
            'headers' => ['X-Level' => 'global', 'X-Global' => 'yes'],
        ]);
        $factory->registerConnection('api', [
            'timeout' => 2,
            'headers' => ['X-Level' => 'preset', 'X-Preset' => 'yes'],
        ]);

        $factory->timeout(4)->connection('api', [
            'timeout' => 3,
            'headers' => ['X-Level' => 'override', 'X-Override' => 'yes'],
        ])->get('https://example.com/first');

        $factory->connection('api', [
            'timeout' => 3,
            'headers' => ['X-Level' => 'override', 'X-Override' => 'yes'],
        ])->timeout(4)->get('https://example.com/second');

        foreach ($factory->invocations as $invocation) {
            $this->assertSame(4, $invocation['options']['timeout']);
            $this->assertSame('override', $invocation['request']->getHeaderLine('X-Level'));
            $this->assertSame('yes', $invocation['request']->getHeaderLine('X-Global'));
            $this->assertSame('yes', $invocation['request']->getHeaderLine('X-Override'));
            $this->assertSame('', $invocation['request']->getHeaderLine('X-Preset'));
        }
    }

    public function testRegisteredPresetOverridesGlobalsAndEmptyPerCallConfigClearsPreset(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->globalOptions(['timeout' => 1, 'headers' => ['X-Global' => 'yes']]);
        $factory->registerConnection('api', ['timeout' => 2, 'headers' => ['X-Preset' => 'yes']]);

        $factory->connection('api')->get('https://example.com/preset');
        $factory->connection('api', [])->get('https://example.com/cleared');

        $this->assertSame(2, $factory->invocations[0]['options']['timeout']);
        $this->assertSame('yes', $factory->invocations[0]['request']->getHeaderLine('X-Preset'));
        $this->assertSame(1, $factory->invocations[1]['options']['timeout']);
        $this->assertSame('', $factory->invocations[1]['request']->getHeaderLine('X-Preset'));
        $this->assertSame('yes', $factory->invocations[1]['request']->getHeaderLine('X-Global'));
    }

    public function testLaterOptionLayersReplaceListsWhole(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->globalOptions([
            'headers' => ['X-Tags' => ['global-a', 'global-b'], 'X-Global' => 'yes'],
            'auth' => ['user', 'secret', 'digest'],
            'allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https']],
            'proxy' => ['http' => 'http://proxy.test', 'no' => ['example.test']],
            'curl' => [CURLOPT_TCP_KEEPALIVE => 1],
        ]);

        $options = $factory
            ->withHeaders(['X-Tags' => ['local']])
            ->withBasicAuth('other', 'pass')
            ->withOptions(['allow_redirects' => ['protocols' => ['https']]])
            ->withOptions(['proxy' => ['no' => []]])
            ->withOptions(['curl' => []])
            ->getOptions();

        $this->assertSame(['X-Tags' => ['local'], 'X-Global' => 'yes'], $options['headers']);
        $this->assertSame(['other', 'pass'], $options['auth']);
        $this->assertSame(['max' => 3, 'protocols' => ['https']], $options['allow_redirects']);
        $this->assertSame(['http' => 'http://proxy.test', 'no' => []], $options['proxy']);
        $this->assertSame([CURLOPT_TCP_KEEPALIVE => 1], $options['curl']);

        $options = $factory
            ->withOptions(['auth' => ['first', 'secret', 'digest']])
            ->withOptions(['auth' => ['second', 'secret']])
            ->getOptions();

        $this->assertSame(['second', 'secret'], $options['auth']);

        $this->assertSame([], $factory->withOptions(['auth' => []])->getOptions()['auth']);
    }

    public function testMergeableListsAccumulateAcrossOptionLayers(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->registerConnection('api');

        $factory->connection('api')
            ->withOptions(['multipart' => [['name' => 'meta', 'contents' => 'meta-contents']]])
            ->attach('file', 'file-contents', 'file.txt')
            ->post('https://example.com/upload');

        $factory->connection('api')
            ->withOptions(['json' => [1, 2]])
            ->post('https://example.com/list', [3]);

        $body = (string) $factory->invocations[0]['request']->getBody();

        $this->assertSame(1, substr_count($body, 'name="meta"'));
        $this->assertSame(1, substr_count($body, 'name="file"'));
        $this->assertStringContainsString('meta-contents', $body);
        $this->assertStringContainsString('file-contents', $body);
        $this->assertSame('[1,2,3]', (string) $factory->invocations[1]['request']->getBody());
    }

    public function testMergingOptionLayersLeavesReferencedCallerDataUnchanged(): void
    {
        $name = 'default';

        $factory = new RecordingHttpConnectionFactory;
        $factory->globalOptions(['json' => ['user' => ['name' => &$name]]]);
        $factory->registerConnection('api');

        $factory->connection('api')->post('https://example.com/first', ['user' => ['name' => 'request-one']]);
        $factory->connection('api')->post('https://example.com/second', ['user' => ['other' => 'request-two']]);

        $this->assertSame('default', $name);
        $this->assertSame('{"user":{"name":"request-one"}}', (string) $factory->invocations[0]['request']->getBody());
        $this->assertSame('{"user":{"name":"default","other":"request-two"}}', (string) $factory->invocations[1]['request']->getBody());
    }

    public function testOmittedPayloadsKeepAndExplicitNullsClearPayloadsFromEarlierLayers(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->registerConnection('api');

        $factory->connection('api')->withOptions(['json' => ['a' => 1]])->post('https://example.com');
        $factory->connection('api')->withOptions(['json' => ['a' => 1]])->delete('https://example.com');
        $factory->connection('api')->withOptions(['json' => ['a' => 1]])->send('POST', 'https://example.com');
        $factory->connection('api')->withOptions(['json' => ['a' => 1]])->send('POST', 'https://example.com', ['json' => null]);
        $factory->connection('api')->withOptions(['json' => ['a' => 1]])->get('https://example.com');
        $factory->connection('api')->withOptions(['json' => ['a' => 1]])->head('https://example.com');

        $this->assertSame('{"a":1}', (string) $factory->invocations[0]['request']->getBody());
        $this->assertSame('{"a":1}', (string) $factory->invocations[1]['request']->getBody());
        $this->assertSame('{"a":1}', (string) $factory->invocations[2]['request']->getBody());
        $this->assertSame('', (string) $factory->invocations[3]['request']->getBody());
        $this->assertSame('{"a":1}', (string) $factory->invocations[4]['request']->getBody());
        $this->assertSame('{"a":1}', (string) $factory->invocations[5]['request']->getBody());
    }

    public function testSetConnectionConfigRegistersEvenAnEmptyPreset(): void
    {
        $factory = new RecordingHttpConnectionFactory;

        $factory->setConnectionConfig('api', []);

        $this->assertTrue($factory->hasConnection('api'));
        $this->assertSame([], $factory->getConnectionOptions('api'));
        $this->assertInstanceOf(PendingRequest::class, $factory->connection('api'));
    }

    public function testCreateClientRequiresTheRequestOwnedCookieJar(): void
    {
        $parameter = (new ReflectionMethod(Factory::class, 'createClient'))->getParameters()[1];

        $this->assertFalse($parameter->allowsNull());
        $this->assertFalse($parameter->isOptional());
    }

    public function testExplicitClientBypassesConnectionPresetButKeepsGlobalAndFluentOptions(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->globalOptions(['headers' => ['X-Global' => 'yes']]);
        $factory->registerConnection('api', ['headers' => ['X-Preset' => 'yes']]);
        $receivedRequest = null;
        $client = new Client([
            'handler' => function (RequestInterface $request) use (&$receivedRequest): PromiseInterface {
                $receivedRequest = $request;

                return Create::promiseFor(new Psr7Response(200));
            },
        ]);

        $factory->connection('api')
            ->setClient($client)
            ->withHeader('X-Fluent', 'yes')
            ->get('https://example.com');

        $this->assertSame('yes', $receivedRequest->getHeaderLine('X-Global'));
        $this->assertSame('yes', $receivedRequest->getHeaderLine('X-Fluent'));
        $this->assertSame('', $receivedRequest->getHeaderLine('X-Preset'));
        $this->assertCount(0, $factory->createdHandlerOptions);
    }

    #[TestWith(['max_host_connections'])]
    #[TestWith(['max_total_connections'])]
    public function testSharedHandlerRejectsConnectionCaps(string $option): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Use bounded coroutine fan-out or rate limiting instead.');

        HandlerFactory::create([$option => 2]);
    }

    #[DataProvider('registeredReservedOptionsProvider')]
    public function testRegisteredConnectionsRejectReservedOptions(string $option, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains("The [{$option}] option is not allowed in registered connection configuration.");

        (new Factory)->registerConnection('api', [$option => $value]);
    }

    public static function registeredReservedOptionsProvider(): array
    {
        return [
            'pool' => ['pool', []],
            'handler' => ['handler', static fn () => null],
            'cookies' => ['cookies', true],
            'host connection cap' => ['max_host_connections', 5],
            'total connection cap' => ['max_total_connections', 10],
        ];
    }

    #[DataProvider('universallyReservedOptionsProvider')]
    public function testGlobalOptionsRejectReservedOptionsFromArraysAndClosures(string $option, mixed $value): void
    {
        foreach ([[$option => $value], fn () => [$option => $value]] as $options) {
            $factory = new Factory;
            $factory->globalOptions($options);

            try {
                $factory->createPendingRequest();
                $this->fail("Global option [{$option}] was not rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    "The [{$option}] option is not allowed in global HTTP client options.",
                    $exception->getMessage(),
                );
            }
        }
    }

    #[DataProvider('universallyReservedOptionsProvider')]
    public function testPerCallConnectionOverridesRejectReservedOptions(string $option, mixed $value): void
    {
        $factory = new Factory;
        $factory->registerConnection('api');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains("The [{$option}] option is not allowed in per-call HTTP connection options.");

        $factory->connection('api', [$option => $value]);
    }

    #[DataProvider('universallyReservedOptionsProvider')]
    public function testFluentOptionsRejectReservedOptions(string $option, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains("The [{$option}] option is not allowed in fluent HTTP request options.");

        (new PendingRequest)->withOptions([$option => $value]);
    }

    #[DataProvider('universallyReservedOptionsProvider')]
    public function testSendOptionsRejectReservedOptions(string $option, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains("The [{$option}] option is not allowed in request options.");

        (new PendingRequest)->send('GET', 'https://example.com', [$option => $value]);
    }

    public static function universallyReservedOptionsProvider(): array
    {
        return [
            'pool' => ['pool', []],
            'handler' => ['handler', static fn () => null],
            'cookies' => ['cookies', true],
            'transport sharing' => ['transport_sharing', TransportSharing::HANDLER_PREFER],
            'max idle handles' => ['max_idle_handles', 8],
            'max idle multi handlers' => ['max_idle_multi_handlers', 4],
            'host connection cap' => ['max_host_connections', 5],
            'total connection cap' => ['max_total_connections', 10],
        ];
    }

    #[DataProvider('requestModes')]
    public function testHandlerOptionsOnlyConfigureTheConnectionHandler(bool $async): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->registerConnection('api', [
            'transport_sharing' => TransportSharing::HANDLER_PREFER,
            'max_idle_handles' => 8,
            'max_idle_multi_handlers' => 4,
            'timeout' => 12,
        ]);

        $response = $factory->connection('api')->async($async)->get('https://example.com');
        if ($async) {
            $response = $response->wait();
        }

        $this->assertSame('handler-1', $response->body());
        $this->assertSame(
            [['transport_sharing' => TransportSharing::HANDLER_PREFER, 'max_idle_handles' => 8, 'max_idle_multi_handlers' => 4]],
            $factory->createdHandlerOptions,
        );
        $this->assertArrayNotHasKey('transport_sharing', $factory->invocations[0]['options']);
        $this->assertArrayNotHasKey('max_idle_handles', $factory->invocations[0]['options']);
        $this->assertArrayNotHasKey('max_idle_multi_handlers', $factory->invocations[0]['options']);
        $this->assertSame(12, $factory->invocations[0]['options']['timeout']);
        $this->assertSame(['timeout' => 12], $factory->getConnectionOptions('api'));
    }

    #[DataProvider('invalidIdleHandleLimits')]
    public function testRegisteredConnectionsRejectInvalidIdleHandleLimits(string $option, mixed $limit): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The [{$option}] connection option must be an integer of 0 or more.");

        (new Factory)->registerConnection('api', [$option => $limit]);
    }

    /**
     * Provide invalid idle handle limits.
     */
    public static function invalidIdleHandleLimits(): array
    {
        return [
            'negative' => ['max_idle_handles', -1],
            'numeric string' => ['max_idle_handles', '8'],
            'float' => ['max_idle_handles', 8.0],
            'negative handler limit' => ['max_idle_multi_handlers', -1],
        ];
    }

    public function testAConnectionKeepingNoIdleHandlesReconnectsForEveryRequest(): void
    {
        $ports = [];
        $failure = null;

        run(static function () use (&$ports, &$failure): void {
            $server = new Server('127.0.0.1', 0, false, false);
            $server->handle('/', static function (ServerRequest $request, ServerResponse $response): void {
                $response->end((string) $request->server['remote_port']);
            });
            Coroutine::create(fn (): bool => $server->start());
            $factory = new Factory;
            $factory->registerConnection('api', ['transport_sharing' => TransportSharing::NONE, 'max_idle_handles' => 0]);

            try {
                for ($request = 0; $request < 3; ++$request) {
                    $ports[] = $factory->connection('api')->withOptions(['proxy' => '', 'version' => '1.1'])
                        ->timeout(3)->get("http://127.0.0.1:{$server->port}/")->throw()->body();
                }
            } catch (Throwable $exception) {
                $failure = $exception;
            } finally {
                $factory->forgetConnectionHandlers();
                $server->shutdown();
            }
        });

        if ($failure !== null) {
            throw $failure;
        }

        $this->assertCount(3, array_unique($ports));
    }

    #[DataProvider('requestModes')]
    public function testDisablingMultiplexingConfiguresTheHandlerAndRequest(bool $async): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->registerConnection('api', ['multiplex' => Multiplexing::NONE]);

        $response = $factory->connection('api')->async($async)->get('https://example.com');
        if ($async) {
            $response = $response->wait();
        }

        $this->assertSame('handler-1', $response->body());
        $this->assertSame([['multiplex' => Multiplexing::NONE]], $factory->createdHandlerOptions);
        $this->assertSame(Multiplexing::NONE, $factory->invocations[0]['options']['multiplex']);
    }

    #[DataProvider('requestModes')]
    public function testReplacingThePresetPreservesRegisteredTransportOptions(bool $async): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->registerConnection('api', [
            'multiplex' => Multiplexing::NONE,
            'transport_sharing' => TransportSharing::HANDLER_REQUIRE,
            'max_idle_handles' => 8,
            'timeout' => 12,
        ]);

        $response = $factory->connection('api', [])->async($async)->get('https://example.com');
        if ($async) {
            $response = $response->wait();
        }

        $this->assertSame('handler-1', $response->body());
        $this->assertSame([[
            'transport_sharing' => TransportSharing::HANDLER_REQUIRE,
            'max_idle_handles' => 8,
            'multiplex' => Multiplexing::NONE,
        ]], $factory->createdHandlerOptions);
        $this->assertArrayNotHasKey('multiplex', $factory->invocations[0]['options']);
        $this->assertArrayNotHasKey('transport_sharing', $factory->invocations[0]['options']);
        $this->assertArrayNotHasKey('max_idle_handles', $factory->invocations[0]['options']);
        $this->assertSame(30, $factory->invocations[0]['options']['timeout']);
    }

    /**
     * Provide synchronous and asynchronous request modes.
     */
    public static function requestModes(): array
    {
        return ['sync' => [false], 'async' => [true]];
    }

    public function testOtherMultiplexingModesConfigureOnlyTheRequest(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->registerConnection('api', ['multiplex' => Multiplexing::EAGER]);

        $factory->connection('api')->get('https://example.com');

        $this->assertSame([[]], $factory->createdHandlerOptions);
        $this->assertSame(Multiplexing::EAGER, $factory->invocations[0]['options']['multiplex']);
    }

    #[TestWith([TransportSharing::NONE, TransportSharing::NONE])]
    #[TestWith([TransportSharing::HANDLER_PREFER, TransportSharing::HANDLER_PREFER])]
    #[TestWith([TransportSharing::HANDLER_REQUIRE, TransportSharing::HANDLER_REQUIRE])]
    #[TestWith([TransportSharing::PERSISTENT_PREFER, TransportSharing::HANDLER_PREFER])]
    public function testIsolatedHandlersPreserveConnectionSettingsWithoutSharingAcrossRequests(string $sharing, string $expectedSharing): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->registerConnection('api', [
            'transport_sharing' => $sharing,
            'multiplex' => Multiplexing::NONE,
            'max_idle_handles' => 8,
            'max_idle_multi_handlers' => 4,
            'timeout' => 12,
        ]);

        $factory->newIsolatedHandler('api');
        $factory->newIsolatedHandler('api');

        $this->assertSame(array_fill(0, 2, [
            'transport_sharing' => $expectedSharing,
            'max_idle_handles' => 8,
            'max_idle_multi_handlers' => 4,
            'multiplex' => Multiplexing::NONE,
        ]), $factory->createdHandlerOptions);
    }

    public function testIsolatedHandlersCannotRequirePersistentSharing(): void
    {
        $factory = new Factory;
        $factory->registerConnection('api', ['transport_sharing' => TransportSharing::PERSISTENT_REQUIRE]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP connection [api] requires persistent transport sharing, which cannot be used with destination pinning.');

        $factory->newIsolatedHandler('api');
    }

    public function testRegisteredAsynchronousRequestsUseTheSharedHandler(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->registerConnection('api', ['multiplex' => Multiplexing::NONE]);

        $this->assertSame('handler-1', $factory->connection('api')->get('https://example.com')->body());
        $this->assertSame('handler-1', $factory->connection('api')->async()->get('https://example.com')->wait()->body());
        $this->assertSame('handler-1', $factory->connection('api')->async()->get('https://example.com')->wait()->body());
        $this->assertSame('handler-1', $factory->connection('api')->get('https://example.com')->body());

        $this->assertSame([['multiplex' => Multiplexing::NONE]], $factory->createdHandlerOptions);
    }

    public function testReregisteringAConnectionReplacesItsSharedHandler(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->registerConnection('api');

        $oldHandler = $factory->getConnectionHandler('api');

        $factory->registerConnection('api', ['timeout' => 12]);
        $newHandler = $factory->getConnectionHandler('api');

        $this->assertNotSame($oldHandler, $newHandler);
        $this->assertCount(2, $factory->createdHandlerOptions);
    }

    public function testForgettingConnectionHandlersPreservesPresetsAndRebuildsEveryHandler(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $apiConfig = ['timeout' => 12];
        $reportingConfig = ['timeout' => 30];
        $factory->registerConnection('api', $apiConfig);
        $factory->registerConnection('reporting', $reportingConfig);
        $oldApiHandler = $factory->getConnectionHandler('api');
        $oldReportingHandler = $factory->getConnectionHandler('reporting');
        $oldDefaultHandler = $factory->getDefaultHandler();

        $this->assertSame($factory, $factory->forgetConnectionHandlers());
        $this->assertSame($apiConfig, $factory->getConnectionConfig('api'));
        $this->assertSame($reportingConfig, $factory->getConnectionConfig('reporting'));

        $newApiHandler = $factory->getConnectionHandler('api');
        $newReportingHandler = $factory->getConnectionHandler('reporting');
        $newDefaultHandler = $factory->getDefaultHandler();

        $this->assertNotSame($oldApiHandler, $newApiHandler);
        $this->assertNotSame($oldReportingHandler, $newReportingHandler);
        $this->assertNotSame($oldDefaultHandler, $newDefaultHandler);
        $this->assertCount(6, $factory->createdHandlerOptions);
    }

    public function testConcurrentRequestsOwnIsolatedCookieJars(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->registerConnection('api');

        $responses = null;
        run(function () use ($factory, &$responses): void {
            $responses = parallel([
                fn () => $factory->connection('api')
                    ->withHeader('X-Request', 'first')
                    ->withCookies(['session' => 'first'], 'example.com')
                    ->get('https://example.com/first'),
                fn () => $factory->connection('api')
                    ->withHeader('X-Request', 'second')
                    ->withCookies(['session' => 'second'], 'example.com')
                    ->get('https://example.com/second'),
            ]);
        });

        $this->assertCount(2, $responses);

        $cookies = [];
        foreach ($factory->invocations as $invocation) {
            $cookies[$invocation['request']->getHeaderLine('X-Request')] = $invocation['request']->getHeaderLine('Cookie');
        }

        $this->assertSame('session=first', $cookies['first']);
        $this->assertSame('session=second', $cookies['second']);
    }

    public function testRetryChainRetainsCookiesOnItsRequestOwnedJar(): void
    {
        $factory = new RetryCookieHttpConnectionFactory;
        $factory->registerConnection('api');

        $response = $factory->connection('api')
            ->retry(2, 0, throw: false)
            ->get('https://example.com');

        $this->assertSame(200, $response->status());
        $this->assertSame(['', 'session=retry'], $factory->cookieHeaders);
    }
}

class RecordingHttpConnectionFactory extends Factory
{
    public array $createdHandlerOptions = [];

    public array $invocations = [];

    protected function createConnectionHandler(array $options): callable
    {
        $this->createdHandlerOptions[] = $options;
        $number = count($this->createdHandlerOptions);

        return function (RequestInterface $request, array $requestOptions) use ($number): PromiseInterface {
            $this->invocations[] = [
                'handler' => $number,
                'request' => $request,
                'options' => $requestOptions,
            ];

            return Create::promiseFor(new Psr7Response(200, [], "handler-{$number}"));
        };
    }
}

class RetryCookieHttpConnectionFactory extends Factory
{
    public array $cookieHeaders = [];

    protected function createConnectionHandler(array $options): callable
    {
        return function (RequestInterface $request): PromiseInterface {
            $this->cookieHeaders[] = $request->getHeaderLine('Cookie');

            return count($this->cookieHeaders) === 1
                ? Create::promiseFor(new Psr7Response(500, ['Set-Cookie' => 'session=retry; Path=/']))
                : Create::promiseFor(new Psr7Response(200));
        };
    }
}
