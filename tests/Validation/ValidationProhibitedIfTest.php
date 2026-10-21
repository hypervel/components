<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Exception;
use Generator;
use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rules\ProhibitedIf;
use Hypervel\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use TypeError;

class ValidationProhibitedIfTest extends TestCase
{
    public function testItReturnsStringVersionOfRuleWhenCast(): void
    {
        $rule = new ProhibitedIf(function (): bool {
            return true;
        });

        $this->assertSame('prohibited', (string) $rule);

        $rule = new ProhibitedIf(function (): bool {
            return false;
        });

        $this->assertSame('', (string) $rule);

        $rule = new ProhibitedIf(true);

        $this->assertSame('prohibited', (string) $rule);

        $rule = new ProhibitedIf(false);

        $this->assertSame('', (string) $rule);
    }

    public function testItAcceptsCallableAndBooleanArguments(): void
    {
        new ProhibitedIf(false);
        new ProhibitedIf(true);
        new ProhibitedIf(fn (): bool => true);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('dataProviderItRejectsNonCallableNonBooleanArguments')]
    public function testItRejectsNonCallableNonBooleanArguments(mixed $condition): void
    {
        $this->expectException(TypeError::class);

        new ProhibitedIf($condition);
    }

    /**
     * Provide invalid rule conditions.
     */
    public static function dataProviderItRejectsNonCallableNonBooleanArguments(): Generator
    {
        yield 'int' => [1];
        yield 'float' => [1.1];
        yield 'string' => ['phpinfo'];
        yield 'object' => [new stdClass];
    }

    public function testItThrowsExceptionIfRuleIsNotSerializable(): void
    {
        $this->expectException(Exception::class);

        serialize(new ProhibitedIf(function (): bool {
            return true;
        }));
    }

    public function testProhibitedIfRuleValidation(): void
    {
        $trans = new Translator(new ArrayLoader, 'en');

        $rule = new ProhibitedIf(true);

        $v = new Validator($trans, ['y' => 'foo'], ['x' => $rule]);
        $this->assertTrue($v->passes());

        $v = new Validator($trans, ['y' => 'foo'], ['x' => (string) $rule]);
        $this->assertTrue($v->passes());

        $v = new Validator($trans, ['y' => 'foo'], ['x' => [$rule]]);
        $this->assertTrue($v->passes());

        $v = new Validator($trans, ['x' => 'foo'], ['x' => ['string', $rule]]);
        $this->assertTrue($v->fails());

        $rule = new ProhibitedIf(false);

        $v = new Validator($trans, ['x' => 'foo'], ['x' => ['string', $rule]]);
        $this->assertTrue($v->passes());
    }
}
