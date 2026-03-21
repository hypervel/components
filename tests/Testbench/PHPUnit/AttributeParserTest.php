<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\PHPUnit;

use Hypervel\Testbench\PHPUnit\AttributeParser;
use Hypervel\Testbench\PHPUnit\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
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
}
