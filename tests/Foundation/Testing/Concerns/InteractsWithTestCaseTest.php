<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Attribute;
use Hypervel\Support\Collection;
use Hypervel\Testbench\Attributes\Define;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\HandlesAttributes;
use Hypervel\Testbench\Concerns\InteractsWithTestCase;
use Hypervel\Testbench\PHPUnit\AttributeParser;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('testing.class_level', 'class_value')]
class InteractsWithTestCaseTest extends TestCase
{
    public function testUsesTestingConcernReturnsTrueForUsedTrait(): void
    {
        $this->assertTrue(static::usesTestingConcern(HandlesAttributes::class));
        $this->assertTrue(static::usesTestingConcern(InteractsWithTestCase::class));
    }

    public function testUsesTestingConcernReturnsFalseForUnusedTrait(): void
    {
        $this->assertFalse(static::usesTestingConcern('NonExistentTrait'));
    }

    public function testCachedUsesForTestCaseReturnsTraits(): void
    {
        $uses = static::cachedUsesForTestCase();

        $this->assertIsArray($uses);
        $this->assertArrayHasKey(HandlesAttributes::class, $uses);
        $this->assertArrayHasKey(InteractsWithTestCase::class, $uses);
    }

    public function testResolvePhpUnitAttributesReturnsCollection(): void
    {
        $attributes = $this->resolvePhpUnitAttributes();

        $this->assertInstanceOf(Collection::class, $attributes);
    }

    #[WithConfig('testing.method_level', 'method_value')]
    public function testResolvePhpUnitAttributesMergesClassAndMethodAttributes(): void
    {
        $attributes = $this->resolvePhpUnitAttributes();

        // Should have WithConfig from both class and method level
        $this->assertTrue($attributes->has(WithConfig::class));

        $withConfigInstances = $attributes->get(WithConfig::class);
        $this->assertCount(2, $withConfigInstances);
    }

    public function testClassLevelAttributeIsApplied(): void
    {
        // The WithConfig attribute at class level should be applied
        $this->assertSame('class_value', $this->app->get('config')->get('testing.class_level'));
    }

    public function testUsesTestingFeatureAddsAttribute(): void
    {
        // Add a testing feature programmatically at method level so it doesn't
        // persist to other tests in this class
        static::usesTestingFeature(
            new WithConfig('testing.programmatic', 'added'),
            Attribute::TARGET_METHOD
        );

        // Re-resolve attributes to include the programmatically added one
        $attributes = $this->resolvePhpUnitAttributes();

        $this->assertTrue($attributes->has(WithConfig::class));
    }

    public function testDefineMetaAttributeIsResolvedByAttributeParser(): void
    {
        // Test that AttributeParser resolves #[Define('env', 'method')] to DefineEnvironment
        $attributes = AttributeParser::forMethod(
            DefineMetaAttributeTestCase::class,
            'testWithDefineAttribute'
        );

        // Should have one attribute, resolved from Define to DefineEnvironment
        $this->assertCount(1, $attributes);
        $this->assertSame(DefineEnvironment::class, $attributes[0]['key']);
        $this->assertInstanceOf(DefineEnvironment::class, $attributes[0]['instance']);
        $this->assertSame('setupDefineEnv', $attributes[0]['instance']->method);
    }

    #[Define('env', 'setupDefineEnvForExecution')]
    public function testDefineMetaAttributeIsExecutedThroughLifecycle(): void
    {
        // The #[Define('env', 'setupDefineEnvForExecution')] attribute should have been
        // resolved to DefineEnvironment and executed during setUp, calling our method
        $this->assertSame(
            'define_env_executed',
            $this->app->get('config')->get('testing.define_meta_attribute')
        );
    }

    protected function setupDefineEnvForExecution($app): void
    {
        $app->get('config')->set('testing.define_meta_attribute', 'define_env_executed');
    }

    public function testResolvePhpUnitAttributesReturnsCollectionOfCollections(): void
    {
        $attributes = $this->resolvePhpUnitAttributes();

        $this->assertInstanceOf(Collection::class, $attributes);

        // Each value should be a Collection, not an array
        $attributes->each(function ($value, $key) {
            $this->assertInstanceOf(
                Collection::class,
                $value,
                "Value for key {$key} should be a Collection, not " . gettype($value)
            );
        });
    }
}

/**
 * Test fixture for Define meta-attribute parsing.
 *
 * @internal
 * @coversNothing
 */
class DefineMetaAttributeTestCase extends TestCase
{
    #[Define('env', 'setupDefineEnv')]
    public function testWithDefineAttribute(): void
    {
        // This method exists just to have the attribute parsed
    }

    protected function setupDefineEnv($app): void
    {
        // Method that would be called
    }
}
