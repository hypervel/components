<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rule;
use Hypervel\Validation\Rules\RequiredUnless;
use Hypervel\Validation\Validator;
use stdClass;
use TypeError;

class ValidationRequiredUnlessRuleTest extends TestCase
{
    protected Translator $translator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->translator = new Translator(new ArrayLoader, 'en');
    }

    public function testInstanceOf(): void
    {
        $this->assertInstanceOf(RequiredUnless::class, Rule::requiredUnless(true));
    }

    public function testBooleanConditionTrue(): void
    {
        $rule = Rule::requiredUnless(true);
        $this->assertSame('', (string) $rule);
    }

    public function testBooleanConditionFalse(): void
    {
        $rule = Rule::requiredUnless(false);
        $this->assertSame('required', (string) $rule);
    }

    public function testBooleanConditionNull(): void
    {
        $rule = Rule::requiredUnless(null);
        $this->assertSame('required', (string) $rule);
    }

    public function testClosureConditionTrue(): void
    {
        $rule = Rule::requiredUnless(fn () => true);
        $this->assertSame('', (string) $rule);
    }

    public function testClosureConditionFalse(): void
    {
        $rule = Rule::requiredUnless(fn () => false);
        $this->assertSame('required', (string) $rule);
    }

    public function testFieldIsRequiredWhenConditionFalse(): void
    {
        $validator = new Validator(
            $this->translator,
            ['name' => 'Taylor'],
            [
                'name' => 'required|string',
                'age' => [Rule::requiredUnless(false), 'integer'],
            ],
        );

        $this->assertTrue($validator->fails());
    }

    public function testFieldIsOptionalWhenConditionTrue(): void
    {
        $validator = new Validator(
            $this->translator,
            ['name' => 'Taylor'],
            [
                'name' => 'required|string',
                'age' => [Rule::requiredUnless(true), 'integer'],
            ],
        );

        $this->assertTrue($validator->passes());
    }

    public function testInvalidConditionThrows(): void
    {
        foreach ([1, 1.1, 'phpinfo', new stdClass] as $condition) {
            try {
                Rule::requiredUnless($condition);
                $this->fail('The RequiredUnless constructor must not accept ' . gettype($condition));
            } catch (TypeError) {
                $this->assertTrue(true);
            }
        }
    }
}
