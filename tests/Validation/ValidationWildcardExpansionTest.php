<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Validator;

class ValidationWildcardExpansionTest extends TestCase
{
    public function testLargeArrayWithSimpleRulesAllPass()
    {
        $items = [];
        for ($i = 0; $i < 500; ++$i) {
            $items[] = ['name' => 'Item ' . $i];
        }

        $v = $this->makeValidator(
            ['items' => $items],
            ['items.*.name' => 'required|string'],
        );

        $this->assertTrue($v->passes());
    }

    public function testLargeArrayReportsFailureOnCorrectIndex()
    {
        $items = [];
        for ($i = 0; $i < 500; ++$i) {
            $items[] = ['name' => $i === 250 ? 12345 : 'Item ' . $i];
        }

        $v = $this->makeValidator(
            ['items' => $items],
            ['items.*.name' => 'required|string'],
        );

        $this->assertFalse($v->passes());
        $this->assertTrue($v->errors()->has('items.250.name'));
        $this->assertFalse($v->errors()->has('items.249.name'));
        $this->assertFalse($v->errors()->has('items.251.name'));
    }

    public function testEmptyArrayValidatesSuccessfully()
    {
        $v = $this->makeValidator(
            ['items' => []],
            ['items.*.name' => 'required|string'],
        );

        $this->assertTrue($v->passes());
    }

    public function testDeeplyNestedWildcards()
    {
        $data = [
            'orders' => [
                ['items' => [['sku' => 'A'], ['sku' => 'B']]],
                ['items' => [['sku' => 'C']]],
            ],
        ];

        $v = $this->makeValidator($data, ['orders.*.items.*.sku' => 'required|string']);

        $this->assertTrue($v->passes());
    }

    public function testDeeplyNestedWildcardsReportCorrectPath()
    {
        $data = [
            'orders' => [
                ['items' => [['sku' => 'A'], ['sku' => '']]],
                ['items' => [['sku' => 'C']]],
            ],
        ];

        $v = $this->makeValidator($data, ['orders.*.items.*.sku' => 'required|string']);

        $this->assertFalse($v->passes());
        $this->assertTrue($v->errors()->has('orders.0.items.1.sku'));
    }

    public function testMixedStringAndNumericKeys()
    {
        $data = [
            'settings' => [
                'theme' => ['color' => 'blue'],
                'layout' => ['color' => 'red'],
            ],
        ];

        $v = $this->makeValidator($data, ['settings.*.color' => 'required|string']);

        $this->assertTrue($v->passes());
    }

    public function testMixedStringAndNumericKeysReportCorrectPath()
    {
        $data = [
            'settings' => [
                'theme' => ['color' => 123],
                'layout' => ['color' => 'red'],
            ],
        ];

        $v = $this->makeValidator($data, ['settings.*.color' => 'required|string']);

        $this->assertFalse($v->passes());
        $this->assertTrue($v->errors()->has('settings.theme.color'));
        $this->assertFalse($v->errors()->has('settings.layout.color'));
    }

    public function testWildcardWithMultipleRuleTypes()
    {
        $items = [];
        for ($i = 0; $i < 100; ++$i) {
            $items[] = [
                'name' => 'Item ' . $i,
                'price' => rand(1, 100),
                'code' => 'ABC' . $i,
            ];
        }

        $v = $this->makeValidator(
            ['items' => $items],
            [
                'items.*.name' => 'required|string|max:255',
                'items.*.price' => 'required|numeric|min:0',
                'items.*.code' => 'required|string|alpha_num',
            ],
        );

        $this->assertTrue($v->passes());
    }

    public function testTopLevelWildcard()
    {
        $v = $this->makeValidator(
            ['foo', 'bar', 'baz'],
            ['*' => 'required|string'],
        );

        $this->assertTrue($v->passes());
    }

    public function testValidatedOutputIncludesWildcardExpandedAttributes()
    {
        $v = $this->makeValidator(
            ['items' => [['name' => 'A'], ['name' => 'B']]],
            ['items.*.name' => 'required|string'],
        );

        $validated = $v->validate();

        $this->assertSame(['items' => [['name' => 'A'], ['name' => 'B']]], $validated);
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
