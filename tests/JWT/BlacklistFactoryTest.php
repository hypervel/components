<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT;

use Hyperf\Config\Config;
use Hyperf\Contract\ConfigInterface;
use Hypervel\JWT\Blacklist;
use Hypervel\JWT\BlacklistFactory;
use Hypervel\JWT\Contracts\StorageContract;
use Hypervel\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;

/**
 * @internal
 * @coversNothing
 */
class BlacklistFactoryTest extends TestCase
{
    #[DataProvider('refreshTTLProvider')]
    public function testBlacklistIsKeptForTheRefreshWindow(?int $refreshTTL, int $blacklistRefreshTTL, ?int $expected)
    {
        $config = new Config([
            'jwt' => [
                'refresh_ttl' => $refreshTTL,
                'blacklist_refresh_ttl' => $blacklistRefreshTTL,
                'providers' => [
                    'storage' => StorageContract::class,
                ],
            ],
        ]);

        /** @var ContainerInterface|MockInterface */
        $container = Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('get')->with(ConfigInterface::class)->andReturn($config);
        $container->shouldReceive('get')->with(StorageContract::class)->andReturn(Mockery::mock(StorageContract::class));

        $blacklist = (new BlacklistFactory())($container);

        $this->assertInstanceOf(Blacklist::class, $blacklist);
        $this->assertSame($expected, $blacklist->getRefreshTTL());
    }

    public static function refreshTTLProvider(): array
    {
        return [
            'refresh ttl is longer' => [43200, 20160, 43200],
            'blacklist refresh ttl is longer' => [60, 20160, 20160],
            'refresh ttl is unlimited' => [null, 20160, null],
        ];
    }
}
