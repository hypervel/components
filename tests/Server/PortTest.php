<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

use Hypervel\Server\Port;
use Hypervel\Server\Server;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class PortTest extends TestCase
{
    public function testSetting()
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
