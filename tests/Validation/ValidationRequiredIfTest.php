<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Exception;
use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rules\RequiredIf;
use Hypervel\Validation\Validator;
use stdClass;
use TypeError;

class ValidationRequiredIfTest extends TestCase
{
    public function testItClosureReturnsFormatsAStringVersionOfTheRule(): void
    {
        $rule = new RequiredIf(function () {
            return true;
        });

        $this->assertSame('required', (string) $rule);

        $rule = new RequiredIf(function () {
            return false;
        });

        $this->assertSame('', (string) $rule);

        $rule = new RequiredIf(true);

        $this->assertSame('required', (string) $rule);

        $rule = new RequiredIf(false);

        $this->assertSame('', (string) $rule);

        $rule = new RequiredIf(null);

        $this->assertSame('', (string) $rule);
    }

    public function testItOnlyClosureBooleanAndNullAreAcceptableArgumentsOfTheRule(): void
    {
        new RequiredIf(false);
        new RequiredIf(true);
        new RequiredIf(null);
        new RequiredIf(fn () => true);

        foreach ([1, 1.1, 'phpinfo', new stdClass] as $condition) {
            try {
                new RequiredIf($condition);
                $this->fail('The RequiredIf constructor must not accept ' . gettype($condition));
            } catch (TypeError) {
                $this->assertTrue(true);
            }
        }
    }

    public function testItReturnedRuleIsNotSerializable(): void
    {
        $this->expectException(Exception::class);

        $rule = serialize(new RequiredIf(function () {
            return true;
        }));
    }

    public function testRequiredIfRuleValidation(): void
    {
        $trans = new Translator(new ArrayLoader, 'en');

        $rule = new RequiredIf(true);

        $v = new Validator($trans, ['x' => 'foo'], ['x' => $rule]);
        $this->assertTrue($v->passes());

        $v = new Validator($trans, ['x' => ''], ['x' => (string) $rule]);
        $this->assertTrue($v->fails());

        $v = new Validator($trans, ['x' => 'foo'], ['x' => [$rule]]);
        $this->assertTrue($v->passes());

        $v = new Validator($trans, ['x' => 'foo'], ['x' => ['string', $rule]]);
        $this->assertTrue($v->passes());

        $rule = new RequiredIf(false);

        $v = new Validator($trans, ['x' => 'foo'], ['x' => ['string', $rule]]);
        $this->assertTrue($v->passes());
    }
}
