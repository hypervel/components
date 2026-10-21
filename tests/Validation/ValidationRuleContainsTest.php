<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\TestCase;
use Hypervel\Tests\Validation\Fixtures\ArrayKeys;
use Hypervel\Tests\Validation\Fixtures\ArrayKeysBacked;
use Hypervel\Tests\Validation\Fixtures\IntegerStatus;
use Hypervel\Tests\Validation\Fixtures\Values;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rule;
use Hypervel\Validation\Rules\Contains;
use Hypervel\Validation\Validator;

class ValidationRuleContainsTest extends TestCase
{
    public function testItCorrectlyFormatsAStringVersionOfTheRule(): void
    {
        $rule = Rule::contains('Taylor');
        $this->assertSame('contains:"Taylor"', (string) $rule);

        $rule = Rule::contains('Taylor', 'Abigail');
        $this->assertSame('contains:"Taylor","Abigail"', (string) $rule);

        $rule = Rule::contains(['Taylor', 'Abigail']);
        $this->assertSame('contains:"Taylor","Abigail"', (string) $rule);

        $rule = Rule::contains(collect(['Taylor', 'Abigail']));
        $this->assertSame('contains:"Taylor","Abigail"', (string) $rule);

        $rule = Rule::contains([ArrayKeys::key_1, ArrayKeys::key_2]);
        $this->assertSame('contains:"key_1","key_2"', (string) $rule);

        $rule = Rule::contains([ArrayKeysBacked::Key1, ArrayKeysBacked::Key2]);
        $this->assertSame('contains:"key_1","key_2"', (string) $rule);

        $rule = Rule::contains(['Taylor', 'Taylor']);
        $this->assertSame('contains:"Taylor","Taylor"', (string) $rule);

        $rule = Rule::contains([1, 2, 3]);
        $this->assertSame('contains:"1","2","3"', (string) $rule);

        $rule = Rule::contains(['"foo"', '"bar"', '"baz"']);
        $this->assertSame('contains:"""foo""","""bar""","""baz"""', (string) $rule);

        $rule = Rule::contains(new Values);
        $this->assertSame('contains:"1","2","3","4"', (string) $rule);

        $rule = new Contains(['foo', 'bar']);
        $this->assertSame('contains:"foo","bar"', (string) $rule);

        $rule = new Contains(collect(['foo', 'bar']));
        $this->assertSame('contains:"foo","bar"', (string) $rule);

        $rule = new Contains('foo', 'bar', 'baz');
        $this->assertSame('contains:"foo","bar","baz"', (string) $rule);

        $rule = Rule::contains([IntegerStatus::Done]);
        $this->assertSame('contains:"2"', (string) $rule);
    }

    public function testContainsValidation(): void
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

        // Combined with other rules
        $v = new Validator($trans, ['roles' => ['admin', 'user']], ['roles' => ['required', 'array', Rule::contains('admin')]]);
        $this->assertTrue($v->passes());
    }
}
