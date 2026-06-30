<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rule;
use Hypervel\Validation\Rules\ProhibitedUnless;
use Hypervel\Validation\Validator;
use stdClass;
use TypeError;

class ValidationProhibitedUnlessRuleTest extends TestCase
{
    protected Translator $translator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->translator = new Translator(new ArrayLoader, 'en');
    }

    public function testInstanceOf(): void
    {
        $this->assertInstanceOf(ProhibitedUnless::class, Rule::prohibitedUnless(true));
    }

    public function testBooleanConditionTrue(): void
    {
        $rule = Rule::prohibitedUnless(true);
        $this->assertSame('', (string) $rule);
    }

    public function testBooleanConditionFalse(): void
    {
        $rule = Rule::prohibitedUnless(false);
        $this->assertSame('prohibited', (string) $rule);
    }

    public function testClosureConditionTrue(): void
    {
        $rule = Rule::prohibitedUnless(fn () => true);
        $this->assertSame('', (string) $rule);
    }

    public function testClosureConditionFalse(): void
    {
        $rule = Rule::prohibitedUnless(fn () => false);
        $this->assertSame('prohibited', (string) $rule);
    }

    public function testFieldIsProhibitedWhenConditionFalse(): void
    {
        $validator = new Validator(
            $this->translator,
            ['name' => 'Taylor', 'secret' => 'value'],
            [
                'name' => 'required|string',
                'secret' => [Rule::prohibitedUnless(false)],
            ],
        );

        $this->assertTrue($validator->fails());
    }

    public function testFieldIsAllowedWhenConditionTrue(): void
    {
        $validator = new Validator(
            $this->translator,
            ['name' => 'Taylor', 'secret' => 'value'],
            [
                'name' => 'required|string',
                'secret' => [Rule::prohibitedUnless(true)],
            ],
        );

        $this->assertTrue($validator->passes());
    }

    public function testInvalidConditionThrows(): void
    {
        foreach ([1, 1.1, 'phpinfo', new stdClass, null] as $condition) {
            try {
                Rule::prohibitedUnless($condition);
                $this->fail('The ProhibitedUnless constructor must not accept ' . gettype($condition));
            } catch (TypeError) {
                $this->assertTrue(true);
            }
        }
    }
}
