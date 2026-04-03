<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\Attributes\Test;

#[WithConfig('fake.parent_attribute', true)]
#[WithConfig('fake.override_attribute', 'parent')]
abstract class AttributesInheritanceTestBaseTestCase extends TestCase
{
    #[BeforeClass]
    public static function defineTestingFeatures(): void
    {
        static::usesTestingFeature(new WithConfig('fake.override_attribute_2', 'parent'));
    }
}

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('fake.override_attribute', 'child')]
class AttributesInheritanceTest extends AttributesInheritanceTestBaseTestCase
{
    #[BeforeClass]
    public static function defineChildTestingFeatures(): void
    {
        static::usesTestingFeature(new WithConfig('fake.override_attribute_2', 'child'));
    }

    #[Test]
    public function itCanSeeParentAttributes(): void
    {
        $this->assertTrue(config('fake.parent_attribute'));
    }

    #[Test]
    public function itCanOverrideParentAttributes(): void
    {
        $this->assertSame('child', config('fake.override_attribute'));
        $this->assertSame('child', config('fake.override_attribute_2'));
    }
}
