<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Console\DbCommand;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;

class DatabaseDbCommandTest extends TestCase
{
    public function testReadOptionMergesFirstListConfigAndStripsReadWriteConfig(): void
    {
        $connection = $this->getConnection([
            'mysql' => $this->mysqlConfig([
                'read' => [
                    ['host' => 'read-one', 'username' => 'reader-one'],
                    ['host' => 'read-two', 'username' => 'reader-two'],
                ],
                'write' => [
                    ['host' => 'write-one'],
                ],
            ]),
        ], [
            'connection' => 'mysql',
            '--read' => true,
        ]);

        $this->assertSame('read-one', $connection['host']);
        $this->assertSame('reader-one', $connection['username']);
        $this->assertArrayNotHasKey('read', $connection);
        $this->assertArrayNotHasKey('write', $connection);
    }

    public function testWriteOptionMergesFirstListConfigAndStripsReadWriteConfig(): void
    {
        $connection = $this->getConnection([
            'mysql' => $this->mysqlConfig([
                'read' => [
                    ['host' => 'read-one'],
                ],
                'write' => [
                    ['host' => 'write-one', 'username' => 'writer-one'],
                    ['host' => 'write-two', 'username' => 'writer-two'],
                ],
            ]),
        ], [
            'connection' => 'mysql',
            '--write' => true,
        ]);

        $this->assertSame('write-one', $connection['host']);
        $this->assertSame('writer-one', $connection['username']);
        $this->assertArrayNotHasKey('read', $connection);
        $this->assertArrayNotHasKey('write', $connection);
    }

    public function testReadOptionUsesFirstHostFromHostArray(): void
    {
        $connection = $this->getConnection([
            'mysql' => $this->mysqlConfig([
                'read' => [
                    'host' => ['read-one', 'read-two'],
                ],
            ]),
        ], [
            'connection' => 'mysql',
            '--read' => true,
        ]);

        $this->assertSame('read-one', $connection['host']);
        $this->assertArrayNotHasKey('read', $connection);
        $this->assertArrayNotHasKey('write', $connection);
    }

    public function testEmptyReadConfigReturnsBaseConfigWithoutReadWriteConfig(): void
    {
        $connection = $this->getConnection([
            'mysql' => $this->mysqlConfig([
                'read' => [],
                'write' => [
                    'host' => 'write-one',
                ],
            ]),
        ], [
            'connection' => 'mysql',
            '--read' => true,
        ]);

        $this->assertSame('write-host', $connection['host']);
        $this->assertArrayNotHasKey('read', $connection);
        $this->assertArrayNotHasKey('write', $connection);
    }

    public function testDefaultConnectionIsReadFromConfigRepository(): void
    {
        $connection = $this->getConnection([
            'mysql' => $this->mysqlConfig(),
        ]);

        $this->assertSame('write-host', $connection['host']);
    }

    public function testUrlConfigIsParsedBeforeReadWriteMerge(): void
    {
        $connection = $this->getConnection([
            'mysql' => [
                'url' => 'mysql://root:secret@write-host/app?strict=true',
                'read' => [
                    'host' => ['read-one', 'read-two'],
                ],
            ],
        ], [
            'connection' => 'mysql',
            '--read' => true,
        ]);

        $this->assertSame('mysql', $connection['driver']);
        $this->assertSame('app', $connection['database']);
        $this->assertSame('read-one', $connection['host']);
        $this->assertSame('root', $connection['username']);
        $this->assertSame('secret', $connection['password']);
        $this->assertTrue($connection['strict']);
        $this->assertArrayNotHasKey('read', $connection);
        $this->assertArrayNotHasKey('write', $connection);
    }

    private function getConnection(array $connections, array $input = []): array
    {
        $command = new TestableDbCommand;
        $command->setHypervel($this->applicationWithConfig($connections));
        $command->setInput($this->inputFor($command, $input));

        return $command->getConnection();
    }

    private function applicationWithConfig(array $connections): Application|m\MockInterface
    {
        $application = m::mock(Application::class);
        $application->shouldReceive('make')
            ->once()
            ->with('config')
            ->andReturn(new Repository([
                'database' => [
                    'default' => 'mysql',
                    'connections' => $connections,
                ],
            ]));

        return $application;
    }

    private function inputFor(DbCommand $command, array $input): InputInterface
    {
        $arrayInput = new ArrayInput($input);
        $arrayInput->bind($command->getDefinition());

        return $arrayInput;
    }

    private function mysqlConfig(array $overrides = []): array
    {
        return array_merge([
            'driver' => 'mysql',
            'host' => 'write-host',
            'port' => 3306,
            'database' => 'app',
            'username' => 'root',
            'password' => '',
            'read' => [
                'host' => 'read-host',
            ],
            'write' => [
                'host' => 'write-host',
            ],
        ], $overrides);
    }
}

class TestableDbCommand extends DbCommand
{
    public function setInput(InputInterface $input): void
    {
        $this->input = $input;
    }
}
