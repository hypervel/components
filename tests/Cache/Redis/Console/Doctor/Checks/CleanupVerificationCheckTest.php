<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Console\Doctor\Checks;

use Generator;
use Hypervel\Cache\Redis\Console\Doctor\Checks\CleanupVerificationCheck;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\Repository;
use Hypervel\Cache\TagMode;
use Hypervel\Redis\PhpRedisClusterConnection;
use Hypervel\Tests\TestCase;
use Mockery as m;

class CleanupVerificationCheckTest extends TestCase
{
    public function testVerificationScansLogicalPatternsThroughTheClusterAwareHelper(): void
    {
        $connection = m::mock(PhpRedisClusterConnection::class);
        $store = m::mock(RedisStore::class);
        $store->allows('getTagMode')->andReturn(TagMode::All);
        $context = new DoctorContext(
            cache: new Repository($store),
            store: $store,
            redis: $connection,
            cachePrefix: 'cache:',
            storeName: 'redis',
        );

        foreach ([
            'cache:_doctor:test:*',
            'cache:*:_doctor:test:*',
            'cache:_any:tag:_doctor:test:*',
            'cache:_all:tag:_doctor:test:*',
        ] as $pattern) {
            $connection->expects('safeScan')->with($pattern, 100)
                ->andReturnUsing(static function () use ($pattern): Generator {
                    if ($pattern === 'cache:_doctor:test:*') {
                        yield 'cache:_doctor:test:leftover';
                    }
                });
        }

        $result = (new CleanupVerificationCheck)->run($context);

        $this->assertFalse($result->passed());
        $this->assertSame([
            'Cleanup incomplete - 1 test key(s) remain: cache:_doctor:test:leftover',
        ], $result->failures());
    }
}
