<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Exception;
use Generator;
use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rules\ExcludeIf;
use Hypervel\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use TypeError;

class ValidationExcludeIfTest extends TestCase
{
    public function testItReturnsStringVersionOfRuleWhenCast(): void
    {
        $rule = new ExcludeIf(function (): bool {
            return true;
        });

        $this->assertSame('exclude', (string) $rule);

        $rule = new ExcludeIf(function (): bool {
            return false;
        });

        $this->assertSame('', (string) $rule);

        $rule = new ExcludeIf(true);

        $this->assertSame('exclude', (string) $rule);

        $rule = new ExcludeIf(false);

        $this->assertSame('', (string) $rule);
    }

    public function testItAcceptsCallableAndBooleanArguments(): void
    {
        new ExcludeIf(false);
        new ExcludeIf(true);
        new ExcludeIf(fn (): bool => true);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('dataProviderItRejectsNonCallableNonBooleanArguments')]
    public function testItRejectsNonCallableNonBooleanArguments(mixed $condition): void
    {
        $this->expectException(TypeError::class);

        new ExcludeIf($condition);
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
        yield 'null' => [null];
    }

    public function testItThrowsExceptionIfRuleIsNotSerializable(): void
    {
        $this->expectException(Exception::class);

        serialize(new ExcludeIf(function (): bool {
            return true;
        }));
    }

    public function testExcludeIfRuleValidation(): void
    {
        $ruleTrue = new ExcludeIf(true);

        $ruleFalse = new ExcludeIf(false);

        $trans = new Translator(new ArrayLoader, 'en');

        $data = ['foo' => 'FOO', 'bar' => 'BAR'];

        $v = new Validator($trans, $data, ['foo' => $ruleTrue, 'bar' => 'nullable']);
        $this->assertTrue($v->passes());
        $this->assertSame(['bar' => 'BAR'], $v->validated());

        $v = new Validator($trans, $data, ['foo' => (string) $ruleTrue, 'bar' => 'nullable']);
        $this->assertTrue($v->passes());
        $this->assertSame(['bar' => 'BAR'], $v->validated());

        $v = new Validator($trans, $data, ['foo' => [$ruleTrue], 'bar' => 'nullable']);
        $this->assertTrue($v->passes());
        $this->assertSame(['bar' => 'BAR'], $v->validated());

        $v = new Validator($trans, $data, ['foo' => $ruleFalse, 'bar' => 'nullable']);
        $this->assertTrue($v->passes());
        $this->assertSame($data, $v->validated());
    }
}
