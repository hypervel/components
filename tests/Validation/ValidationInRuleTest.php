<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Contracts\Validation\Rule as RuleContract;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\Validation\Fixtures\IntegerStatus;
use Hypervel\Tests\Validation\Fixtures\PureEnum;
use Hypervel\Tests\Validation\Fixtures\StringStatus;
use Hypervel\Tests\Validation\Fixtures\Values;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rule;
use Hypervel\Validation\Rules\In;
use Hypervel\Validation\Validator;
use PHPUnit\Framework\Attributes\TestWith;

class ValidationInRuleTest extends TestCase
{
    public function testItCorrectlyFormatsAStringVersionOfTheRule(): void
    {
        $rule = new In(['Laravel', 'Framework', 'PHP']);

        $this->assertSame('in:"Laravel","Framework","PHP"', (string) $rule);

        $rule = new In(collect(['Taylor', 'Michael', 'Tim']));

        $this->assertSame('in:"Taylor","Michael","Tim"', (string) $rule);

        $rule = new In(['Life, the Universe and Everything', 'this is a "quote"']);

        $this->assertSame('in:"Life, the Universe and Everything","this is a ""quote"""', (string) $rule);

        $rule = Rule::in(collect([1, 2, 3, 4]));

        $this->assertSame('in:"1","2","3","4"', (string) $rule);

        $rule = Rule::in(collect([1, 2, 3, 4]));

        $this->assertSame('in:"1","2","3","4"', (string) $rule);

        $rule = new In(["a,b\nc,d"]);

        $this->assertSame("in:\"a,b\nc,d\"", (string) $rule);

        $rule = Rule::in([1, 2, 3, 4]);

        $this->assertSame('in:"1","2","3","4"', (string) $rule);

        $rule = Rule::in(collect([1, 2, 3, 4]));

        $this->assertSame('in:"1","2","3","4"', (string) $rule);

        $rule = Rule::in(new Values);

        $this->assertSame('in:"1","2","3","4"', (string) $rule);

        $rule = Rule::in('1', '2', '3', '4');

        $this->assertSame('in:"1","2","3","4"', (string) $rule);

        $rule = new In('1', '2', '3', '4');

        $this->assertSame('in:"1","2","3","4"', (string) $rule);

        $rule = Rule::in([StringStatus::Done]);

        $this->assertSame('in:"done"', (string) $rule);

        $rule = Rule::in([IntegerStatus::Done]);

        $this->assertSame('in:"2"', (string) $rule);

        $rule = Rule::in([PureEnum::one]);

        $this->assertSame('in:"one"', (string) $rule);
    }

    public function testInRuleValidation(): void
    {
        $trans = new Translator(new ArrayLoader, 'en');

        $v = new Validator($trans, ['x' => 'foo'], ['x' => Rule::in('foo', 'bar')]);
        $this->assertTrue($v->passes());

        $v = new Validator($trans, ['x' => 'foo'], ['x' => (string) Rule::in('foo', 'bar')]);
        $this->assertTrue($v->passes());

        $v = new Validator($trans, ['x' => 'foo'], ['x' => [Rule::in('bar', 'baz')]]);
        $this->assertFalse($v->passes());

        $v = new Validator($trans, ['x' => 'foo'], ['x' => ['required', Rule::in('foo', 'bar')]]);
        $this->assertTrue($v->passes());

        $number = 1.234567890123456;
        $v = new Validator($trans, ['x' => $number], ['x' => Rule::in([$number])]);
        $this->assertTrue($v->passes());

        $validator = new Validator($trans, ['x' => 1.2345678901234558], ['x' => Rule::in([$number])]);
        $this->assertFalse($validator->passes());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testCustomRuleExecutionTakesPrecedenceOverTheInheritedDeclaration(bool $delegated): void
    {
        $rule = new class(['allowed']) extends In implements RuleContract {
            public int $calls = 0;

            /**
             * Reject a value that the inherited membership declaration would allow.
             */
            public function passes(string $attribute, mixed $value): bool
            {
                ++$this->calls;

                return false;
            }

            /**
             * Get the custom validation message.
             */
            public function message(): string
            {
                return 'The custom rule rejected this value.';
            }
        };
        $arguments = [new Translator(new ArrayLoader, 'en'), ['value' => 'allowed'], ['value' => [$rule]]];
        $validator = $delegated ? new class(...$arguments) extends Validator {} : new Validator(...$arguments);

        $this->assertFalse($validator->passes());
        $this->assertSame(1, $rule->calls);
        $this->assertSame('The custom rule rejected this value.', $validator->errors()->first('value'));
    }

    public function testCanonicalExportControlsRenderingAndValidation(): void
    {
        $rule = new class(['original']) extends In {
            /**
             * Export the effective values instead of the constructor's defaults.
             */
            public function toArray(): array
            {
                return ['in', 'replacement'];
            }
        };
        $translator = new Translator(new ArrayLoader, 'en');

        $this->assertSame('in:"replacement"', (string) $rule);
        $this->assertTrue((new Validator($translator, ['value' => 'replacement'], ['value' => $rule]))->passes());
        $this->assertFalse((new Validator($translator, ['value' => 'original'], ['value' => $rule]))->passes());
    }

    #[TestWith([' 1', false])]
    #[TestWith(['1 ', false])]
    #[TestWith(["\t1", false])]
    #[TestWith(["1\n", false])]
    #[TestWith(['01', false])]
    #[TestWith(['+1', false])]
    #[TestWith(['1.0', false])]
    #[TestWith(['1e0', false])]
    #[TestWith(['1', true])]
    public function testInRuleIsNotLoosyBypassed(mixed $value, bool $expectation): void
    {
        $trans = new Translator(new ArrayLoader, 'en');

        $v = new Validator($trans, ['x' => $value], ['x' => ['in:1,2,3']]);

        $this->assertSame($expectation, $v->passes());
    }
}
