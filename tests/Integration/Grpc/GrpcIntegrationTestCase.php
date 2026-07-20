<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Grpc;

use Hypervel\Container\Container;
use Hypervel\Contracts\Engine\Http\V2\ClientFactoryInterface;
use Hypervel\Engine\Http\V2\ClientFactory;
use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Grpc\Client\BaseClient;
use Hypervel\Grpc\Health\HealthClient;
use Hypervel\Tests\Grpc\Fixtures\TestServiceClient;
use Hypervel\Tests\TestCase;
use Throwable;

abstract class GrpcIntegrationTestCase extends TestCase
{
    use InteractsWithServer;

    protected int $serverPort = 19520;

    /** @var list<BaseClient> */
    private array $clients = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInteractsWithServer();

        $container = Container::setInstance(new Container);
        $container->instance(ClientFactoryInterface::class, new ClientFactory);
    }

    protected function tearDownInCoroutine(): void
    {
        $failure = null;

        foreach ($this->clients as $client) {
            try {
                $client->close();
            } catch (Throwable $throwable) {
                $failure ??= $throwable;
            }
        }

        $this->clients = [];

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Create a tracked test-service client.
     *
     * @param array<string, mixed> $options
     */
    protected function newTestClient(array $options = []): TestServiceClient
    {
        $client = new TestServiceClient($this->target(), $options);
        $this->clients[] = $client;

        return $client;
    }

    /**
     * Create a tracked standard health client.
     *
     * @param array<string, mixed> $options
     */
    protected function newHealthClient(array $options = []): HealthClient
    {
        $client = new HealthClient($this->target(), $options);
        $this->clients[] = $client;

        return $client;
    }

    /**
     * Return the active peer target.
     */
    protected function target(): string
    {
        return $this->getServerHost() . ':' . $this->getServerPort();
    }

    /**
     * Return client TLS options for the checked-in test CA.
     *
     * @return array<string, mixed>
     */
    protected function tlsOptions(): array
    {
        return [
            'tls' => [
                'enabled' => true,
                'ca_file' => __DIR__ . '/Fixtures/Tls/ca.crt',
                'server_name' => 'localhost',
            ],
        ];
    }
}
