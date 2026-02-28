<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Features\FeaturesCollection;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('testing.class_attribute', 'class_value')]
class HandlesAttributesTest extends TestCase
{
    protected function defineConfigEnv($app): void
    {
        $app->make('config')->set('testing.method_env', 'method_value');
    }

    public function testParseTestMethodAttributesReturnsCollection(): void
    {
        $result = $this->parseTestMethodAttributes($this->app, WithConfig::class);

        $this->assertInstanceOf(FeaturesCollection::class, $result);
    }

    #[WithConfig('testing.method_attribute', 'test_value')]
    public function testParseTestMethodAttributesHandlesInvokable(): void
    {
        // Parse WithConfig attribute which is Invokable
        $this->parseTestMethodAttributes($this->app, WithConfig::class);

        // The attribute should have set the config value
        $this->assertSame('test_value', $this->app->make('config')->get('testing.method_attribute'));
    }

    #[DefineEnvironment('defineConfigEnv')]
    public function testParseTestMethodAttributesHandlesActionable(): void
    {
        // Parse DefineEnvironment attribute which is Actionable
        $this->parseTestMethodAttributes($this->app, DefineEnvironment::class);

        // The attribute should have called the method which set the config value
        $this->assertSame('method_value', $this->app->make('config')->get('testing.method_env'));
    }

    public function testParseTestMethodAttributesReturnsEmptyCollectionForNoMatch(): void
    {
        $result = $this->parseTestMethodAttributes($this->app, DefineEnvironment::class);

        $this->assertInstanceOf(FeaturesCollection::class, $result);
        $this->assertTrue($result->isEmpty());
    }

    #[WithConfig('testing.multi_one', 'one')]
    #[WithConfig('testing.multi_two', 'two')]
    public function testParseTestMethodAttributesHandlesMultipleAttributes(): void
    {
        $this->parseTestMethodAttributes($this->app, WithConfig::class);

        $this->assertSame('one', $this->app->make('config')->get('testing.multi_one'));
        $this->assertSame('two', $this->app->make('config')->get('testing.multi_two'));
    }
}
