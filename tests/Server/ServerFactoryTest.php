<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

use Hypervel\Container\Container;
use Hypervel\Server\ServerConfig;
use Hypervel\Server\ServerFactory;
use Hypervel\Server\ServerInterface;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Swoole\Server as SwooleServer;

class ServerFactoryTest extends TestCase
{
    use VerifiesDoubles;

    public function testExplicitServerInjectionAcceptsAnyInterfaceImplementation(): void
    {
        $server = new ServerFactoryTestServer(Double::for(SwooleServer::class)->strict());
        $factory = new ServerFactory(new Container);

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

    /**
     * Create a server wrapper with the supplied native server.
     */
    public function __construct(private SwooleServer $server)
    {
    }

    /**
     * Record the server configuration.
     */
    public function init(ServerConfig $config): ServerInterface
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Record that the server was started.
     */
    public function start(): void
    {
        $this->started = true;
    }

    /**
     * Return the supplied native server.
     */
    public function getServer(): SwooleServer
    {
        return $this->server;
    }
}
