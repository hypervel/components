<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\Storage\DatabaseEntriesRepository;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\TelescopeServiceProvider;
use InvalidArgumentException;
use ReflectionProperty;

class TelescopeServiceProviderTest extends FeatureTestCase
{
    protected bool $yieldBeforeTelescopeContextPropagation = false;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        // This runs before package providers boot, so the callback precedes Telescope's.
        Coroutine::afterCreated(function (): void {
            if ($this->yieldBeforeTelescopeContextPropagation) {
                Coroutine::sleep(0.01);
            }
        });

        parent::defineEnvironment($app);
    }

    public function testForkPreservesCapturedTelescopeContext(): void
    {
        CoroutineContext::set(Telescope::SHOULD_RECORD_CONTEXT_KEY, false);
        $this->yieldBeforeTelescopeContextPropagation = true;
        $recording = null;
        $parentRecording = null;

        $coroutineId = Coroutine::fork(function () use (&$recording, &$parentRecording): void {
            $recording = Telescope::isRecording();
            $parentRecording = CoroutineContext::get(
                Telescope::SHOULD_RECORD_CONTEXT_KEY,
                null,
                Coroutine::parentId(),
            );
        });

        CoroutineContext::set(Telescope::SHOULD_RECORD_CONTEXT_KEY, true);
        Coroutine::join([$coroutineId]);

        $this->assertFalse($recording);
        $this->assertTrue($parentRecording);
    }

    public function testCreateInheritsTelescopeContextFromParent(): void
    {
        CoroutineContext::set(Telescope::SHOULD_RECORD_CONTEXT_KEY, false);
        $this->yieldBeforeTelescopeContextPropagation = true;
        $recording = null;

        $coroutineId = Coroutine::create(function () use (&$recording): void {
            $recording = Telescope::isRecording();
        });

        CoroutineContext::set(Telescope::SHOULD_RECORD_CONTEXT_KEY, true);
        Coroutine::join([$coroutineId]);

        $this->assertTrue($recording);
    }

    public function testForkInheritsOmittedTelescopeContextFromParent(): void
    {
        CoroutineContext::set(Telescope::SHOULD_RECORD_CONTEXT_KEY, false);
        CoroutineContext::set('telescope-test.selected', 'selected');
        $this->yieldBeforeTelescopeContextPropagation = true;
        $observed = null;

        $coroutineId = Coroutine::fork(function () use (&$observed): void {
            $observed = [
                Telescope::isRecording(),
                CoroutineContext::get('telescope-test.selected'),
            ];
        }, ['telescope-test.selected']);

        CoroutineContext::set(Telescope::SHOULD_RECORD_CONTEXT_KEY, true);
        Coroutine::join([$coroutineId]);

        $this->assertSame([true, 'selected'], $observed);
    }

    public function testRouteRegistrationRequiresStringPath(): void
    {
        config()->set('telescope.path', null);

        $provider = new class($this->app) extends TelescopeServiceProvider {
            public function registerRoutesForTest(): void
            {
                $this->registerRoutes();
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configuration value for key [telescope.path] must be a string');

        $provider->registerRoutesForTest();
    }

    public function testDatabaseRepositoryUsesDefaultChunkSizeWhenSettingIsOmitted(): void
    {
        $telescope = config()->array('telescope');

        $this->assertSame(
            DatabaseEntriesRepository::DEFAULT_CHUNK_SIZE,
            $telescope['storage']['database']['chunk'],
        );

        unset($telescope['storage']['database']['chunk']);
        config()->set('telescope', $telescope);
        $this->app->forgetInstance(EntriesRepository::class);

        $repository = $this->app->make(EntriesRepository::class);
        $chunkSize = new ReflectionProperty(DatabaseEntriesRepository::class, 'chunkSize');

        $this->assertSame(DatabaseEntriesRepository::DEFAULT_CHUNK_SIZE, $chunkSize->getValue($repository));
    }
}
