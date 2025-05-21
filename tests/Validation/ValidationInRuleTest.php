<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\Validation\fixtures\Values;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rule;
use Hypervel\Validation\Rules\In;
use Hypervel\Validation\Validator;
use PHPUnit\Framework\TestCase;

include_once 'Enums.php';

/**
 * @internal
 * @coversNothing
 */
class ValidationInRuleTest extends TestCase
{
    public function testItCorrectlyFormatsAStringVersionOfTheRule()
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

        $rule = Rule::in(new Values());

        $this->assertSame('in:"1","2","3","4"', (string) $rule);

        $rule = Rule::in('1', '2', '3', '4');

        $this->assertSame('in:"1","2","3","4"', (string) $rule);

        $rule = new In('1', '2', '3', '4');

        $this->assertSame('in:"1","2","3","4"', (string) $rule);

        $rule = Rule::in([StringStatus::done]);

        $this->assertSame('in:"done"', (string) $rule);

        $rule = Rule::in([IntegerStatus::done]);

        $this->assertSame('in:"2"', (string) $rule);

        $rule = Rule::in([PureEnum::one]);

        $this->assertSame('in:"one"', (string) $rule);
    }

    public function testInRuleValidation()
    {
        $trans = new Translator(new ArrayLoader(), 'en');

        $v = new Validator($trans, ['x' => 'foo'], ['x' => Rule::in('foo', 'bar')]);
        $this->assertTrue($v->passes());

        $v = new Validator($trans, ['x' => 'foo'], ['x' => (string) Rule::in('foo', 'bar')]);
        $this->assertTrue($v->passes());

        $v = new Validator($trans, ['x' => 'foo'], ['x' => [Rule::in('bar', 'baz')]]);
        $this->assertFalse($v->passes());

        $v = new Validator($trans, ['x' => 'foo'], ['x' => ['required', Rule::in('foo', 'bar')]]);
        $this->assertTrue($v->passes());
    }
}
