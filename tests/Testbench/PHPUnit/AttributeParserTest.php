<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\PHPUnit;

use Attribute;
use Error;
use Hypervel\Testbench\Contracts\Attributes\Resolvable;
use Hypervel\Testbench\Contracts\Attributes\TestingFeature;
use Hypervel\Testbench\PHPUnit\AttributeParser;
use Hypervel\Testbench\PHPUnit\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class AttributeParserTest extends TestCase
{
    #[Test]
    public function itCanValidateAttribute(): void
    {
        $this->assertFalse(AttributeParser::validAttribute('TestCase::class'));
        $this->assertFalse(AttributeParser::validAttribute(TestCase::class));
        $this->assertFalse(AttributeParser::validAttribute('Hypervel\Testbench\Support\FluentDecorator'));

        $this->assertTrue(AttributeParser::validAttribute('Hypervel\Testbench\Attributes\Define'));
    }

    #[Test]
    public function itPropagatesAttributeConstructionFailures(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('attribute construction failed');

        AttributeParser::forMethod(AttributeParserFixture::class, 'withFailingConstructor');
    }

    #[Test]
    public function itPropagatesAttributeResolverFailures(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('attribute resolver failed');

        AttributeParser::forMethod(AttributeParserFixture::class, 'withFailingResolver');
    }

    #[Test]
    public function itPropagatesInvalidAttributeTargetFailures(): void
    {
        $this->expectException(Error::class);

        AttributeParser::forMethod(AttributeParserFixture::class, 'withClassOnlyAttribute');
    }

    #[Test]
    public function itOmitsAnExplicitlyNullResolvedAttribute(): void
    {
        $this->assertSame(
            [],
            AttributeParser::forMethod(AttributeParserFixture::class, 'withNullResolver'),
        );
    }
}

class AttributeParserFixture
{
    #[FailingConstructionAttribute]
    public function withFailingConstructor(): void
    {
    }

    #[FailingResolverAttribute]
    public function withFailingResolver(): void
    {
    }

    #[NullResolverAttribute]
    public function withNullResolver(): void
    {
    }

    #[ClassOnlyAttribute]
    public function withClassOnlyAttribute(): void
    {
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
class ClassOnlyAttribute implements TestingFeature
{
}

#[Attribute(Attribute::TARGET_METHOD)]
class FailingConstructionAttribute implements TestingFeature
{
    public function __construct()
    {
        throw new RuntimeException('attribute construction failed');
    }
}

#[Attribute(Attribute::TARGET_METHOD)]
class FailingResolverAttribute implements Resolvable
{
    public function resolve(): never
    {
        throw new RuntimeException('attribute resolver failed');
    }
}

#[Attribute(Attribute::TARGET_METHOD)]
class NullResolverAttribute implements Resolvable
{
    public function resolve(): null
    {
        return null;
    }
}
