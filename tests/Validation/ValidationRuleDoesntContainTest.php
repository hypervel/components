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
use Hypervel\Validation\Rules\DoesntContain;
use Hypervel\Validation\Validator;
use PHPUnit\Framework\Attributes\TestWith;

class ValidationRuleDoesntContainTest extends TestCase
{
    public function testItCorrectlyFormatsAStringVersionOfTheRule(): void
    {
        $rule = Rule::doesntContain('Taylor');
        $this->assertSame('doesnt_contain:"Taylor"', (string) $rule);

        $rule = Rule::doesntContain('Taylor', 'Abigail');
        $this->assertSame('doesnt_contain:"Taylor","Abigail"', (string) $rule);

        $rule = Rule::doesntContain(['Taylor', 'Abigail']);
        $this->assertSame('doesnt_contain:"Taylor","Abigail"', (string) $rule);

        $rule = Rule::doesntContain(collect(['Taylor', 'Abigail']));
        $this->assertSame('doesnt_contain:"Taylor","Abigail"', (string) $rule);

        $rule = Rule::doesntContain([ArrayKeys::key_1, ArrayKeys::key_2]);
        $this->assertSame('doesnt_contain:"key_1","key_2"', (string) $rule);

        $rule = Rule::doesntContain([ArrayKeysBacked::Key1, ArrayKeysBacked::Key2]);
        $this->assertSame('doesnt_contain:"key_1","key_2"', (string) $rule);

        $rule = Rule::doesntContain(['Taylor', 'Taylor']);
        $this->assertSame('doesnt_contain:"Taylor","Taylor"', (string) $rule);

        $rule = Rule::doesntContain([1, 2, 3]);
        $this->assertSame('doesnt_contain:"1","2","3"', (string) $rule);

        $rule = Rule::doesntContain(['"foo"', '"bar"', '"baz"']);
        $this->assertSame('doesnt_contain:"""foo""","""bar""","""baz"""', (string) $rule);

        $rule = Rule::doesntContain(new Values);
        $this->assertSame('doesnt_contain:"1","2","3","4"', (string) $rule);

        $rule = new DoesntContain(['foo', 'bar']);
        $this->assertSame('doesnt_contain:"foo","bar"', (string) $rule);

        $rule = new DoesntContain(collect(['foo', 'bar']));
        $this->assertSame('doesnt_contain:"foo","bar"', (string) $rule);

        $rule = new DoesntContain('foo', 'bar', 'baz');
        $this->assertSame('doesnt_contain:"foo","bar","baz"', (string) $rule);

        $rule = Rule::doesntContain([IntegerStatus::Done]);
        $this->assertSame('doesnt_contain:"2"', (string) $rule);
    }

    public function testDoesntContainValidation(): void
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

        // Combined with other rules
        $v = new Validator($trans, ['roles' => ['guest', 'user']], ['roles' => ['required', 'array', Rule::doesntContain('admin')]]);
        $this->assertTrue($v->passes());
    }

    #[TestWith([['0e123'], true])]
    #[TestWith([['0'], false])]
    public function testDoesntContainRuleDoesNotUseLooseComparisons(array $value, bool $expectation): void
    {
        $trans = new Translator(new ArrayLoader, 'en');

        $v = new Validator($trans, ['x' => $value], ['x' => ['doesnt_contain:0']]);

        $this->assertSame($expectation, $v->passes());
    }

    public function testDoesntContainMessageFormatsValues(): void
    {
        $trans = new Translator(new ArrayLoader, 'en');
        $trans->addLines(['validation.doesnt_contain' => ':attribute must not contain :values.'], 'en');

        $v = new Validator($trans, ['roles' => ['admin', 'user']], ['roles' => 'doesnt_contain:admin,editor']);
        $this->assertFalse($v->passes());
        $this->assertSame('roles must not contain admin, editor.', $v->messages()->first('roles'));
    }
}
