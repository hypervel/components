<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\Validation\Fixtures\Values;
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

        $rule = Rule::doesntContain(new Values);

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
        $trans = new Translator(new ArrayLoader, 'en');

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

    public function testDoesntContainValidation()
    {
        $trans = new Translator(new ArrayLoader, 'en');

        // Test fails when value is string
        $v = new Validator($trans, ['roles' => 'admin'], ['roles' => Rule::doesntContain('admin')]);
        $this->assertTrue($v->fails());

        // Test fails when array contains the value
        $v = new Validator($trans, ['roles' => ['admin', 'user']], ['roles' => Rule::doesntContain('admin')]);
        $this->assertTrue($v->fails());

        // Test fails when array contains all the values (using array argument)
        $v = new Validator($trans, ['roles' => ['admin', 'user']], ['roles' => Rule::doesntContain(['admin', 'editor'])]);
        $this->assertTrue($v->fails());

        // Test fails when array contains some of the values (using multiple arguments)
        $v = new Validator($trans, ['roles' => ['admin', 'user']], ['roles' => Rule::doesntContain('subscriber', 'admin')]);
        $this->assertTrue($v->fails());

        // Test passes when array does not contain any value
        $v = new Validator($trans, ['roles' => ['subscriber', 'guest']], ['roles' => Rule::doesntContain(['admin', 'editor'])]);
        $this->assertTrue($v->passes());

        // Test fails when array includes a value (using string-like format)
        $v = new Validator($trans, ['roles' => ['admin', 'user']], ['roles' => 'doesnt_contain:admin']);
        $this->assertTrue($v->fails());

        // Test passes when array doesn't include a value (using string-like format)
        $v = new Validator($trans, ['roles' => ['admin', 'user']], ['roles' => 'doesnt_contain:editor']);
        $this->assertTrue($v->passes());

        // Test fails when array doesn't contain the value
        $v = new Validator($trans, ['roles' => ['admin', 'user']], ['roles' => Rule::doesntContain('admin')]);
        $this->assertTrue($v->fails());

        // Test with empty array
        $v = new Validator($trans, ['roles' => []], ['roles' => Rule::doesntContain('admin')]);
        $this->assertTrue($v->passes());

        // Test with nullable field
        $v = new Validator($trans, ['roles' => null], ['roles' => ['nullable', Rule::doesntContain('admin')]]);
        $this->assertTrue($v->passes());
    }

    public function testDoesntContainMessageFormatsValues()
    {
        $trans = new Translator(new ArrayLoader, 'en');
        $trans->addLines(['validation.doesnt_contain' => ':attribute must not contain :values.'], 'en');

        $v = new Validator($trans, ['roles' => ['admin', 'user']], ['roles' => 'doesnt_contain:admin,editor']);
        $this->assertFalse($v->passes());
        $this->assertSame('roles must not contain admin, editor.', $v->messages()->first('roles'));
    }
}
