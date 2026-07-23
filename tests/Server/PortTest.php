<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

use Hypervel\Server\Port;
use Hypervel\Server\Server;
use Hypervel\Tests\TestCase;

class PortTest extends TestCase
{
    public function testUsesHttpAndTcpDefaults(): void
    {
        $port = Port::build([]);

        $this->assertSame(Server::SERVER_HTTP, $port->getType());
        $this->assertSame(SWOOLE_SOCK_TCP, $port->getSockType());
        $this->assertSame([], $port->getSettings());
    }

    public function testSetting(): void
    {
        $port = Port::build([
            'name' => 'http',
            'type' => Server::SERVER_HTTP,
        ]);

        $this->assertSame([], $port->getSettings());

        $port = Port::build([
            'name' => 'tcp',
            'type' => Server::SERVER_BASE,
        ]);

        $this->assertSame([
            'open_http2_protocol' => false,
            'open_http_protocol' => false,
        ], $port->getSettings());

        $port = Port::build([
            'name' => 'tcp',
            'type' => Server::SERVER_BASE,
            'settings' => [
                'open_http2_protocol' => true,
            ],
        ]);

        $this->assertSame([
            'open_http2_protocol' => true,
            'open_http_protocol' => false,
        ], $port->getSettings());
    }
}
