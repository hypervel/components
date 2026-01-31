<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Testbench\PHPUnit\AttributeParser;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;

/**
 * Tests that attributes are inherited from parent TestCase classes.
 *
 * @internal
 * @coversNothing
 */
#[WithConfig('testing.child_class', 'child_value')]
class AttributeInheritanceTest extends AbstractParentTestCase
{
    public function testAttributeParserIncludesParentClassAttributes(): void
    {
        $attributes = AttributeParser::forClass(static::class);

        // Should have attributes from both parent and child
        $withConfigAttributes = array_filter(
            $attributes,
            fn ($attr) => $attr['key'] === WithConfig::class
        );

        $this->assertCount(2, $withConfigAttributes);

        // Extract the config keys to verify both are present
        $configKeys = array_map(
            fn ($attr) => $attr['instance']->key,
            $withConfigAttributes
        );

        $this->assertContains('testing.parent_class', $configKeys);
        $this->assertContains('testing.child_class', $configKeys);
    }

    public function testParentAttributeIsExecutedThroughLifecycle(): void
    {
        // The parent's #[WithConfig('testing.parent_class', 'parent_value')] should be applied
        $this->assertSame(
            'parent_value',
            $this->app->get('config')->get('testing.parent_class')
        );
    }

    public function testChildAttributeIsExecutedThroughLifecycle(): void
    {
        // The child's #[WithConfig('testing.child_class', 'child_value')] should be applied
        $this->assertSame(
            'child_value',
            $this->app->get('config')->get('testing.child_class')
        );
    }

    public function testParentAttributesAreAppliedBeforeChildAttributes(): void
    {
        // Parent attributes come first in the array (prepended during recursion)
        $attributes = AttributeParser::forClass(static::class);

        $withConfigAttributes = array_values(array_filter(
            $attributes,
            fn ($attr) => $attr['key'] === WithConfig::class
        ));

        // Parent should be first
        $this->assertSame('testing.parent_class', $withConfigAttributes[0]['instance']->key);
        // Child should be second
        $this->assertSame('testing.child_class', $withConfigAttributes[1]['instance']->key);
    }
}

/**
 * Abstract parent test case with class-level attributes for inheritance testing.
 *
 * @internal
 */
#[WithConfig('testing.parent_class', 'parent_value')]
abstract class AbstractParentTestCase extends TestCase
{
}
