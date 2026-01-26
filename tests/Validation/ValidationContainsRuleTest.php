<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\Validation\fixtures\Values;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rule;
use Hypervel\Validation\Rules\Contains;
use Hypervel\Validation\Validator;
use PHPUnit\Framework\TestCase;

include_once 'Enums.php';

/**
 * @internal
 * @coversNothing
 */
class ValidationContainsRuleTest extends TestCase
{
    public function testItCorrectlyFormatsAStringVersionOfTheRule()
    {
        $rule = new Contains(['foo', 'bar']);

        $this->assertSame('contains:"foo","bar"', (string) $rule);

        $rule = new Contains(collect(['foo', 'bar']));

        $this->assertSame('contains:"foo","bar"', (string) $rule);

        $rule = new Contains(['value with "quotes"']);

        $this->assertSame('contains:"value with ""quotes"""', (string) $rule);

        $rule = Rule::contains(['foo', 'bar']);

        $this->assertSame('contains:"foo","bar"', (string) $rule);

        $rule = Rule::contains(collect([1, 2, 3]));

        $this->assertSame('contains:"1","2","3"', (string) $rule);

        $rule = Rule::contains(new Values());

        $this->assertSame('contains:"1","2","3","4"', (string) $rule);

        $rule = Rule::contains('foo', 'bar', 'baz');

        $this->assertSame('contains:"foo","bar","baz"', (string) $rule);

        $rule = new Contains('foo', 'bar', 'baz');

        $this->assertSame('contains:"foo","bar","baz"', (string) $rule);

        $rule = Rule::contains([StringStatus::done]);

        $this->assertSame('contains:"done"', (string) $rule);

        $rule = Rule::contains([IntegerStatus::done]);

        $this->assertSame('contains:"2"', (string) $rule);

        $rule = Rule::contains([PureEnum::one]);

        $this->assertSame('contains:"one"', (string) $rule);
    }

    public function testContainsRuleValidation()
    {
        $trans = new Translator(new ArrayLoader(), 'en');

        // Array contains the required value
        $v = new Validator($trans, ['x' => ['foo', 'bar', 'baz']], ['x' => Rule::contains('foo')]);
        $this->assertTrue($v->passes());

        // Array contains multiple required values
        $v = new Validator($trans, ['x' => ['foo', 'bar', 'baz']], ['x' => Rule::contains('foo', 'bar')]);
        $this->assertTrue($v->passes());

        // Array missing a required value
        $v = new Validator($trans, ['x' => ['foo', 'bar']], ['x' => Rule::contains('baz')]);
        $this->assertFalse($v->passes());

        // Array missing one of multiple required values
        $v = new Validator($trans, ['x' => ['foo', 'bar']], ['x' => Rule::contains('foo', 'qux')]);
        $this->assertFalse($v->passes());

        // Non-array value fails
        $v = new Validator($trans, ['x' => 'foo'], ['x' => Rule::contains('foo')]);
        $this->assertFalse($v->passes());

        // Combined with other rules
        $v = new Validator($trans, ['x' => ['foo', 'bar']], ['x' => ['required', 'array', Rule::contains('foo')]]);
        $this->assertTrue($v->passes());
    }
}
