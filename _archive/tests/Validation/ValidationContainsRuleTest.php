<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\Validation\Fixtures\Values;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rule;
use Hypervel\Validation\Rules\Contains;
use Hypervel\Validation\Validator;
use PHPUnit\Framework\TestCase;

include_once 'Enums.php';

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

        $rule = Rule::contains(new Values);

        $this->assertSame('contains:"1","2","3","4"', (string) $rule);

        $rule = Rule::contains('foo', 'bar', 'baz');

        $this->assertSame('contains:"foo","bar","baz"', (string) $rule);

        $rule = new Contains('foo', 'bar', 'baz');

        $this->assertSame('contains:"foo","bar","baz"', (string) $rule);

        $rule = Rule::contains([StringStatus::Done]);

        $this->assertSame('contains:"done"', (string) $rule);

        $rule = Rule::contains([IntegerStatus::Done]);

        $this->assertSame('contains:"2"', (string) $rule);

        $rule = Rule::contains([PureEnum::one]);

        $this->assertSame('contains:"one"', (string) $rule);
    }

    public function testContainsRuleValidation()
    {
        $trans = new Translator(new ArrayLoader, 'en');

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

    public function testContainsValidation()
    {
        $trans = new Translator(new ArrayLoader, 'en');

        // Test fails when value is string
        $v = new Validator($trans, ['roles' => 'admin'], ['roles' => Rule::contains('editor')]);
        $this->assertTrue($v->fails());

        // Test passes when array contains the value
        $v = new Validator($trans, ['roles' => ['admin', 'user']], ['roles' => Rule::contains('admin')]);
        $this->assertTrue($v->passes());

        // Test fails when array doesn't contain all the values
        $v = new Validator($trans, ['roles' => ['admin', 'user']], ['roles' => Rule::contains(['admin', 'editor'])]);
        $this->assertTrue($v->fails());

        // Test fails when array doesn't contain all the values (using multiple arguments)
        $v = new Validator($trans, ['roles' => ['admin', 'user']], ['roles' => Rule::contains('admin', 'editor')]);
        $this->assertTrue($v->fails());

        // Test passes when array contains all the values
        $v = new Validator($trans, ['roles' => ['admin', 'user', 'editor']], ['roles' => Rule::contains(['admin', 'editor'])]);
        $this->assertTrue($v->passes());

        // Test passes when array contains all the values (using multiple arguments)
        $v = new Validator($trans, ['roles' => ['admin', 'user', 'editor']], ['roles' => Rule::contains('admin', 'editor')]);
        $this->assertTrue($v->passes());

        // Test fails when array doesn't contain the value
        $v = new Validator($trans, ['roles' => ['admin', 'user']], ['roles' => Rule::contains('editor')]);
        $this->assertTrue($v->fails());

        // Test fails when array doesn't contain any of the values
        $v = new Validator($trans, ['roles' => ['admin', 'user']], ['roles' => Rule::contains(['editor', 'manager'])]);
        $this->assertTrue($v->fails());

        // Test with empty array
        $v = new Validator($trans, ['roles' => []], ['roles' => Rule::contains('admin')]);
        $this->assertTrue($v->fails());

        // Test with nullable field
        $v = new Validator($trans, ['roles' => null], ['roles' => ['nullable', Rule::contains('admin')]]);
        $this->assertTrue($v->passes());
    }
}
