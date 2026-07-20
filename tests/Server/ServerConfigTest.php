<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

use Hypervel\Server\Exceptions\InvalidArgumentException;
use Hypervel\Server\Server;
use Hypervel\Server\ServerConfig;
use Hypervel\Tests\TestCase;
use Swoole\Constant;

class ServerConfigTest extends TestCase
{
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
