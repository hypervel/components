<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use ArrayIterator;
use BadMethodCallException;
use DateInterval;
use DateTime;
use DateTimeImmutable;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\KeyWritten;
use Hypervel\Cache\Events\RetrievingManyKeys;
use Hypervel\Cache\Events\WritingKey;
use Hypervel\Cache\Events\WritingManyKeys;
use Hypervel\Cache\FileStore;
use Hypervel\Cache\Lock;
use Hypervel\Cache\NullSentinel;
use Hypervel\Cache\NullStore;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\Repository;
use Hypervel\Cache\TaggableStore;
use Hypervel\Cache\TaggedCache;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\LockTimeoutException;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Carbon;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class CacheRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse($this->getTestDate()));
    }

    public function testGetReturnsValueFromCache()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn('bar');
        $this->assertSame('bar', $repo->get('foo'));
    }

    public function testGetReturnsMultipleValuesFromCacheWhenGivenAnArray()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('many')->once()->with(['foo', 'bar'])->andReturn(['foo' => 'bar', 'bar' => 'baz']);
        $this->assertEquals(['foo' => 'bar', 'bar' => 'baz'], $repo->get(['foo', 'bar']));
    }

    public function testGetReturnsMultipleValuesFromCacheWhenGivenAnArrayWithDefaultValues()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('many')->once()->with(['foo', 'bar'])->andReturn(['foo' => null, 'bar' => 'baz']);
        $this->assertEquals(['foo' => 'default', 'bar' => 'baz'], $repo->get(['foo' => 'default', 'bar']));
    }

    public function testGetReturnsMultipleValuesFromCacheWhenGivenAnArrayOfOneTwoThree()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('many')->once()->with(['one', 'two', 'three'])->andReturn(['one' => null, 'two' => null, 'three' => null]);
        $this->assertEquals(['one' => null, 'two' => null, 'three' => null], $repo->get(['one', 'two', 'three']));
    }

    public function testDefaultValueIsReturned()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->times(2)->andReturn(null);
        $this->assertSame('bar', $repo->get('foo', 'bar'));
        $this->assertSame('baz', $repo->get('boom', function () {
            return 'baz';
        }));
    }

    public function testSettingDefaultCacheTime()
    {
        $repo = $this->getRepository();
        $repo->setDefaultCacheTime(10);
        $this->assertEquals(10, $repo->getDefaultCacheTime());
    }

    public function testHasMethod()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn(null);
        $repo->getStore()->shouldReceive('get')->once()->with('bar')->andReturn('bar');
        $repo->getStore()->shouldReceive('get')->once()->with('baz')->andReturn(false);

        $this->assertTrue($repo->has('bar'));
        $this->assertFalse($repo->has('foo'));
        $this->assertTrue($repo->has('baz'));
    }

    public function testMissingMethod()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn(null);
        $repo->getStore()->shouldReceive('get')->once()->with('bar')->andReturn('bar');

        $this->assertTrue($repo->missing('foo'));
        $this->assertFalse($repo->missing('bar'));
    }

    public function testRememberMethodCallsPutAndReturnsDefault()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->andReturn(null);
        $repo->getStore()->shouldReceive('put')->once()->with('foo', 'bar', 10);
        $result = $repo->remember('foo', 10, function () {
            return 'bar';
        });
        $this->assertSame('bar', $result);

        /*
         * Use Carbon object...
         */
        Carbon::setTestNow(Carbon::now());

        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->times(2)->andReturn(null);
        $repo->getStore()->shouldReceive('put')->once()->with('foo', 'bar', 602);
        $repo->getStore()->shouldReceive('put')->once()->with('baz', 'qux', 598);
        $result = $repo->remember('foo', Carbon::now()->addMinutes(10)->addSeconds(2), function () {
            return 'bar';
        });
        $this->assertSame('bar', $result);
        $result = $repo->remember('baz', Carbon::now()->addMinutes(10)->subSeconds(2), function () {
            return 'qux';
        });
        $this->assertSame('qux', $result);
    }

    public function testRememberForeverMethodCallsForeverAndReturnsDefault()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->andReturn(null);
        $repo->getStore()->shouldReceive('forever')->once()->with('foo', 'bar');
        $result = $repo->rememberForever('foo', function () {
            return 'bar';
        });
        $this->assertSame('bar', $result);
    }

    public function testRememberNullableStoresAndReturnsNonNullValue()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn(null);
        $repo->getStore()->shouldReceive('put')->once()->with('foo', 'bar', 10);

        $result = $repo->rememberNullable('foo', 10, fn () => 'bar');

        $this->assertSame('bar', $result);
    }

    public function testRememberNullableStoresSentinelWhenCallbackReturnsNull()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn(null);
        $repo->getStore()->shouldReceive('put')->once()->with('foo', NullSentinel::VALUE, 10);

        $result = $repo->rememberNullable('foo', 10, fn () => null);

        $this->assertNull($result);
    }

    public function testRememberNullableReturnsNullOnSentinelHitWithoutInvokingCallback()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn(NullSentinel::VALUE);
        $repo->getStore()->shouldNotReceive('put');

        $invoked = false;
        $result = $repo->rememberNullable('foo', 10, function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    public function testRememberNullableReturnsCachedValueOnHit()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn('cached');

        $result = $repo->rememberNullable('foo', 10, fn () => 'new');

        $this->assertSame('cached', $result);
    }

    public function testRememberForeverNullableStoresAndReturnsNonNullValue()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->andReturn(null);
        $repo->getStore()->shouldReceive('forever')->once()->with('foo', 'bar');

        $result = $repo->rememberForeverNullable('foo', fn () => 'bar');

        $this->assertSame('bar', $result);
    }

    public function testRememberForeverNullableStoresSentinelWhenCallbackReturnsNull()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->andReturn(null);
        $repo->getStore()->shouldReceive('forever')->once()->with('foo', NullSentinel::VALUE);

        $result = $repo->rememberForeverNullable('foo', fn () => null);

        $this->assertNull($result);
    }

    public function testRememberForeverNullableReturnsNullOnSentinelHit()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->andReturn(NullSentinel::VALUE);
        $repo->getStore()->shouldNotReceive('forever');

        $result = $repo->rememberForeverNullable('foo', fn () => 'should-not-run');

        $this->assertNull($result);
    }

    public function testSearNullableDelegatesToRememberForeverNullable()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->andReturn(null);
        $repo->getStore()->shouldReceive('forever')->once()->with('foo', NullSentinel::VALUE);

        $result = $repo->searNullable('foo', fn () => null);

        $this->assertNull($result);
    }

    public function testFlexibleNullableStoresAndReturnsNonNullValue()
    {
        $repo = $this->getRepository();
        $repo->getStore()
            ->shouldReceive('many')
            ->once()
            ->with(['foo', 'hypervel:cache:flexible:created:foo'])
            ->andReturn(['foo' => null, 'hypervel:cache:flexible:created:foo' => null]);
        $repo->getStore()
            ->shouldReceive('putMany')
            ->once()
            ->with(m::on(fn ($values) => $values['foo'] === 'bar'), 20);

        $result = $repo->flexibleNullable('foo', [10, 20], fn () => 'bar');

        $this->assertSame('bar', $result);
    }

    public function testFlexibleNullableStoresSentinelWhenCallbackReturnsNull()
    {
        $repo = $this->getRepository();
        $repo->getStore()
            ->shouldReceive('many')
            ->once()
            ->with(['foo', 'hypervel:cache:flexible:created:foo'])
            ->andReturn(['foo' => null, 'hypervel:cache:flexible:created:foo' => null]);
        $repo->getStore()
            ->shouldReceive('putMany')
            ->once()
            ->with(m::on(fn ($values) => $values['foo'] === NullSentinel::VALUE), 20);

        $result = $repo->flexibleNullable('foo', [10, 20], fn () => null);

        $this->assertNull($result);
    }

    public function testFlexibleNullableReturnsNullOnFreshSentinelHit()
    {
        $repo = $this->getRepository();
        $now = Carbon::now()->getTimestamp();

        $repo->getStore()
            ->shouldReceive('many')
            ->once()
            ->with(['foo', 'hypervel:cache:flexible:created:foo'])
            ->andReturn([
                'foo' => NullSentinel::VALUE,
                'hypervel:cache:flexible:created:foo' => $now,
            ]);

        $result = $repo->flexibleNullable('foo', [10, 20], fn () => 'should-not-run');

        $this->assertNull($result);
    }

    public function testFlexibleNullableReturnsValueOnFreshValueHit()
    {
        $repo = $this->getRepository();
        $now = Carbon::now()->getTimestamp();

        $repo->getStore()
            ->shouldReceive('many')
            ->once()
            ->with(['foo', 'hypervel:cache:flexible:created:foo'])
            ->andReturn([
                'foo' => 'cached',
                'hypervel:cache:flexible:created:foo' => $now,
            ]);

        $result = $repo->flexibleNullable('foo', [10, 20], fn () => 'new');

        $this->assertSame('cached', $result);
    }

    public function testMixedUsageGetReturnsNullForCachedNullEntry()
    {
        $repo = new Repository(new ArrayStore(serializesValues: true));
        $repo->rememberNullable('k', 60, fn () => null);

        $this->assertNull($repo->get('k'));
    }

    public function testMixedUsageGetAppliesDefaultForCachedNullEntry()
    {
        $repo = new Repository(new ArrayStore(serializesValues: true));
        $repo->rememberNullable('k', 60, fn () => null);

        $this->assertSame('default', $repo->get('k', 'default'));
    }

    public function testMixedUsageManyReturnsNullForCachedNullEntry()
    {
        $repo = new Repository(new ArrayStore(serializesValues: true));
        $repo->rememberNullable('k', 60, fn () => null);

        $this->assertSame(['k' => null], $repo->many(['k']));
    }

    public function testMixedUsageHasReturnsFalseForCachedNullEntry()
    {
        $repo = new Repository(new ArrayStore(serializesValues: true));
        $repo->rememberNullable('k', 60, fn () => null);

        $this->assertFalse($repo->has('k'));
        $this->assertTrue($repo->missing('k'));
    }

    public function testMixedUsageHasConsistencyBetweenPutNullAndRememberNullable()
    {
        $repoA = new Repository(new ArrayStore(serializesValues: true));
        $repoA->put('k', null, 60);

        $repoB = new Repository(new ArrayStore(serializesValues: true));
        $repoB->rememberNullable('k', 60, fn () => null);

        $this->assertSame($repoA->has('k'), $repoB->has('k'));
        $this->assertFalse($repoA->has('k'));
        $this->assertFalse($repoB->has('k'));
    }

    public function testMixedUsagePlainRememberTreatsCachedSentinelAsHit()
    {
        $repo = new Repository(new ArrayStore(serializesValues: true));
        $repo->rememberNullable('k', 60, fn () => null);

        $invoked = false;
        $result = $repo->remember('k', 60, function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    public function testMixedUsagePlainFlexibleTreatsCachedSentinelAsHit()
    {
        $repo = new Repository(new ArrayStore(serializesValues: true));
        $repo->flexibleNullable('k', [60, 120], fn () => null);

        $invoked = false;
        $result = $repo->flexible('k', [60, 120], function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    public function testNullSentinelRoundTripsThroughStoreWithDefaultSerializableClasses()
    {
        $repo = new Repository(new ArrayStore(serializesValues: true));

        $repo->rememberNullable('k', 60, fn () => null);

        $this->assertSame(NullSentinel::VALUE, $repo->getStore()->get('k'));
        $this->assertNull($repo->get('k'));

        $invoked = false;
        $result = $repo->rememberNullable('k', 60, function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    public function testNullSentinelRoundTripsThroughStoreWithNoAllowedClasses()
    {
        $repo = new Repository(new ArrayStore(serializesValues: true, serializableClasses: false));

        $repo->rememberNullable('k', 60, fn () => null);

        $this->assertSame(NullSentinel::VALUE, $repo->getStore()->get('k'));
        $this->assertNull($repo->get('k'));

        $invoked = false;
        $result = $repo->rememberNullable('k', 60, function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    public function testRememberNullableReRunsCallbackAfterTtlExpiry()
    {
        $repo = new Repository(new ArrayStore(serializesValues: true));

        $repo->rememberNullable('k', 60, fn () => null);

        Carbon::setTestNow(Carbon::now()->addSeconds(61));

        $invoked = false;
        $result = $repo->rememberNullable('k', 60, function () use (&$invoked) {
            $invoked = true;
            return 'fresh';
        });

        $this->assertSame('fresh', $result);
        $this->assertTrue($invoked);
    }

    public function testPutOverwritesCachedNullSentinelWithRealValue()
    {
        $repo = new Repository(new ArrayStore(serializesValues: true));
        $repo->rememberNullable('k', 60, fn () => null);

        $repo->put('k', 'real', 60);

        $this->assertSame('real', $repo->get('k'));
        $this->assertTrue($repo->has('k'));

        $invoked = false;
        $result = $repo->rememberNullable('k', 60, function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertSame('real', $result);
        $this->assertFalse($invoked);
    }

    public function testForgetClearsSentinelAndNextRememberNullableReRunsCallback()
    {
        $repo = new Repository(new ArrayStore(serializesValues: true));
        $repo->rememberNullable('k', 60, fn () => null);

        $repo->forget('k');

        $invoked = false;
        $result = $repo->rememberNullable('k', 60, function () use (&$invoked) {
            $invoked = true;
            return 'fresh';
        });

        $this->assertSame('fresh', $result);
        $this->assertTrue($invoked);
    }

    public function testPuttingMultipleItemsInCache()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('putMany')->once()->with(['foo' => 'bar', 'bar' => 'baz'], 1);
        $repo->put(['foo' => 'bar', 'bar' => 'baz'], 1);
        $this->assertTrue(true);
    }

    public function testSettingMultipleItemsInCacheArray()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('putMany')->once()->with(['foo' => 'bar', 'bar' => 'baz'], 1)->andReturn(true);
        $result = $repo->setMultiple(['foo' => 'bar', 'bar' => 'baz'], 1);
        $this->assertTrue($result);
    }

    public function testSettingMultipleItemsInCacheIterator()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('putMany')->once()->with(['foo' => 'bar', 'bar' => 'baz'], 1)->andReturn(true);
        $result = $repo->setMultiple(new ArrayIterator(['foo' => 'bar', 'bar' => 'baz']), 1);
        $this->assertTrue($result);
    }

    public function testPutWithNullTTLRemembersItemForever()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('forever')->once()->with('foo', 'bar')->andReturn(true);
        $this->assertTrue($repo->put('foo', 'bar'));
    }

    public function testPutWithDatetimeInPastOrZeroSecondsRemovesOldItem()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('put')->never();
        $repo->getStore()->shouldReceive('forget')->twice()->andReturn(true);
        $result = $repo->put('foo', 'bar', Carbon::now()->subMinutes(10));
        $this->assertTrue($result);
        $result = $repo->put('foo', 'bar', Carbon::now());
        $this->assertTrue($result);
    }

    public function testPutManyWithNullTTLRemembersItemsForever()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('forever')->with('foo', 'bar')->andReturn(true);
        $repo->getStore()->shouldReceive('forever')->with('bar', 'baz')->andReturn(true);
        $this->assertTrue($repo->putMany(['foo' => 'bar', 'bar' => 'baz']));
    }

    public function testAddWithStoreFailureReturnsFalse()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('add')->never();
        $repo->getStore()->shouldReceive('get')->andReturn(null);
        $repo->getStore()->shouldReceive('put')->andReturn(false);
        $this->assertFalse($repo->add('foo', 'bar', 60));
    }

    public function testCacheAddCallsRedisStoreAdd()
    {
        $store = m::mock(RedisStore::class);
        $store->shouldReceive('add')->once()->with('k', 'v', 60)->andReturn(true);
        $repository = new Repository($store);
        $this->assertTrue($repository->add('k', 'v', 60));
    }

    public function testAddMethodCanAcceptDateIntervals()
    {
        $storeWithAdd = m::mock(RedisStore::class);
        $storeWithAdd->shouldReceive('add')->once()->with('k', 'v', 61)->andReturn(true);
        $repository = new Repository($storeWithAdd);
        $this->assertTrue($repository->add('k', 'v', DateInterval::createFromDateString('61 seconds')));

        $storeWithoutAdd = m::mock(ArrayStore::class);
        $this->assertFalse(method_exists(ArrayStore::class, 'add'), 'This store should not have add method on it.');
        $storeWithoutAdd->shouldReceive('get')->once()->with('k')->andReturn(null);
        $storeWithoutAdd->shouldReceive('put')->once()->with('k', 'v', 60)->andReturn(true);
        $repository = new Repository($storeWithoutAdd);
        $this->assertTrue($repository->add('k', 'v', DateInterval::createFromDateString('60 seconds')));
    }

    public function testAddMethodCanAcceptDateTimeInterface()
    {
        $withAddStore = m::mock(RedisStore::class);
        $withAddStore->shouldReceive('add')->once()->with('k', 'v', 61)->andReturn(true);
        $repository = new Repository($withAddStore);
        $this->assertTrue($repository->add('k', 'v', Carbon::now()->addSeconds(61)));

        $noAddStore = m::mock(ArrayStore::class);
        $this->assertFalse(method_exists(ArrayStore::class, 'add'), 'This store should not have add method on it.');
        $noAddStore->shouldReceive('get')->once()->with('k')->andReturn(null);
        $noAddStore->shouldReceive('put')->once()->with('k', 'v', 62)->andReturn(true);
        $repository = new Repository($noAddStore);
        $this->assertTrue($repository->add('k', 'v', Carbon::now()->addSeconds(62)));
    }

    public function testAddWithNullTTLRemembersItemForever()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn(null);
        $repo->getStore()->shouldReceive('forever')->once()->with('foo', 'bar')->andReturn(true);
        $this->assertTrue($repo->add('foo', 'bar'));
    }

    public function testAddWithDatetimeInPastOrZeroSecondsReturnsImmediately()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('add', 'get', 'put')->never();
        $result = $repo->add('foo', 'bar', Carbon::now()->subMinutes(10));
        $this->assertFalse($result);
        $result = $repo->add('foo', 'bar', Carbon::now());
        $this->assertFalse($result);
        $result = $repo->add('foo', 'bar', -1);
        $this->assertFalse($result);
    }

    #[DataProvider('dataProviderTestGetSeconds')]
    public function testGetSeconds($duration)
    {
        Carbon::setTestNow(Carbon::parse($this->getTestDate()));

        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('put')->once()->with($key = 'foo', $value = 'bar', 300);
        $repo->put($key, $value, $duration);

        $this->assertTrue(true);
    }

    public static function dataProviderTestGetSeconds()
    {
        Carbon::setTestNow(Carbon::parse(self::getTestDate()));

        return [
            [Carbon::parse(self::getTestDate())->addMinutes(5)],
            [(new DateTime(self::getTestDate()))->modify('+5 minutes')],
            [(new DateTimeImmutable(self::getTestDate()))->modify('+5 minutes')],
            [new DateInterval('PT5M')],
            [300],
        ];
    }

    public function testGetSecondsCeilsSubSecondTtl()
    {
        Carbon::setTestNow(Carbon::parse($this->getTestDate()));

        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('put')->once()->with('foo', 'bar', 1);
        $repo->put('foo', 'bar', Carbon::parse($this->getTestDate())->addMilliseconds(400));

        $this->assertTrue(true);
    }

    public function testRegisterMacroWithNonStaticCall()
    {
        $repo = $this->getRepository();
        $repo::macro(__CLASS__, function () {
            return 'Taylor';
        });
        $this->assertSame('Taylor', $repo->{__CLASS__}());
    }

    public function testForgettingCacheKey()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('forget')->once()->with('a-key')->andReturn(true);
        $repo->forget('a-key');

        $this->assertTrue(true);
    }

    public function testRemovingCacheKey()
    {
        // Alias of Forget
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('forget')->once()->with('a-key')->andReturn(true);
        $repo->delete('a-key');
        $this->assertTrue(true);
    }

    public function testSettingCache()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('put')->with($key = 'foo', $value = 'bar', 1)->andReturn(true);
        $result = $repo->set($key, $value, 1);
        $this->assertTrue($result);
    }

    public function testClearingWholeCache()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('flush')->andReturn(true);
        $repo->clear();

        $this->assertTrue(true);
    }

    public function testGettingMultipleValuesFromCache()
    {
        $keys = ['key1', 'key2', 'key3'];
        $default = 5;

        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('many')->once()->with(['key1', 'key2', 'key3'])->andReturn(['key1' => 1, 'key2' => null, 'key3' => null]);
        $this->assertEquals(['key1' => 1, 'key2' => 5, 'key3' => 5], $repo->getMultiple($keys, $default));
    }

    public function testRemovingMultipleKeys()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('forget')->once()->with('a-key')->andReturn(true);
        $repo->getStore()->shouldReceive('forget')->once()->with('a-second-key')->andReturn(true);

        $this->assertTrue($repo->deleteMultiple(['a-key', 'a-second-key']));
    }

    public function testRemovingMultipleKeysFailsIfOneFails()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('forget')->once()->with('a-key')->andReturn(true);
        $repo->getStore()->shouldReceive('forget')->once()->with('a-second-key')->andReturn(false);

        $this->assertFalse($repo->deleteMultiple(['a-key', 'a-second-key']));
    }

    public function testAllTagsArePassedToTaggableStore()
    {
        $store = m::mock(ArrayStore::class);
        $repo = new Repository($store);

        $taggedCache = m::mock(TaggedCache::class);
        $taggedCache->shouldReceive('setDefaultCacheTime');
        $store->shouldReceive('tags')->once()->with(['foo', 'bar', 'baz'])->andReturn($taggedCache);
        $repo->tags('foo', 'bar', 'baz');

        $this->assertTrue(true);
    }

    public function testItThrowsExceptionWhenStoreDoesNotSupportTags()
    {
        $this->expectException(BadMethodCallException::class);

        $store = new FileStore(new Filesystem, '/usr');
        $this->assertFalse(method_exists($store, 'tags'), 'Store should not support tagging.');
        (new Repository($store))->tags('foo');
    }

    public function testTagMethodReturnsTaggedCache()
    {
        $store = (new Repository(new ArrayStore))->tags('foo');

        $this->assertInstanceOf(TaggedCache::class, $store);
    }

    public function testPossibleInputTypesToTags()
    {
        $repo = new Repository(new ArrayStore);

        $store = $repo->tags('foo');
        $this->assertEquals(['foo'], $store->getTags()->getNames());

        $store = $repo->tags(['foo!', 'Kangaroo']);
        $this->assertEquals(['foo!', 'Kangaroo'], $store->getTags()->getNames());

        $store = $repo->tags('r1', 'r2', 'r3');
        $this->assertEquals(['r1', 'r2', 'r3'], $store->getTags()->getNames());
    }

    public function testEventDispatcherIsPassedToStoreFromRepository()
    {
        $repo = new Repository(new ArrayStore);
        $repo->setEventDispatcher(m::mock(Dispatcher::class));

        $store = $repo->tags('foo');

        $this->assertSame($store->getEventDispatcher(), $repo->getEventDispatcher());
    }

    public function testDefaultCacheLifeTimeIsSetOnTaggableStore()
    {
        $repo = new Repository(new ArrayStore);
        $repo->setDefaultCacheTime(random_int(1, 100));

        $store = $repo->tags('foo');

        $this->assertSame($store->getDefaultCacheTime(), $repo->getDefaultCacheTime());
    }

    public function testFlushLocksDelegatesToStore()
    {
        $flushable = m::mock(RedisStore::class);
        $flushable->shouldReceive('flushLocks')->once()->andReturn(true);

        $repo = new Repository($flushable);

        $this->assertTrue($repo->flushLocks());
    }

    public function testTaggableRepositoriesSupportTags()
    {
        $taggable = m::mock(TaggableStore::class);
        $taggableRepo = new Repository($taggable);

        $this->assertTrue($taggableRepo->supportsTags());
    }

    public function testNonTaggableRepositoryDoesNotSupportTags()
    {
        $nonTaggable = m::mock(FileStore::class);
        $nonTaggableRepo = new Repository($nonTaggable);

        $this->assertFalse($nonTaggableRepo->supportsTags());
    }

    public function testFlushableLockRepositorySupportsFlushingLocks()
    {
        $flushable = m::mock(RedisStore::class);
        $flushableRepo = new Repository($flushable);

        $this->assertTrue($flushableRepo->supportsFlushingLocks());
    }

    public function testNonFlushableLockRepositoryDoesNotSupportFlushingLocks()
    {
        $nonFlushable = m::mock(NullStore::class);
        $nonFlushableRepo = new Repository($nonFlushable);

        $this->assertFalse($nonFlushableRepo->supportsFlushingLocks());
    }

    public function testItThrowsExceptionWhenStoreDoesNotSupportFlushingLocks()
    {
        $this->expectException(BadMethodCallException::class);

        $nonFlushable = m::mock(NullStore::class);
        $nonFlushableRepo = new Repository($nonFlushable);

        $nonFlushableRepo->flushLocks();
    }

    public function testTouchWithNullTTLRemembersItemForever()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->with('key')->andReturn('bar');
        $repo->getStore()->shouldReceive('forever')->once()->with('key', 'bar')->andReturn(true);
        $this->assertTrue($repo->touch('key', null));
    }

    public function testTouchWithSecondsTtlCorrectlyProxiesToStore()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->with('key')->andReturn('bar');
        $repo->getStore()->shouldReceive('touch')->once()->with('key', 60)->andReturn(true);
        $this->assertTrue($repo->touch('key', 60));
    }

    public function testTouchWithDatetimeTtlCorrectlyProxiesToStore()
    {
        Carbon::setTestNow($now = Carbon::now());

        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->with('key')->andReturn('bar');
        $repo->getStore()->shouldReceive('touch')->once()->with('key', 60)->andReturn(true);
        $this->assertTrue($repo->touch('key', $now->addSeconds(60)));
    }

    public function testTouchWithDateIntervalTtlCorrectlyProxiesToStore()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->with('key')->andReturn('bar');
        $repo->getStore()->shouldReceive('touch')->once()->with('key', 60)->andReturn(true);
        $this->assertTrue($repo->touch('key', DateInterval::createFromDateString('60 seconds')));
    }

    public function testAtomicExecutesCallbackAndReturnsResult()
    {
        $repo = new Repository(new ArrayStore);

        $result = $repo->withoutOverlapping('foo', function () {
            return 'bar';
        });

        $this->assertSame('bar', $result);
    }

    public function testAtomicPassesLockAndWaitSecondsToLock()
    {
        $store = m::mock(Store::class, LockProvider::class);
        $repo = new Repository($store);
        $lock = m::mock(Lock::class);

        $store->shouldReceive('lock')->once()->with('foo', 30, null)->andReturn($lock);
        $lock->shouldReceive('block')->once()->with(15, m::type('callable'))->andReturnUsing(function ($seconds, $callback) {
            return $callback();
        });

        $result = $repo->withoutOverlapping('foo', function () {
            return 'bar';
        }, 30, 15);

        $this->assertSame('bar', $result);
    }

    public function testAtomicPassesOwnerToLock()
    {
        $store = m::mock(Store::class, LockProvider::class);
        $repo = new Repository($store);
        $lock = m::mock(Lock::class);

        $store->shouldReceive('lock')->once()->with('foo', 10, 'my-owner')->andReturn($lock);
        $lock->shouldReceive('block')->once()->with(10, m::type('callable'))->andReturnUsing(function ($seconds, $callback) {
            return $callback();
        });

        $result = $repo->withoutOverlapping('foo', function () {
            return 'bar';
        }, 10, 10, 'my-owner');

        $this->assertSame('bar', $result);
    }

    public function testAtomicThrowsOnLockTimeout()
    {
        $repo = new Repository(new ArrayStore);

        $repo->getStore()->lock('foo', 10)->acquire();

        $called = false;

        try {
            $repo->withoutOverlapping('foo', function () use (&$called) {
                $called = true;
            }, 10, 0);

            $this->fail('Expected LockTimeoutException was not thrown.');
        } catch (LockTimeoutException) {
            $this->assertFalse($called);
        }
    }

    public function testTaggedCacheWorksWithEnumKey()
    {
        $cache = (new Repository(new ArrayStore))->tags('test-tag');

        $cache->put(TestCacheKey::Foo, 5);
        $this->assertSame(6, $cache->increment(TestCacheKey::Foo));
        $this->assertSame(5, $cache->decrement(TestCacheKey::Foo));
    }

    public function testPutManyHandlesIntegerArrayKeys()
    {
        $repo = new Repository(new ArrayStore);

        // Null TTL path (putManyForever)
        $repo->putMany([2 => 'integer-value', 'a' => 'string-value']);

        $this->assertSame('integer-value', $repo->get('2'));
        $this->assertSame('string-value', $repo->get('a'));

        // Finite TTL path (store->putMany via RetrievesMultipleKeys)
        $repo->putMany([3 => 'another-int', 'b' => 'another-string'], 60);

        $this->assertSame('another-int', $repo->get('3'));
        $this->assertSame('another-string', $repo->get('b'));
    }

    public function testTaggedPutManyHandlesIntegerArrayKeys()
    {
        $repo = (new Repository(new ArrayStore))->tags('test-tag');

        $repo->putMany([2 => 'integer-value', 'a' => 'string-value']);

        $this->assertSame('integer-value', $repo->get('2'));
        $this->assertSame('string-value', $repo->get('a'));
    }

    public function testStringTypedGetter()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn('bar');

        $this->assertSame('bar', $repo->string('foo'));
    }

    public function testStringTypedGetterThrowsExceptionForNonString()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache value for key [foo] must be a string, integer given.');

        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn(1);

        $repo->string('foo');
    }

    public function testStringTypedGetterReturnsDefaultWhenKeyNotFound()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn('default');

        $this->assertSame('default', $repo->string('foo', 'default'));
    }

    public function testIntegerTypedGetter()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn(42);

        $this->assertSame(42, $repo->integer('foo'));
    }

    public function testIntegerTypedGetterParsesNumericString()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn('123');

        $this->assertSame(123, $repo->integer('foo'));
    }

    public function testIntegerTypedGetterThrowsExceptionForNonInteger()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache value for key [foo] must be an integer, array given.');

        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn(['bar']);

        $repo->integer('foo');
    }

    public function testIntegerTypedGetterReturnsDefaultWhenKeyNotFound()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn(100);

        $this->assertSame(100, $repo->integer('foo', 100));
    }

    public function testItThrowsExceptionWhenGettingFloatStringAsInteger()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache value for key [foo] must be an integer, string given.');

        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn('1.5');
        $repo->integer('foo');
    }

    public function testFloatTypedGetter()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn(3.14);

        $this->assertSame(3.14, $repo->float('foo'));
    }

    public function testFloatTypedGetterParsesNumericString()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn('3.14');

        $this->assertSame(3.14, $repo->float('foo'));
    }

    public function testFloatTypedGetterThrowsExceptionForNonFloat()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache value for key [foo] must be a float, array given.');

        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn(['bar']);

        $repo->float('foo');
    }

    public function testFloatTypedGetterReturnsDefaultWhenKeyNotFound()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn(2.5);

        $this->assertSame(2.5, $repo->float('foo', 2.5));
    }

    public function testBooleanTypedGetter()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn(true);

        $this->assertTrue($repo->boolean('foo'));
    }

    public function testBooleanTypedGetterReturnsFalse()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn(false);

        $this->assertFalse($repo->boolean('foo'));
    }

    public function testBooleanTypedGetterThrowsExceptionForNonBoolean()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache value for key [foo] must be a boolean, string given.');

        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn('true');

        $repo->boolean('foo');
    }

    public function testBooleanTypedGetterReturnsDefaultWhenKeyNotFound()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn(true);

        $this->assertTrue($repo->boolean('foo', true));
    }

    public function testArrayTypedGetter()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn(['bar', 'baz']);

        $this->assertSame(['bar', 'baz'], $repo->array('foo'));
    }

    public function testArrayTypedGetterReturnsAssociativeArray()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn(['key' => 'value']);

        $this->assertSame(['key' => 'value'], $repo->array('foo'));
    }

    public function testArrayTypedGetterThrowsExceptionForNonArray()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache value for key [foo] must be an array, string given.');

        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn('bar');

        $repo->array('foo');
    }

    public function testArrayTypedGetterReturnsDefaultWhenKeyNotFound()
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('foo')->andReturn(['default']);

        $this->assertSame(['default'], $repo->array('foo', ['default']));
    }

    public function testRememberFiresEventsWithPinnableStore()
    {
        $store = m::mock(RedisStore::class);
        $store->shouldReceive('withPinnedConnection')
            ->once()
            ->andReturnUsing(fn (callable $callback) => $callback());
        $store->shouldReceive('get')->once()->with('foo')->andReturn(null);
        $store->shouldReceive('put')->once()->with('foo', 'bar', 10);

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturn(true);
        $events->shouldReceive('dispatch')->times(4); // RetrievingKey, CacheMissed, WritingKey, KeyWritten

        $repository = new Repository($store);
        $repository->setEventDispatcher($events);

        $result = $repository->remember('foo', 10, fn () => 'bar');

        $this->assertSame('bar', $result);
    }

    public function testRememberForeverFiresEventsWithPinnableStore()
    {
        $store = m::mock(RedisStore::class);
        $store->shouldReceive('withPinnedConnection')
            ->once()
            ->andReturnUsing(fn (callable $callback) => $callback());
        $store->shouldReceive('get')->once()->with('foo')->andReturn(null);
        $store->shouldReceive('forever')->once()->with('foo', 'bar');

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturn(true);
        $events->shouldReceive('dispatch')->times(4); // RetrievingKey, CacheMissed, WritingKey, KeyWritten

        $repository = new Repository($store);
        $repository->setEventDispatcher($events);

        $result = $repository->rememberForever('foo', fn () => 'bar');

        $this->assertSame('bar', $result);
    }

    public function testRememberHitFiresEventsWithPinnableStore()
    {
        $store = m::mock(RedisStore::class);
        $store->shouldReceive('withPinnedConnection')
            ->once()
            ->andReturnUsing(fn (callable $callback) => $callback());
        $store->shouldReceive('get')->once()->with('foo')->andReturn('cached');

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturn(true);
        $events->shouldReceive('dispatch')->twice(); // RetrievingKey, CacheHit

        $repository = new Repository($store);
        $repository->setEventDispatcher($events);

        $result = $repository->remember('foo', 10, fn () => 'bar');

        $this->assertSame('cached', $result);
    }

    public function testRememberSkipsDispatchWhenCacheEventsHaveNoListeners()
    {
        $store = m::mock(RedisStore::class);
        $store->shouldReceive('withPinnedConnection')
            ->once()
            ->andReturnUsing(fn (callable $callback) => $callback());
        $store->shouldReceive('get')->once()->with('foo')->andReturn(null);
        $store->shouldReceive('put')->once()->with('foo', 'bar', 10);

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->times(4)->andReturn(false);
        $events->shouldNotReceive('dispatch');

        $repository = new Repository($store);
        $repository->setEventDispatcher($events);

        $result = $repository->remember('foo', 10, fn () => 'bar');

        $this->assertSame('bar', $result);
    }

    public function testRememberNullableFiresEventsWithNullPayloadOnCacheMiss()
    {
        $store = m::mock(RedisStore::class);
        $store->shouldReceive('withPinnedConnection')
            ->once()
            ->andReturnUsing(fn (callable $callback) => $callback());
        $store->shouldReceive('get')->once()->with('foo')->andReturn(null);
        $store->shouldReceive('put')->once()->with('foo', NullSentinel::VALUE, 10)->andReturn(true);

        $captured = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturn(true);
        $events->shouldReceive('dispatch')
            ->andReturnUsing(function ($event) use (&$captured) {
                $captured[] = $event;
            });

        $repository = new Repository($store);
        $repository->setEventDispatcher($events);

        $result = $repository->rememberNullable('foo', 10, fn () => null);

        $this->assertNull($result);

        // Miss path fires four events: RetrievingKey, CacheMissed, WritingKey, KeyWritten.
        $this->assertCount(4, $captured);

        $writingKey = array_values(array_filter($captured, fn ($e) => $e instanceof WritingKey))[0] ?? null;
        $keyWritten = array_values(array_filter($captured, fn ($e) => $e instanceof KeyWritten))[0] ?? null;

        $this->assertNotNull($writingKey);
        $this->assertNotNull($keyWritten);
        // Null, not the sentinel value.
        $this->assertNull($writingKey->value);
        $this->assertNull($keyWritten->value);
    }

    public function testRememberForeverNullableFiresEventsWithNullPayloadOnCacheMiss()
    {
        $store = m::mock(RedisStore::class);
        $store->shouldReceive('withPinnedConnection')
            ->once()
            ->andReturnUsing(fn (callable $callback) => $callback());
        $store->shouldReceive('get')->once()->with('foo')->andReturn(null);
        $store->shouldReceive('forever')->once()->with('foo', NullSentinel::VALUE)->andReturn(true);

        $captured = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturn(true);
        $events->shouldReceive('dispatch')
            ->andReturnUsing(function ($event) use (&$captured) {
                $captured[] = $event;
            });

        $repository = new Repository($store);
        $repository->setEventDispatcher($events);

        $result = $repository->rememberForeverNullable('foo', fn () => null);

        $this->assertNull($result);

        // Miss path fires four events: RetrievingKey, CacheMissed, WritingKey, KeyWritten.
        $this->assertCount(4, $captured);

        $writingKey = array_values(array_filter($captured, fn ($e) => $e instanceof WritingKey))[0] ?? null;
        $keyWritten = array_values(array_filter($captured, fn ($e) => $e instanceof KeyWritten))[0] ?? null;

        $this->assertNotNull($writingKey);
        $this->assertNotNull($keyWritten);
        // Null, not the sentinel value.
        $this->assertNull($writingKey->value);
        $this->assertNull($keyWritten->value);
    }

    public function testFlexibleNullableFiresEventsWithNullPayloadOnCacheMiss()
    {
        $repo = $this->getRepository();
        $repo->getStore()
            ->shouldReceive('many')
            ->once()
            ->with(['foo', 'hypervel:cache:flexible:created:foo'])
            ->andReturn(['foo' => null, 'hypervel:cache:flexible:created:foo' => null]);
        $repo->getStore()
            ->shouldReceive('putMany')
            ->once()
            ->andReturn(true);

        $captured = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturn(true);
        $events->shouldReceive('dispatch')
            ->andReturnUsing(function ($event) use (&$captured) {
                $captured[] = $event;
            });
        $repo->setEventDispatcher($events);

        $result = $repo->flexibleNullable('foo', [10, 20], fn () => null);

        $this->assertNull($result);

        // WritingManyKeys carries an aligned keys/values pair, so we look up the
        // value-key's entry by its index in the keys array.
        $writingMany = array_values(array_filter($captured, fn ($e) => $e instanceof WritingManyKeys))[0] ?? null;
        $this->assertNotNull($writingMany);
        $valueKeyIndex = array_search('foo', $writingMany->keys, true);
        $this->assertNotFalse($valueKeyIndex);
        // Null, not the sentinel value.
        $this->assertNull($writingMany->values[$valueKeyIndex]);

        // The marker key's KeyWritten carries a real timestamp, so filter to the
        // value key's KeyWritten specifically.
        $valueKeyWritten = array_values(array_filter(
            $captured,
            fn ($e) => $e instanceof KeyWritten && $e->key === 'foo'
        ))[0] ?? null;
        $this->assertNotNull($valueKeyWritten);
        // Null, not the sentinel value.
        $this->assertNull($valueKeyWritten->value);
    }

    public function testRememberNullableFiresHitEventWithNullPayloadOnSentinelRetrieval()
    {
        $store = m::mock(RedisStore::class);
        $store->shouldReceive('withPinnedConnection')
            ->once()
            ->andReturnUsing(fn (callable $callback) => $callback());
        $store->shouldReceive('get')->once()->with('foo')->andReturn(NullSentinel::VALUE);

        $captured = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturn(true);
        $events->shouldReceive('dispatch')
            ->andReturnUsing(function ($event) use (&$captured) {
                $captured[] = $event;
            });

        $repository = new Repository($store);
        $repository->setEventDispatcher($events);

        $result = $repository->rememberNullable('foo', 10, fn () => 'should-not-run');

        $this->assertNull($result);

        // Hit path fires two events: RetrievingKey, CacheHit.
        $this->assertCount(2, $captured);

        $cacheHit = array_values(array_filter($captured, fn ($e) => $e instanceof CacheHit))[0] ?? null;
        $this->assertNotNull($cacheHit);
        // Null, not the sentinel value.
        $this->assertNull($cacheHit->value);
    }

    public function testManyFiresCacheHitWithNullPayloadForCachedNullEntry()
    {
        // Real ArrayStore so the sentinel genuinely round-trips through serialize.
        $repo = new Repository(new ArrayStore(serializesValues: true));
        $repo->rememberNullable('k', 60, fn () => null);

        // Capture only the many() read-path events by attaching the dispatcher
        // after the write.
        $captured = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturn(true);
        $events->shouldReceive('dispatch')
            ->andReturnUsing(function ($event) use (&$captured) {
                $captured[] = $event;
            });
        $repo->setEventDispatcher($events);

        $result = $repo->many(['k']);

        $this->assertSame(['k' => null], $result);
        $this->assertCount(2, $captured);
        $this->assertInstanceOf(RetrievingManyKeys::class, $captured[0]);
        $this->assertInstanceOf(CacheHit::class, $captured[1]);
        // Null, not the sentinel value.
        $this->assertNull($captured[1]->value);
        $this->assertEmpty(array_filter($captured, fn ($e) => $e instanceof CacheMissed));
    }

    public function testRememberWorksWithoutPinnableStore()
    {
        $store = m::mock(ArrayStore::class);
        $store->shouldReceive('get')->once()->with('foo')->andReturn(null);
        $store->shouldReceive('put')->once()->with('foo', 'bar', 10);

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturn(true);
        $events->shouldReceive('dispatch')->with(m::any())->andReturnNull();

        $repository = new Repository($store);
        $repository->setEventDispatcher($events);

        $result = $repository->remember('foo', 10, fn () => 'bar');

        $this->assertSame('bar', $result);
    }

    protected function getRepository()
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('hasListeners')->withAnyArgs()->andReturn(true);
        $dispatcher->shouldReceive('dispatch')->with(m::any())->andReturnNull();
        $repository = new Repository(m::mock(Store::class));

        $repository->setEventDispatcher($dispatcher);

        return $repository;
    }

    protected static function getTestDate()
    {
        return '2030-07-25 12:13:14 UTC';
    }
}

enum TestCacheKey: string
{
    case Foo = 'foo';
}
