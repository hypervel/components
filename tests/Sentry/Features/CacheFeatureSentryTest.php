<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Sentry\Features\CacheFeature;
use Hypervel\Session\Contracts\Session as SessionContract;
use Hypervel\Support\Facades\Cache;
use Hypervel\Tests\Sentry\SentryTestCase;
use Sentry\SentrySdk;

/**
 * @internal
 * @coversNothing
 */
class CacheFeatureSentryTest extends SentryTestCase
{
    use RunTestsInCoroutine;

    protected array $defaultSetupConfig = [
        'sentry.breadcrumbs.cache' => true,
        'sentry.tracing.cache' => true,
        'sentry.features' => [
            CacheFeature::class,
        ],
    ];

    public function testCacheSetAndGet(): void
    {
        Cache::put($key = 'foo', 'bar');
        $this->assertEquals("Written: {$key}", $this->getLastSentryBreadcrumb()->getMessage());

        Cache::get('foo');

        $this->assertEquals("Read: {$key}", $this->getLastSentryBreadcrumb()->getMessage());
    }

    public function testCacheBreadcrumbForWriteAndForgetIsRecorded(): void
    {
        Cache::put($key = 'foo', 'bar');

        $this->assertEquals("Written: {$key}", $this->getLastSentryBreadcrumb()->getMessage());

        Cache::forget($key);

        $this->assertEquals("Forgotten: {$key}", $this->getLastSentryBreadcrumb()->getMessage());
    }

    public function testCacheBreadcrumbForMissIsRecorded(): void
    {
        Cache::get($key = 'foo');

        $this->assertEquals("Missed: {$key}", $this->getLastSentryBreadcrumb()->getMessage());
    }

    public function testCacheBreadcrumbIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.cache' => false,
        ]);

        $this->assertFalse($this->app['config']->get('sentry.breadcrumbs.cache'));

        Cache::get('foo');

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    public function testCacheBreadcrumbReplacesSessionKeyWithPlaceholder(): void
    {
        // Start a session properly in the test environment
        $this->startSession();
        $this->app->get(SessionContract::class)->setId($sessionId = 'my-session-id');

        // Use the session ID as a cache key
        Cache::put($sessionId, 'session-data');

        $breadcrumb = $this->getLastSentryBreadcrumb();
        $this->assertEquals("Written: {$sessionId}", $breadcrumb->getMessage());

        Cache::get($sessionId);

        $breadcrumb = $this->getLastSentryBreadcrumb();
        $this->assertEquals("Read: {$sessionId}", $breadcrumb->getMessage());
    }

    public function testCacheBreadcrumbDoesNotReplaceNonSessionKeys(): void
    {
        Cache::put('regular-key', 'value');

        $breadcrumb = $this->getLastSentryBreadcrumb();
        $this->assertEquals('Written: regular-key', $breadcrumb->getMessage());
    }
}
