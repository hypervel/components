<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Sentry\SentryServiceProvider;
use Hypervel\Sentry\Tracing\ViewEngineDecorator;
use Hypervel\Tests\Sentry\SentryTestCase;
use Hypervel\View\Engines\EngineResolver;
use ReflectionProperty;
use Sentry\Tracing\Transaction;

use function Hypervel\Coroutine\parallel;

class ViewEngineDecoratorTest extends SentryTestCase
{
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
        $viewDir = sys_get_temp_dir() . '/sentry_view_test_' . uniqid();
        mkdir($viewDir);
        file_put_contents($viewDir . '/hello.blade.php', 'Hello');

        try {
            /** @var \Hypervel\View\Factory $viewFactory */
            $viewFactory = $this->app->make('view');
            $viewFactory->addNamespace('sentrytest', $viewDir);

            $transaction = $this->startTransaction();

            $viewFactory->make('sentrytest::hello')->render();

            $this->assertSame(
                'sentrytest::hello',
                $this->lastViewSpanDescription($transaction)
            );
        } finally {
            @unlink($viewDir . '/hello.blade.php');
            @rmdir($viewDir);
        }
    }

    public function testViewNamesAreIsolatedBetweenConcurrentRenders(): void
    {
        $viewDir = sys_get_temp_dir() . '/sentry_view_race_test_' . uniqid();
        mkdir($viewDir);
        file_put_contents($viewDir . '/first.blade.php', 'First');
        file_put_contents($viewDir . '/second.blade.php', 'Second');

        try {
            /** @var \Hypervel\View\Factory $viewFactory */
            $viewFactory = $this->app->make('view');
            $viewFactory->addNamespace('sentryrace', $viewDir);

            $viewFactory->observeRendering(static function (): void {
                usleep(5000);
            });

            $results = parallel([
                'first' => function () use ($viewFactory) {
                    $transaction = $this->startTransaction();

                    $viewFactory->make('sentryrace::first')->render();

                    return $this->lastViewSpanDescription($transaction);
                },
                'second' => function () use ($viewFactory) {
                    $transaction = $this->startTransaction();

                    $viewFactory->make('sentryrace::second')->render();

                    return $this->lastViewSpanDescription($transaction);
                },
            ]);

            $this->assertSame('sentryrace::first', $results['first']);
            $this->assertSame('sentryrace::second', $results['second']);
        } finally {
            @unlink($viewDir . '/first.blade.php');
            @unlink($viewDir . '/second.blade.php');
            @rmdir($viewDir);
        }
    }

    private function getInnerEngine(ViewEngineDecorator $decorator): object
    {
        $property = new ReflectionProperty(ViewEngineDecorator::class, 'engine');

        return $property->getValue($decorator);
    }

    private function lastViewSpanDescription(Transaction $transaction): ?string
    {
        $spans = array_filter(
            $transaction->getSpanRecorder()?->getSpans() ?? [],
            static fn ($span) => $span->getOp() === 'view.render'
        );

        $span = end($spans);

        return $span === false ? null : $span->getDescription();
    }
}
