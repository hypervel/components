<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\Validation\fixtures\Values;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rule;
use Hypervel\Validation\Rules\DoesntContain;
use Hypervel\Validation\Validator;
use PHPUnit\Framework\TestCase;

include_once 'Enums.php';

/**
 * @internal
 * @coversNothing
 */
class ValidationDoesntContainRuleTest extends TestCase
{
    public function testItCorrectlyFormatsAStringVersionOfTheRule()
    {
        $rule = new DoesntContain(['foo', 'bar']);

        $this->assertSame('doesnt_contain:"foo","bar"', (string) $rule);

        $rule = new DoesntContain(collect(['foo', 'bar']));

        $this->assertSame('doesnt_contain:"foo","bar"', (string) $rule);

        $rule = new DoesntContain(['value with "quotes"']);

        $this->assertSame('doesnt_contain:"value with ""quotes"""', (string) $rule);

        $rule = Rule::doesntContain(['foo', 'bar']);

        $this->assertSame('doesnt_contain:"foo","bar"', (string) $rule);

        $rule = Rule::doesntContain(collect([1, 2, 3]));

        $this->assertSame('doesnt_contain:"1","2","3"', (string) $rule);

        $rule = Rule::doesntContain(new Values());

        $this->assertSame('doesnt_contain:"1","2","3","4"', (string) $rule);

        $rule = Rule::doesntContain('foo', 'bar', 'baz');

        $this->assertSame('doesnt_contain:"foo","bar","baz"', (string) $rule);

        $rule = new DoesntContain('foo', 'bar', 'baz');

        $this->assertSame('doesnt_contain:"foo","bar","baz"', (string) $rule);

        $rule = Rule::doesntContain([StringStatus::done]);

        $this->assertSame('doesnt_contain:"done"', (string) $rule);

        $rule = Rule::doesntContain([IntegerStatus::done]);

        $this->assertSame('doesnt_contain:"2"', (string) $rule);

        $rule = Rule::doesntContain([PureEnum::one]);

        $this->assertSame('doesnt_contain:"one"', (string) $rule);
    }

    public function testDoesntContainRuleValidation()
    {
        $trans = new Translator(new ArrayLoader(), 'en');

        // Array doesn't contain the forbidden value
        $v = new Validator($trans, ['x' => ['foo', 'bar', 'baz']], ['x' => Rule::doesntContain('qux')]);
        $this->assertTrue($v->passes());

        // Array doesn't contain any of the forbidden values
        $v = new Validator($trans, ['x' => ['foo', 'bar', 'baz']], ['x' => Rule::doesntContain('qux', 'quux')]);
        $this->assertTrue($v->passes());

        // Array contains a forbidden value
        $v = new Validator($trans, ['x' => ['foo', 'bar', 'baz']], ['x' => Rule::doesntContain('foo')]);
        $this->assertFalse($v->passes());

        // Array contains one of the forbidden values
        $v = new Validator($trans, ['x' => ['foo', 'bar', 'baz']], ['x' => Rule::doesntContain('qux', 'bar')]);
        $this->assertFalse($v->passes());

        // Non-array value fails
        $v = new Validator($trans, ['x' => 'foo'], ['x' => Rule::doesntContain('foo')]);
        $this->assertFalse($v->passes());

        // Combined with other rules
        $v = new Validator($trans, ['x' => ['foo', 'bar']], ['x' => ['required', 'array', Rule::doesntContain('baz')]]);
        $this->assertTrue($v->passes());
    }
}
