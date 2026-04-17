<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Contracts\Validation\Rule as RuleContract;
use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Validator;

class ValidationPreEvaluatedExclusionsTest extends TestCase
{
    public function testExcludeUnlessRemovesAttributeWhenConditionNotMet()
    {
        $v = $this->makeValidator(
            ['type' => 'section', 'details' => 'some details'],
            ['type' => 'required|string', 'details' => 'exclude_unless:type,chapter|required|string'],
        );

        $this->assertTrue($v->passes());
        $this->assertArrayNotHasKey('details', $v->validated());
    }

    public function testExcludeUnlessKeepsAttributeWhenConditionMet()
    {
        $v = $this->makeValidator(
            ['type' => 'chapter', 'details' => 'some details'],
            ['type' => 'required|string', 'details' => 'exclude_unless:type,chapter|required|string'],
        );

        $this->assertTrue($v->passes());
        $this->assertArrayHasKey('details', $v->validated());
    }

    public function testExcludeIfRemovesAttributeWhenConditionMet()
    {
        $v = $this->makeValidator(
            ['type' => 'draft', 'publish_date' => '2025-01-01'],
            ['type' => 'required|string', 'publish_date' => 'exclude_if:type,draft|required|date'],
        );

        $this->assertTrue($v->passes());
        $this->assertArrayNotHasKey('publish_date', $v->validated());
    }

    public function testExcludeIfKeepsAttributeWhenConditionNotMet()
    {
        $v = $this->makeValidator(
            ['type' => 'published', 'publish_date' => '2025-01-01'],
            ['type' => 'required|string', 'publish_date' => 'exclude_if:type,draft|required|date'],
        );

        $this->assertTrue($v->passes());
        $this->assertArrayHasKey('publish_date', $v->validated());
    }

    public function testExcludeUnlessWithWildcardConditionField()
    {
        $v = $this->makeValidator(
            ['items' => [
                ['type' => 'chapter', 'position' => 5],
                ['type' => 'section', 'position' => 10],
            ]],
            [
                'items.*.type' => 'required|string',
                'items.*.position' => 'exclude_unless:items.*.type,chapter|required|integer',
            ],
        );

        $this->assertTrue($v->passes());
        $validated = $v->validated();
        $this->assertArrayHasKey('position', $validated['items'][0]);
        $this->assertArrayNotHasKey('position', $validated['items'][1]);
    }

    public function testSafetySkipForBooleanConditionField()
    {
        $v = $this->makeValidator(
            ['active' => true, 'details' => 'some details'],
            ['active' => 'required|boolean', 'details' => 'exclude_unless:active,true|required|string'],
        );

        $this->assertTrue($v->passes());
        $this->assertArrayHasKey('details', $v->validated());
    }

    public function testSafetySkipForNullConditionValue()
    {
        $v = $this->makeValidator(
            ['type' => null, 'details' => 'some details'],
            ['details' => 'exclude_if:type,null|string'],
        );

        $v->passes();
        // Should be excluded because type is null and the value matches 'null' sentinel
        $this->assertArrayNotHasKey('details', $v->validated());
    }

    public function testGuardSkipsPrePassWithCustomExtensions()
    {
        $v = $this->makeValidator(
            ['type' => 'section', 'details' => 'test'],
            ['type' => 'required|string', 'details' => 'exclude_unless:type,chapter|required|string'],
        );

        $v->addExtension('custom_rule', function () {
            return true;
        });

        // Pre-pass is skipped (extensions present), but exclude_unless still
        // works correctly via the DelegatedCheck path.
        $this->assertTrue($v->passes());
        $this->assertArrayNotHasKey('details', $v->validated());
    }

    public function testGuardSkipsPrePassWithRuleObjects()
    {
        $customRule = new class implements RuleContract {
            public function passes(string $attribute, mixed $value): bool
            {
                return true;
            }

            public function message(): array|string
            {
                return '';
            }
        };

        $v = $this->makeValidator(
            ['type' => 'section', 'name' => 'test', 'details' => 'test'],
            [
                'type' => 'required|string',
                'name' => [$customRule],
                'details' => 'exclude_unless:type,chapter|required|string',
            ],
        );

        // Pre-pass is skipped (rule objects present), but exclude_unless still
        // works correctly via the DelegatedCheck path.
        $this->assertTrue($v->passes());
        $this->assertArrayNotHasKey('details', $v->validated());
    }

    public function testMultipleExcludeConditionsOnDifferentAttributes()
    {
        $v = $this->makeValidator(
            [
                'type' => 'section',
                'field_a' => 'value_a',
                'field_b' => 'value_b',
            ],
            [
                'type' => 'required|string',
                'field_a' => 'exclude_unless:type,chapter|required|string',
                'field_b' => 'exclude_unless:type,article|required|string',
            ],
        );

        $this->assertTrue($v->passes());
        $validated = $v->validated();
        $this->assertArrayNotHasKey('field_a', $validated);
        $this->assertArrayNotHasKey('field_b', $validated);
    }

    private function makeValidator(array $data, array $rules): Validator
    {
        return new Validator(
            new Translator(new ArrayLoader, 'en'),
            $data,
            $rules,
        );
    }
}
