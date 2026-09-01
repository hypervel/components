<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Auth\AuthServiceProvider;
use Hypervel\Cache\CacheServiceProvider;
use Hypervel\Support\DefaultProviders;
use Hypervel\Tests\TestCase;

class DefaultProvidersTest extends TestCase
{
    public function testExplicitEmptyProviderListIsPreserved(): void
    {
        self::assertSame([], (new DefaultProviders([]))->toArray());
    }

    public function testMergeReturnsNewCollectionWithoutMutatingReceiver(): void
    {
        $providers = new DefaultProviders([AuthServiceProvider::class]);
        $merged = $providers->merge([CacheServiceProvider::class]);

        self::assertNotSame($providers, $merged);
        self::assertSame([AuthServiceProvider::class], $providers->toArray());
        self::assertSame([
            AuthServiceProvider::class,
            CacheServiceProvider::class,
        ], $merged->toArray());
    }

    public function testExceptCanRemoveEveryProviderWithoutMutatingReceiver(): void
    {
        $providerClasses = [
            AuthServiceProvider::class,
            CacheServiceProvider::class,
        ];
        $providers = new DefaultProviders($providerClasses);
        $remaining = $providers->except($providerClasses);

        self::assertNotSame($providers, $remaining);
        self::assertSame($providerClasses, $providers->toArray());
        self::assertSame([], $remaining->toArray());
    }
}
