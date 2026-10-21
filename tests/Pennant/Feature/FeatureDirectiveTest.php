<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pennant\Feature;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Pennant\Feature;
use Hypervel\Support\Facades\Blade;
use Hypervel\Tests\Pennant\TestCase;

class FeatureDirectiveTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('pennant.default', 'database');
    }

    public function testItRendersActiveFeatures(): void
    {
        $blade = <<< 'BLADE'
            @feature("foo")
                foo is active
            @else
                foo is inactive
            @endfeature
            BLADE;

        $output = trim(Blade::render($blade));
        $this->assertSame('foo is inactive', $output);

        Feature::activate('foo');
        $output = trim(Blade::render($blade));
        $this->assertSame('foo is active', $output);
    }

    public function testItChecksFeatureValue(): void
    {
        $blade = <<< 'BLADE'
            @feature("foo", 888)
                foo is 888
            @else
                foo is not 888
            @endfeature
            BLADE;

        Feature::activate('foo', 999);
        $output = trim(Blade::render($blade));
        $this->assertSame('foo is not 888', $output);

        Feature::activate('foo', 888);
        $output = trim(Blade::render($blade));
        $this->assertSame('foo is 888', $output);
    }

    public function testItChecksFeaturesAgainstDefaultScopeByDefault(): void
    {
        $blade = <<< 'BLADE'
            @feature("foo", 888)
                foo is 888
            @else
                foo is not 888
            @endfeature
            BLADE;

        Feature::resolveScopeUsing(fn () => 'tim');

        Feature::for('tim')->activate('foo', 999);
        $output = trim(Blade::render($blade));
        $this->assertSame('foo is not 888', $output);

        Feature::for('tim')->activate('foo', 888);
        $output = trim(Blade::render($blade));
        $this->assertSame('foo is 888', $output);
    }

    public function testItRendersAnyActiveFeatures(): void
    {
        $blade = <<<'BLADE'
        @featureany(['foo', 'bar'])
            foo or bar is active
        @else
            foo and bar are inactive
        @endfeatureany
        BLADE;

        $output = trim(Blade::render($blade));
        $this->assertSame('foo and bar are inactive', $output);

        Feature::activate('foo');
        $output = trim(Blade::render($blade));
        $this->assertSame('foo or bar is active', $output);

        Feature::deactivate('foo');
        Feature::activate('bar');
        $output = trim(Blade::render($blade));
        $this->assertSame('foo or bar is active', $output);
    }
}
