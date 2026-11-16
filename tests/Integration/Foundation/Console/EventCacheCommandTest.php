<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Support\Providers\EventServiceProvider;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use RuntimeException;

class EventCacheCommandTest extends TestCase
{
    use VerifiesDoubles;

    protected function tearDown(): void
    {
        @unlink($this->app->getCachedEventsPath());

        parent::tearDown();
    }

    public function testEventsAreCachedAtomically(): void
    {
        $files = Double::for(new Filesystem)->passthru();
        $files->expects('put')->never();

        $this->app->instance('files', $files);

        $this->artisan('event:cache')
            ->expectsOutputToContain('Events cached successfully.')
            ->assertSuccessful();

        $this->assertFileExists($this->app->getCachedEventsPath());

        $files->received('replace')->times(1)->with($this->app->getCachedEventsPath(), Argument::type('string'), null);
    }

    public function testCachedFileContainsProviderEvents(): void
    {
        $this->artisan('event:cache')
            ->assertSuccessful();

        $cached = require $this->app->getCachedEventsPath();

        $this->assertIsArray($cached);
    }

    public function testEventsCacheCommandReplacesOldCache(): void
    {
        $cachePath = $this->app->getCachedEventsPath();
        $cacheDir = dirname($cachePath);

        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        file_put_contents($cachePath, '<?php return ["stale" => true];');

        $this->artisan('event:cache')
            ->assertSuccessful();

        $cached = require $cachePath;

        $this->assertArrayNotHasKey('stale', $cached);
    }

    public function testExistingEventCacheSurvivesDiscoveryFailure(): void
    {
        $cachePath = $this->app->getCachedEventsPath();
        $previousContents = '<?php return ["stale" => true];';
        file_put_contents($cachePath, $previousContents);
        $this->app->register(new FailingEventDiscoveryProvider($this->app));

        try {
            $this->artisan('event:cache');
            $this->fail('Event discovery should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('discovery failed', $exception->getMessage());
        }

        $this->assertSame($previousContents, file_get_contents($cachePath));
    }

    public function testEventCacheReplacementPreservesExistingMode(): void
    {
        $cachePath = $this->app->getCachedEventsPath();
        file_put_contents($cachePath, '<?php return ["stale" => true];');
        chmod($cachePath, 0640);

        $this->artisan('event:cache')->assertSuccessful();

        $this->assertSame(0640, fileperms($cachePath) & 0777);
    }

    public function testExistingEventCacheSurvivesPublicationFailure(): void
    {
        $cachePath = $this->app->getCachedEventsPath();
        $previousContents = '<?php return ["stale" => true];';
        file_put_contents($cachePath, $previousContents);
        chmod($cachePath, 0640);

        $publicationException = new RuntimeException('publication failed');
        $files = Double::for(new Filesystem)->passthru();
        $files->expects('replace')
            ->with($cachePath, Argument::type('string'), 0640)
            ->throws($publicationException);
        $this->app->instance('files', $files);

        try {
            $this->artisan('event:cache');
            $this->fail('Publication should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame($publicationException, $exception);
        }

        $this->assertSame($previousContents, file_get_contents($cachePath));
        $this->assertSame(0640, fileperms($cachePath) & 0777);
    }
}

class FailingEventDiscoveryProvider extends EventServiceProvider
{
    public function shouldDiscoverEvents(): bool
    {
        return true;
    }

    public function discoverEvents(): array
    {
        throw new RuntimeException('discovery failed');
    }
}
