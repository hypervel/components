<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Http\Client\TransportHandler;
use Hypervel\Http\Client\UnsupportedTransportException;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;

class HttpTransportConfigurationTest extends TestCase
{
    /** @var TransportConfigurationFactory[] */
    private array $factories = [];

    protected function tearDown(): void
    {
        try {
            foreach ($this->factories as $factory) {
                $factory->purge();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testTransportPrecedenceAndHandlerCaching(): void
    {
        $factory = $this->factory();
        $factory->setDefaultTransport('auto');
        $factory->registerConnection('analytics', ['transport' => 'swoole']);

        $defaultHandler = $factory->getConnectionHandler();
        $analyticsHandler = $factory->getConnectionHandler('analytics');

        $defaultResponse = $factory->get('https://example.com/default');
        $secondDefaultResponse = $factory->get('https://example.com/default-again');
        $analyticsResponse = $factory->connection('analytics')->get('https://example.com/analytics');
        $overrideResponse = $factory->connection('analytics')
            ->transport('curl')
            ->get('https://example.com/override');

        $this->assertSame('auto', $defaultResponse->body());
        $this->assertSame('auto', $secondDefaultResponse->body());
        $this->assertSame('swoole', $analyticsResponse->body());
        $this->assertSame('curl', $overrideResponse->body());
        $this->assertSame($defaultHandler, $factory->getConnectionHandler());
        $this->assertSame($analyticsHandler, $factory->getConnectionHandler('analytics'));
        $this->assertSame(['auto', 'auto'], $defaultHandler->transports);
        $this->assertSame(['swoole', 'curl'], $analyticsHandler->transports);
        $this->assertSame(['default', 'connection:analytics'], array_keys($factory->createdHandlers));
    }

    #[DataProvider('strictAsyncOrderProvider')]
    public function testStrictSwooleAsyncIsRejectedAtTheMutatorThatMakesItEffective(string $order): void
    {
        $factory = $this->factory();
        $factory->registerConnection('strict', ['transport' => 'swoole']);

        $this->expectException(UnsupportedTransportException::class);
        $this->expectExceptionMessage('Use parallel() with synchronous requests for native concurrency.');

        match ($order) {
            'transport then async' => $factory->transport('swoole')->async(),
            'async then transport' => $factory->async()->transport('swoole'),
            'connection then async' => $factory->connection('strict')->async(),
            'async then connection' => $factory->async()->connection('strict'),
            'factory default then async' => $factory->setDefaultTransport('swoole')->async(),
        };
    }

    public static function strictAsyncOrderProvider(): array
    {
        return [
            'transport then async' => ['transport then async'],
            'async then transport' => ['async then transport'],
            'connection then async' => ['connection then async'],
            'async then connection' => ['async then connection'],
            'factory default then async' => ['factory default then async'],
        ];
    }

    public function testFinalBuildRejectsStrictAsyncAfterFactoryPolicyChanges(): void
    {
        $factory = $this->factory();
        $request = $factory->async();

        $factory->setDefaultTransport('swoole');

        $this->expectException(UnsupportedTransportException::class);
        $this->expectExceptionMessage('does not support async requests');

        $request->buildClient();
    }

    public function testAutoAndCurlAsyncUseTheConfiguredOrOverriddenHandler(): void
    {
        $factory = $this->factory();
        $factory->setDefaultTransport('auto');
        $factory->registerConnection('strict', ['transport' => 'swoole']);

        $auto = $factory->async()->get('https://example.com/auto')->wait();
        $curl = $factory->connection('strict')
            ->transport('curl')
            ->async()
            ->get('https://example.com/curl')
            ->wait();

        $this->assertSame('auto', $auto->body());
        $this->assertSame('curl', $curl->body());
    }

    public function testCustomHandlerBypassesStrictFactoryTransport(): void
    {
        $factory = $this->factory();
        $factory->setDefaultTransport('swoole');

        $response = $factory
            ->setHandler(static fn () => Factory::response('custom'))
            ->async()
            ->get('https://example.com')
            ->wait();

        $this->assertSame('custom', $response->body());
    }

    #[DataProvider('customTransportConflictProvider')]
    public function testRequestLocalTransportRejectsCustomClientsAndHandlersInEitherOrder(string $order): void
    {
        $request = $this->factory()->createPendingRequest();
        $handler = static fn () => Factory::response();
        $client = new Client(['handler' => $handler]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be combined');

        match ($order) {
            'transport then handler' => $request->transport('auto')->setHandler($handler),
            'handler then transport' => $request->setHandler($handler)->transport('auto'),
            'transport then client' => $request->transport('auto')->setClient($client),
            'client then transport' => $request->setClient($client)->transport('auto'),
        };
    }

    public static function customTransportConflictProvider(): array
    {
        return [
            'transport then handler' => ['transport then handler'],
            'handler then transport' => ['handler then transport'],
            'transport then client' => ['transport then client'],
            'client then transport' => ['client then transport'],
        ];
    }

    public function testTransportSelectionRequiresAnOwningFactory(): void
    {
        $request = (new PendingRequest)->transport('auto');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must belong to an HTTP Factory');

        $request->buildHandlerStack();
    }

    public function testInvalidRequestLocalTransportIsRejectedImmediately(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported HTTP transport [native]');

        $this->factory()->transport('native');
    }

    /**
     * Create and track a transport-aware test factory.
     */
    private function factory(): TransportConfigurationFactory
    {
        return $this->factories[] = new TransportConfigurationFactory;
    }
}

class TransportConfigurationFactory extends Factory
{
    /** @var array<string, RecordingTransportHandler> */
    public array $createdHandlers = [];

    /**
     * Create a recording transport handler.
     */
    protected function createConnectionHandler(string $identity, array $config): callable
    {
        return $this->createdHandlers[$identity] = new RecordingTransportHandler(
            $identity,
            $config['transport'] ?? $this->defaultTransport,
        );
    }
}

class RecordingTransportHandler extends TransportHandler
{
    /** @var string[] */
    public array $transports = [];

    public function __construct(
        string $identity,
        private string $configuredTransport,
    ) {
        parent::__construct(
            poolFactory: new PoolManager,
            logicalIdentity: $identity,
            poolOptions: PoolOptions::fromArray(['min_retained_objects' => 0]),
            transport: $configuredTransport,
            fallbackHandler: static fn () => Create::promiseFor(new Psr7Response(200)),
        );
    }

    /**
     * Record use of the configured transport.
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        return $this->respond($this->configuredTransport);
    }

    /**
     * Record use of a request-local transport override.
     */
    public function handleUsing(
        string $transport,
        RequestInterface $request,
        array $options,
    ): PromiseInterface {
        return $this->respond($transport);
    }

    /**
     * Build a response that identifies the selected transport.
     */
    private function respond(string $transport): PromiseInterface
    {
        $this->transports[] = $transport;

        return Create::promiseFor(new Psr7Response(200, [], $transport));
    }
}
