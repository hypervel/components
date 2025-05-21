<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Exception;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rules\ProhibitedIf;
use Hypervel\Validation\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class ValidationProhibitedIfTest extends TestCase
{
    public function testItReturnsStringVersionOfRuleWhenCast()
    {
        $rule = new ProhibitedIf(function () {
            return true;
        });

        $this->assertSame('prohibited', (string) $rule);

        $rule = new ProhibitedIf(function () {
            return false;
        });

        $this->assertSame('', (string) $rule);

        $rule = new ProhibitedIf(true);

        $this->assertSame('prohibited', (string) $rule);

        $rule = new ProhibitedIf(false);

        $this->assertSame('', (string) $rule);
    }

    public function testItValidatesCallableAndBooleanAreAcceptableArguments()
    {
        new ProhibitedIf(false);
        new ProhibitedIf(true);
        new ProhibitedIf(fn () => true);

        foreach ([1, 1.1, 'phpinfo', new stdClass()] as $condition) {
            try {
                new ProhibitedIf($condition);
                $this->fail('The ProhibitedIf constructor must not accept ' . gettype($condition));
            } catch (InvalidArgumentException $exception) {
                $this->assertEquals('The provided condition must be a callable or boolean.', $exception->getMessage());
            }
        }
    }

    public function testItThrowsExceptionIfRuleIsNotSerializable()
    {
        $this->expectException(Exception::class);

        serialize(new ProhibitedIf(function () {
            return true;
        }));
    }

    public function testProhibitedIfRuleValidation()
    {
        $trans = new Translator(new ArrayLoader(), 'en');

        $rule = new ProhibitedIf(true);

        $v = new Validator($trans, ['y' => 'foo'], ['x' => $rule]);
        $this->assertTrue($v->passes());

        $v = new Validator($trans, ['y' => 'foo'], ['x' => (string) $rule]);
        $this->assertTrue($v->passes());

        $v = new Validator($trans, ['y' => 'foo'], ['x' => [$rule]]);
        $this->assertTrue($v->passes());

        $v = new Validator($trans, ['x' => 'foo'], ['x' => ['string', $rule]]);
        $this->assertTrue($v->fails());

        $rule = new ProhibitedIf(false);

        $v = new Validator($trans, ['x' => 'foo'], ['x' => ['string', $rule]]);
        $this->assertTrue($v->passes());
    }
}
