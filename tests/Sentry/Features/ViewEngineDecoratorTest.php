<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Sentry\SentryServiceProvider;
use Hypervel\Sentry\Tracing\ViewEngineDecorator;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Sentry\TestCase;
use Hypervel\View\Engines\EngineResolver;
use Hypervel\View\Factory;
use Override;
use ReflectionProperty;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;

use function Hypervel\Coroutine\parallel;

class ViewEngineDecoratorTest extends TestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
    ];

    private string $viewPath;

    private Filesystem $filesystem;

    /**
     * Set up the test environment.
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->viewPath = ParallelTesting::tempDir('SentryViewEngineDecoratorTest');

        $this->filesystem->deleteDirectory($this->viewPath);
        $this->filesystem->ensureDirectoryExists($this->viewPath);
    }

    /**
     * Clean up the test environment.
     */
    #[Override]
    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->viewPath);

        parent::tearDown();
    }

    public function testViewEngineIsDecorated(): void
    {
        /** @var EngineResolver $engineResolver */
        $engineResolver = $this->app->make('view.engine.resolver');

        foreach (['file', 'php', 'blade'] as $engineName) {
            $engine = $engineResolver->resolve($engineName);

            $this->assertInstanceOf(ViewEngineDecorator::class, $engine, "Engine `{$engineName}` should be wrapped in a ViewEngineDecorator.");
        }
    }

    public function testViewEngineIsNotDoubleDecorated(): void
    {
        // Boot the service provider again to simulate the wrapping running a second time
        (new SentryServiceProvider($this->app))->boot();

        /** @var EngineResolver $engineResolver */
        $engineResolver = $this->app->make('view.engine.resolver');

        foreach (['file', 'php', 'blade'] as $engineName) {
            $engine = $engineResolver->resolve($engineName);

            $this->assertInstanceOf(ViewEngineDecorator::class, $engine, "Engine `{$engineName}` should be wrapped in a ViewEngineDecorator.");

            $innerEngine = $this->getInnerEngine($engine);

            $this->assertNotInstanceOf(ViewEngineDecorator::class, $innerEngine, "Engine `{$engineName}` should not be double wrapped in a ViewEngineDecorator.");
        }
    }

    public function testViewNameIsAvailableDuringRender(): void
    {
        $this->filesystem->put($this->viewPath . '/hello.blade.php', 'Hello');

        /** @var Factory $viewFactory */
        $viewFactory = $this->app->make('view');
        $viewFactory->addNamespace('sentrytest', $this->viewPath);

        $transaction = $this->startTransaction();

        $viewFactory->make('sentrytest::hello')->render();

        $this->assertSame(
            'sentrytest::hello',
            $this->lastViewSpanDescription($transaction)
        );
    }

    public function testViewNamesAreIsolatedBetweenConcurrentRenders(): void
    {
        $this->filesystem->put($this->viewPath . '/first.blade.php', 'First');
        $this->filesystem->put($this->viewPath . '/second.blade.php', 'Second');

        /** @var Factory $viewFactory */
        $viewFactory = $this->app->make('view');
        $viewFactory->addNamespace('sentryrace', $this->viewPath);

        $viewFactory->observeRendering(static function (): void {
            usleep(5000);
        });

        $results = parallel([
            'first' => function () use ($viewFactory): ?string {
                $transaction = $this->startTransaction();

                $viewFactory->make('sentryrace::first')->render();

                return $this->lastViewSpanDescription($transaction);
            },
            'second' => function () use ($viewFactory): ?string {
                $transaction = $this->startTransaction();

                $viewFactory->make('sentryrace::second')->render();

                return $this->lastViewSpanDescription($transaction);
            },
        ]);

        $this->assertSame('sentryrace::first', $results['first']);
        $this->assertSame('sentryrace::second', $results['second']);
    }

    /**
     * Get the engine wrapped by the given decorator.
     */
    private function getInnerEngine(ViewEngineDecorator $decorator): object
    {
        $property = new ReflectionProperty(ViewEngineDecorator::class, 'engine');

        return $property->getValue($decorator);
    }

    /**
     * Get the description of the last view span recorded by the given transaction.
     */
    private function lastViewSpanDescription(Transaction $transaction): ?string
    {
        $spans = array_filter(
            $transaction->getSpanRecorder()?->getSpans() ?? [],
            static fn (Span $span): bool => $span->getOp() === 'view.render'
        );

        $span = end($spans);

        return $span === false ? null : $span->getDescription();
    }
}
