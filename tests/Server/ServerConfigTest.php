<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

use Closure;
use Hypervel\Server\Exceptions\InvalidArgumentException;
use Hypervel\Server\Port;
use Hypervel\Server\Server;
use Hypervel\Server\ServerConfig;
use Hypervel\Server\ServerInterface;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Constant;

class ServerConfigTest extends TestCase
{
    public function testUsesProcessModeWithoutPublishingADeadServerClassType(): void
    {
        $config = new ServerConfig([
            'servers' => [
                ['name' => 'http'],
            ],
        ]);

        $this->assertSame(SWOOLE_PROCESS, $config->getMode());
        $this->assertArrayNotHasKey('type', $config->toArray());
    }

    #[DataProvider('invalidDynamicConfigurationCalls')]
    public function testInvalidDynamicConfigurationCallsUseThePackageException(Closure $call): void
    {
        $config = new ServerConfig([
            'servers' => [
                ['name' => 'http'],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);

        $call($config);
    }

    public static function invalidDynamicConfigurationCalls(): array
    {
        return [
            'property get' => [static fn (ServerConfig $config): mixed => $config->unknown],
            'property set' => [static function (ServerConfig $config): void {
                $config->unknown = true;
            }],
            'dynamic setter' => [static fn (ServerConfig $config): mixed => $config->setType(Server::class)],
            'unknown method' => [static fn (ServerConfig $config): mixed => $config->unknown()],
        ];
    }

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

    public function testRejectsGlobalEventObjectSetting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Swoole event_object is not supported in global server settings; use Hypervel lifecycle events instead.'
        );

        new ServerConfig([
            'servers' => $this->servers(),
            'settings' => [Constant::OPTION_EVENT_OBJECT => true],
        ]);
    }

    public function testRejectsPortEventObjectSetting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Swoole event_object is not supported on server port 'http'; use Hypervel lifecycle events instead."
        );

        new ServerConfig([
            'servers' => [[
                'name' => 'http',
                'type' => Server::SERVER_HTTP,
                'settings' => [Constant::OPTION_EVENT_OBJECT => true],
            ]],
        ]);
    }

    public function testAllowsDisabledEventObjectSetting(): void
    {
        $config = new ServerConfig([
            'servers' => [[
                'name' => 'http',
                'type' => Server::SERVER_HTTP,
                'settings' => [Constant::OPTION_EVENT_OBJECT => false],
            ]],
            'settings' => [Constant::OPTION_EVENT_OBJECT => false],
        ]);

        $this->assertFalse($config->getSettings()[Constant::OPTION_EVENT_OBJECT]);
        $this->assertFalse($config->getServers()[0]->getSettings()[Constant::OPTION_EVENT_OBJECT]);
    }

    public function testRejectsGlobalEventObjectSettingAfterConstruction(): void
    {
        $config = new ServerConfig(['servers' => $this->servers()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Swoole event_object is not supported in global server settings; use Hypervel lifecycle events instead.'
        );

        $config->setSettings([Constant::OPTION_EVENT_OBJECT => true]);
    }

    public function testRejectsPortEventObjectSettingAfterConstruction(): void
    {
        $config = new ServerConfig(['servers' => $this->servers()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Swoole event_object is not supported on server port 'http'; use Hypervel lifecycle events instead."
        );

        $config->getServers()[0]->setSettings([Constant::OPTION_EVENT_OBJECT => true]);
    }

    /**
     * Get the minimum valid server configuration.
     */
    private function servers(): array
    {
        return [[
            'name' => 'http',
            'type' => Server::SERVER_HTTP,
        ]];
    }
}
