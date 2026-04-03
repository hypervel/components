<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Sentry\SentryServiceProvider;
use Hypervel\Sentry\Tracing\ViewEngineDecorator;
use Hypervel\Tests\Sentry\SentryTestCase;
use Hypervel\View\Engines\EngineResolver;
use ReflectionProperty;

/**
 * @internal
 * @coversNothing
 */
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

    private function getInnerEngine(ViewEngineDecorator $decorator): object
    {
        $property = new ReflectionProperty(ViewEngineDecorator::class, 'engine');

        return $property->getValue($decorator);
    }
}
