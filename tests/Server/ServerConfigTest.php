<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

use Hypervel\Server\Exceptions\InvalidArgumentException;
use Hypervel\Server\Port;
use Hypervel\Server\ServerConfig;
use Hypervel\Server\ServerInterface;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ServerConfigTest extends TestCase
{
    public function testAssociativeServerKeysBecomeNames(): void
    {
        $config = new ServerConfig([
            'servers' => [
                'http' => ['type' => ServerInterface::SERVER_HTTP],
                'grpc' => ['type' => ServerInterface::SERVER_HTTP],
            ],
        ]);

        $this->assertSame(
            ['http', 'grpc'],
            array_map(
                static fn (Port $port): string => $port->getName(),
                $config->getServers(),
            ),
        );
    }

    public function testNumericServerEntriesRetainExplicitNames(): void
    {
        $config = new ServerConfig([
            'servers' => [
                ['name' => 'http', 'type' => ServerInterface::SERVER_HTTP],
                ['name' => 'grpc', 'type' => ServerInterface::SERVER_HTTP],
            ],
        ]);

        $this->assertSame(
            ['http', 'grpc'],
            array_map(
                static fn (Port $port): string => $port->getName(),
                $config->getServers(),
            ),
        );
    }

    public function testEmptyServerNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Server names cannot be empty.');

        new ServerConfig([
            'servers' => [
                ['name' => ' ', 'type' => ServerInterface::SERVER_HTTP],
            ],
        ]);
    }

    #[DataProvider('duplicateServerConfigurations')]
    public function testDuplicateServerNameIsRejected(array $servers): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Server name [grpc] is duplicated.');

        new ServerConfig(['servers' => $servers]);
    }

    public static function duplicateServerConfigurations(): array
    {
        return [
            'associative then explicit' => [[
                'grpc' => ['type' => ServerInterface::SERVER_HTTP],
                ['name' => 'grpc', 'type' => ServerInterface::SERVER_HTTP],
            ]],
            'explicit then associative' => [[
                ['name' => 'grpc', 'type' => ServerInterface::SERVER_HTTP],
                'grpc' => ['type' => ServerInterface::SERVER_HTTP],
            ]],
        ];
    }

    public function testValidServerMutationsPreservePortInstances(): void
    {
        $config = new ServerConfig([
            'servers' => [
                'http' => ['type' => ServerInterface::SERVER_HTTP],
            ],
        ]);
        $grpc = Port::build([
            'name' => 'grpc',
            'type' => ServerInterface::SERVER_HTTP,
        ]);

        $config->addServer($grpc);

        $this->assertSame(['http', 'grpc'], array_map(
            static fn (Port $port): string => $port->getName(),
            $config->getServers(),
        ));

        $config->setServers([$grpc]);

        $this->assertSame([$grpc], $config->getServers());
    }

    #[DataProvider('invalidAddedServers')]
    public function testAddServerRejectsInvalidNames(Port $server, string $message): void
    {
        $config = new ServerConfig([
            'servers' => [
                'http' => ['type' => ServerInterface::SERVER_HTTP],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $config->addServer($server);
    }

    public static function invalidAddedServers(): array
    {
        return [
            'empty name' => [
                Port::build(['name' => ' ', 'type' => ServerInterface::SERVER_HTTP]),
                'Server names cannot be empty.',
            ],
            'duplicate name' => [
                Port::build(['name' => 'http', 'type' => ServerInterface::SERVER_HTTP]),
                'Server name [http] is duplicated.',
            ],
        ];
    }

    #[DataProvider('invalidServerReplacements')]
    public function testSetServersRejectsInvalidLists(array $servers, string $message): void
    {
        $config = new ServerConfig([
            'servers' => [
                'http' => ['type' => ServerInterface::SERVER_HTTP],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $config->setServers($servers);
    }

    public static function invalidServerReplacements(): array
    {
        return [
            'empty list' => [
                [],
                'Config server.servers not exist.',
            ],
            'non-port' => [
                [['name' => 'grpc']],
                'Server configurations must contain Port instances.',
            ],
            'empty name' => [
                [Port::build(['name' => '', 'type' => ServerInterface::SERVER_HTTP])],
                'Server names cannot be empty.',
            ],
            'duplicate name' => [
                [
                    Port::build(['name' => 'grpc', 'type' => ServerInterface::SERVER_HTTP]),
                    Port::build(['name' => 'grpc', 'type' => ServerInterface::SERVER_HTTP]),
                ],
                'Server name [grpc] is duplicated.',
            ],
        ];
    }
}
