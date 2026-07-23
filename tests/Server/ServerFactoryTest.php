<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

use Hypervel\Contracts\Container\Container;
use Hypervel\Server\ServerConfig;
use Hypervel\Server\ServerFactory;
use Hypervel\Server\ServerInterface;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Swoole\Server as SwooleServer;

class ServerFactoryTest extends TestCase
{
    public function testExplicitServerInjectionAcceptsAnyInterfaceImplementation(): void
    {
        $server = new ServerFactoryTestServer(m::mock(SwooleServer::class));
        $factory = new ServerFactory(m::mock(Container::class));

        $this->assertSame($factory, $factory->setServer($server));

        $factory->configure([
            'servers' => [
                ['name' => 'http'],
            ],
        ]);
        $factory->start();

        $this->assertSame($server, $factory->getServer());
        $this->assertInstanceOf(ServerConfig::class, $server->config);
        $this->assertTrue($server->started);
    }
}

class ServerFactoryTestServer implements ServerInterface
{
    public ?ServerConfig $config = null;

    public bool $started = false;

    public function __construct(private SwooleServer $server)
    {
    }

    public function init(ServerConfig $config): ServerInterface
    {
        $this->config = $config;

        return $this;
    }

    public function start(): void
    {
        $this->started = true;
    }

    public function getServer(): SwooleServer
    {
        return $this->server;
    }
}
