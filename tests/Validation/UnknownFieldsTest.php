<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\UnknownFields;
use Hypervel\Validation\Validator;

class UnknownFieldsTest extends TestCase
{
    public function testItRejectsUnknownFields(): void
    {
        $validator = $this->validator(
            ['name' => 'Taylor'],
            ['name' => 'required'],
            ['name' => 'Taylor', 'role' => 'admin'],
        );

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('role'));
    }

    public function testItAllowsDefaultAndCustomConfirmationFields(): void
    {
        $validator = $this->validator(
            [
                'password' => 'secret',
                'password_confirmation' => 'secret',
                'pin' => '1234',
                'repeat_pin' => '1234',
            ],
            [
                'password' => 'confirmed',
                'pin' => 'confirmed:repeat_pin',
            ],
            [
                'password' => 'secret',
                'password_confirmation' => 'secret',
                'pin' => '1234',
                'repeat_pin' => '1234',
            ],
        );

        $this->assertFalse($validator->fails());
    }

    public function testItAllowsContentsOfLeafArrayRules(): void
    {
        $validator = $this->validator(
            [
                'meta' => ['source' => 'import'],
                'tags' => ['framework', 'php'],
                'items' => [['name' => 'first']],
            ],
            [
                'meta' => 'array',
                'tags' => 'array',
                'items.*' => 'array',
            ],
            [
                'meta' => ['source' => 'import'],
                'tags' => ['framework', 'php'],
                'items' => [['name' => 'first']],
            ],
        );

        $this->assertFalse($validator->fails());
    }

    public function testArrayRulesWithDescendantsRemainStructured(): void
    {
        $validator = $this->validator(
            ['items' => [['id' => 1, 'name' => 'first']]],
            [
                'items' => 'array',
                'items.*.id' => 'required|integer',
            ],
            ['items' => [['id' => 1, 'name' => 'first']]],
        );

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('items.0.name'));
    }

    public function testItAcceptsAdditionalExactFieldsAndExplicitSubtrees(): void
    {
        $validator = $this->validator(
            [],
            [],
            [
                'context' => 'server-owned',
                'meta' => ['source' => 'import'],
            ],
            additionalFields: ['context'],
            allowedSubtrees: ['meta'],
        );

        $this->assertFalse($validator->fails());
    }

    public function testAdditionalFieldsDoNotAllowDescendants(): void
    {
        $validator = $this->validator(
            [],
            [],
            ['context' => ['id' => 1]],
            additionalFields: ['context'],
        );

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('context.id'));
    }

    public function testItAcceptsWildcardAdditionalFields(): void
    {
        $validator = $this->validator(
            [],
            [],
            ['items' => [['serverUser' => 1]]],
            additionalFields: ['items.*.serverUser'],
        );

        $this->assertFalse($validator->fails());
    }

    public function testWildcardAdditionalFieldsDoNotAllowDescendants(): void
    {
        $validator = $this->validator(
            [],
            [],
            ['items' => [['context' => ['id' => 1]]]],
            additionalFields: ['items.*.context'],
        );

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('items.0.context.id'));
    }

    public function testItAcceptsWildcardSubtreesAndTheirOwnEmptyLeaf(): void
    {
        $validator = $this->validator(
            [],
            [],
            [
                'items' => [
                    ['meta' => ['source' => 'import']],
                    ['meta' => []],
                ],
            ],
            allowedSubtrees: ['items.*.meta'],
        );

        $this->assertFalse($validator->fails());
    }

    public function testItTreatsEscapedAsterisksAsLiteralAuxiliarySegments(): void
    {
        $validator = $this->validator(
            [],
            [],
            ['items' => [['literal*' => 'value']]],
            additionalFields: ['items.*.literal\*'],
        );

        $this->assertFalse($validator->fails());
    }

    public function testPartialAsteriskAuxiliarySegmentsFailClosed(): void
    {
        $validator = $this->validator(
            [],
            [],
            [
                'items' => [
                    'a*' => ['value' => 1],
                    'alpha' => ['value' => 2],
                ],
            ],
            additionalFields: ['items.a*.value'],
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('items.a*.value', $validator->errors()->messages());
        $this->assertArrayHasKey('items.alpha.value', $validator->errors()->messages());
    }

    public function testItMergesUnfilteredRulesWithEffectiveRules(): void
    {
        $validator = $this->validator(
            ['name' => 'Taylor'],
            ['name' => 'required'],
            [
                'name' => 'Taylor',
                'email' => 'taylor@example.com',
            ],
            unfilteredRules: [
                'name' => ['required'],
                'email' => ['required', 'email'],
            ],
        );

        $this->assertFalse($validator->fails());
    }

    public function testItAllowsLiteralDotAndAsteriskKeys(): void
    {
        $input = [
            'maps' => ['example.com' => ['id' => 1]],
            'labels' => ['literal*' => 'value'],
        ];

        $validator = $this->validator(
            $input,
            [
                'maps.example\.com.id' => 'required|integer',
                'labels.literal\*' => 'required|string',
            ],
            $input,
        );

        $this->assertFalse($validator->fails());
    }

    public function testItAllowsWildcardExpandedLiteralAsteriskKeys(): void
    {
        $input = ['items' => ['literal*' => ['id' => 1]]];

        $validator = $this->validator(
            $input,
            ['items.*.id' => 'required|integer'],
            $input,
        );

        $this->assertFalse($validator->fails());
    }

    public function testItPreservesUnescapedPublicErrorPaths(): void
    {
        $validator = $this->validator(
            [],
            [],
            ['unknown.key' => ['child*' => 'value']],
        );

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('unknown.key.child*'));
        $this->assertFalse($validator->errors()->has('unknown\.key.child\*'));
    }

    public function testItMatchesAllowedSubtreesAcrossEscapedSegments(): void
    {
        $validator = $this->validator(
            [],
            [],
            ['maps' => ['example.com' => ['source' => 'import']]],
            allowedSubtrees: ['maps.example\.com'],
        );

        $this->assertFalse($validator->fails());
    }

    public function testEscapedSegmentsCannotMatchADifferentTrailingBackslashSubtree(): void
    {
        $validator = $this->validator(
            [],
            [],
            ['maps' => ['example.com' => ['source' => 'import']]],
            allowedSubtrees: ['maps.example\\'],
        );

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('maps.example.com.source'));
    }

    public function testTrailingBackslashSubtreeBeforeAChildFailsClosed(): void
    {
        $validator = $this->validator(
            [],
            [],
            ['maps' => ['back\\' => ['source' => 'import']]],
            allowedSubtrees: ['maps.back\\'],
        );

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('maps.back\.source'));
    }

    /**
     * Create a validator with unknown-field checking attached.
     *
     * @param array<string, mixed> $validationData
     * @param array<string, array|object|string> $rules
     * @param array<string, mixed> $input
     * @param null|array<string, array<int, array|object|string>> $unfilteredRules
     * @param list<string> $additionalFields
     * @param list<string> $allowedSubtrees
     */
    private function validator(
        array $validationData,
        array $rules,
        array $input,
        ?array $unfilteredRules = null,
        array $additionalFields = [],
        array $allowedSubtrees = [],
    ): Validator {
        $validator = new Validator(
            new Translator(new ArrayLoader, 'en'),
            $validationData,
            $rules,
        );

        $validator->after(static function (Validator $validator) use (
            $input,
            $unfilteredRules,
            $additionalFields,
            $allowedSubtrees,
        ): void {
            UnknownFields::validate(
                $validator,
                $input,
                $unfilteredRules,
                $additionalFields,
                $allowedSubtrees,
            );
        });

        return $validator;
    }
}
