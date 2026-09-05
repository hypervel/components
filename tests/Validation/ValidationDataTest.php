<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\TestCase;
use Hypervel\Validation\ValidationData;
use ReflectionProperty;

class ValidationDataTest extends TestCase
{
    /**
     * Test attribute and data-key placeholders round trip.
     */
    public function testPlaceholderEncodingRoundTrips(): void
    {
        $attribute = 'settings\.theme.items.\*.value';
        $encodedAttribute = ValidationData::encodeAttribute($attribute);

        $this->assertSame($attribute, ValidationData::decodeAttribute($encodedAttribute));
        $this->assertSame('settings.theme.items.*.value', ValidationData::replacePlaceholderInString($encodedAttribute));

        $data = [
            'settings.theme' => [
                'literal*' => [
                    'child.name' => 'value',
                ],
            ],
        ];

        $this->assertSame($data, ValidationData::decodeKeys(ValidationData::encodeKeys($data)));
    }

    /**
     * Test placeholder state initializes lazily and can be flushed.
     */
    public function testPlaceholderHashLifecycle(): void
    {
        $placeholderHash = new ReflectionProperty(ValidationData::class, 'placeholderHash');

        ValidationData::flushState();

        $this->assertNull($placeholderHash->getValue());

        ValidationData::encodeAttribute('profile\.name');

        $this->assertIsString($placeholderHash->getValue());
        $this->assertNotSame('', $placeholderHash->getValue());

        ValidationData::flushState();

        $this->assertNull($placeholderHash->getValue());
    }

    /**
     * Test direct, nested, top-level, and partial wildcards.
     */
    public function testExpandsWildcardKeys(): void
    {
        $data = [
            'orders' => [
                ['items' => [['sku' => 'A'], ['sku' => 'B']]],
                'priority' => ['items' => [['sku' => 'C']]],
            ],
        ];

        $this->assertSame([
            'orders.0.items.0.sku',
            'orders.0.items.1.sku',
            'orders.priority.items.0.sku',
        ], ValidationData::expandWildcardKeys('orders.*.items.*.sku', $data));

        $this->assertSame(
            ['orders.priority.items.0.sku'],
            ValidationData::expandWildcardKeys('orders.pr*.items.*.sku', $data),
        );

        $this->assertSame(
            ['alpha.value'],
            ValidationData::expandWildcardKeys('a*.value', ['alpha' => ['value' => 1], 'beta' => ['value' => 2]]),
        );
    }

    /**
     * Test matched wildcards emit missing fixed leaves for required rules.
     */
    public function testExpandsMissingFixedLeavesBelowWildcards(): void
    {
        $this->assertSame([
            'items.0.value',
            'items.1.value',
        ], ValidationData::expandWildcardKeys('items.*.value', [
            'items' => [[], ['value' => 1]],
        ]));

        $this->assertSame([], ValidationData::expandWildcardKeys('items.*.value', []));
    }

    /**
     * Test wildcard expansion preserves escaped literal path segments.
     */
    public function testExpandsEncodedLiteralDotAndAsteriskKeys(): void
    {
        $data = ValidationData::encodeKeys([
            'groups' => [
                'theme.dark' => [
                    'literal*' => ['value' => 1],
                ],
            ],
        ]);
        $attribute = ValidationData::encodeAttribute('groups.*.literal\*.value');
        $keys = ValidationData::expandWildcardKeys($attribute, $data);

        $this->assertSame(
            ['groups.theme\.dark.literal\*.value'],
            array_map(ValidationData::decodeAttribute(...), $keys),
        );
    }
}
