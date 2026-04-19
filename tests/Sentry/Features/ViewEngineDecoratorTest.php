<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Sentry\SentryServiceProvider;
use Hypervel\Sentry\Tracing\ViewEngineDecorator;
use Hypervel\Tests\Sentry\SentryTestCase;
use Hypervel\View\Engines\EngineResolver;
use ReflectionProperty;

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

            $viewFactory->make('sentrytest::hello')->render();

            $this->assertSame(
                'sentrytest::hello',
                $viewFactory->shared(ViewEngineDecorator::SHARED_KEY)
            );
        } finally {
            @unlink($viewDir . '/hello.blade.php');
            @rmdir($viewDir);
        }
    }

    private function getInnerEngine(ViewEngineDecorator $decorator): object
    {
        $property = new ReflectionProperty(ViewEngineDecorator::class, 'engine');

        return $property->getValue($decorator);
    }
}
