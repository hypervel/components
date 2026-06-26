<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rule;
use Hypervel\Validation\Rules\ExcludeUnless;
use Hypervel\Validation\Validator;
use stdClass;
use TypeError;

class ValidationExcludeUnlessRuleTest extends TestCase
{
    protected Translator $translator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->translator = new Translator(new ArrayLoader, 'en');
    }

    public function testInstanceOf(): void
    {
        $this->assertInstanceOf(ExcludeUnless::class, Rule::excludeUnless(true));
    }

    public function testBooleanConditionTrue(): void
    {
        $rule = Rule::excludeUnless(true);
        $this->assertSame('', (string) $rule);
    }

    public function testBooleanConditionFalse(): void
    {
        $rule = Rule::excludeUnless(false);
        $this->assertSame('exclude', (string) $rule);
    }

    public function testClosureConditionTrue(): void
    {
        $rule = Rule::excludeUnless(fn () => true);
        $this->assertSame('', (string) $rule);
    }

    public function testClosureConditionFalse(): void
    {
        $rule = Rule::excludeUnless(fn () => false);
        $this->assertSame('exclude', (string) $rule);
    }

    public function testFieldIsExcludedWhenConditionFalse(): void
    {
        $validator = new Validator(
            $this->translator,
            ['name' => 'Taylor', 'extra' => 'value'],
            [
                'name' => 'required|string',
                'extra' => [Rule::excludeUnless(false), 'string'],
            ],
        );

        $this->assertTrue($validator->passes());
        $this->assertArrayNotHasKey('extra', $validator->validated());
    }

    public function testFieldIsKeptWhenConditionTrue(): void
    {
        $validator = new Validator(
            $this->translator,
            ['name' => 'Taylor', 'extra' => 'value'],
            [
                'name' => 'required|string',
                'extra' => [Rule::excludeUnless(true), 'string'],
            ],
        );

        $this->assertTrue($validator->passes());
        $this->assertArrayHasKey('extra', $validator->validated());
    }

    public function testInvalidConditionThrows(): void
    {
        foreach ([1, 1.1, 'phpinfo', new stdClass, null] as $condition) {
            try {
                Rule::excludeUnless($condition);
                $this->fail('The ExcludeUnless constructor must not accept ' . gettype($condition));
            } catch (TypeError) {
                $this->assertTrue(true);
            }
        }
    }
}
