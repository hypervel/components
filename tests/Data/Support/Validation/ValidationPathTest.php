<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Validation;

use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Tests\TestCase;

class ValidationPathTest extends TestCase
{
    /**
     * Test wildcard segments expand against the complete payload.
     */
    public function testExpandsWildcardSegments(): void
    {
        $payload = [
            'sections' => [
                [
                    'items' => [
                        ['name' => 'A'],
                        ['name' => 'B'],
                    ],
                ],
                [
                    'items' => [
                        ['name' => 'C'],
                    ],
                ],
            ],
        ];

        $path = ValidationPath::create('sections.*.items.*.name');
        $matches = $path->matchingWildcardPayloadValidationPaths($payload);

        $this->assertSame([
            'sections.0.items.0.name',
            'sections.0.items.1.name',
            'sections.1.items.0.name',
        ], array_map(
            fn (ValidationPath $match): string => $match->get(),
            $matches,
        ));
    }

    /**
     * Test trailing wildcard segments expand to the matching values.
     */
    public function testExpandsTrailingWildcardSegments(): void
    {
        $path = ValidationPath::create('list_items.*');
        $matches = $path->matchingWildcardPayloadValidationPaths([
            'list_items' => ['First', 'Second'],
        ]);

        $this->assertSame([
            'list_items.0',
            'list_items.1',
        ], array_map(
            fn (ValidationPath $match): string => (string) $match,
            $matches,
        ));
    }

    /**
     * Test literal segments after a wildcard include missing validation leaves.
     */
    public function testExpandsMissingLiteralLeavesAfterWildcards(): void
    {
        $path = ValidationPath::create('items.*.profile.name');
        $matches = $path->matchingWildcardPayloadValidationPaths([
            'items' => [
                ['profile' => ['name' => 'Taylor']],
                ['profile' => []],
                [],
            ],
        ]);

        $this->assertSame([
            'items.0.profile.name',
            'items.1.profile.name',
            'items.2.profile.name',
        ], array_map(
            fn (ValidationPath $match): string => $match->get(),
            $matches,
        ));
    }

    /**
     * Test mapped properties and raw item keys retain distinct path semantics.
     */
    public function testAppendsMappedPropertiesAndRawItemKeys(): void
    {
        $path = ValidationPath::create()
            ->property('profile.names')
            ->item('first.item')
            ->property('label');

        $this->assertSame(
            ['profile', 'names', 'first.item', 'label'],
            $path->segments(),
        );
        $this->assertSame('profile.names.first\\.item.label', $path->get());
        $this->assertTrue($path->equals('profile.names.first\\.item.label'));
    }

    /**
     * Test escaped literal dots are parsed as one segment.
     */
    public function testCreatesPathsWithEscapedLiteralDots(): void
    {
        $path = ValidationPath::create('items.first\\.item.name');
        $wildcards = ValidationPath::create('items.*.literal\\*.name');

        $this->assertSame(['items', 'first.item', 'name'], $path->segments());
        $this->assertSame('items.first\\.item.name', $path->get());
        $this->assertSame(['items', null, 'literal*', 'name'], $wildcards->rawSegments());
    }

    /**
     * Test canonical paths preserve PHP integer array keys when reparsed.
     */
    public function testRoundTripsCanonicalIntegerAndEscapedSegments(): void
    {
        $path = new ValidationPath([
            'items',
            0,
            -1,
            null,
            'literal.dot',
            'literal*',
        ]);

        $this->assertSame(
            $path->rawSegments(),
            ValidationPath::create($path->get())->rawSegments(),
        );
    }

    /**
     * Test numeric-looking strings that PHP retains as array strings stay literal.
     */
    public function testRetainsNonCanonicalIntegerStrings(): void
    {
        $outOfRange = PHP_INT_MAX . '0';
        $path = ValidationPath::create(
            'items.01.+1.-0.1\.0.' . $outOfRange,
        );

        $this->assertSame([
            'items',
            '01',
            '+1',
            '-0',
            '1.0',
            $outOfRange,
        ], $path->rawSegments());
    }

    /**
     * Test trailing backslashes retain Validator's fail-closed path boundary.
     */
    public function testTrailingBackslashDoesNotPromiseRoundTripIdentity(): void
    {
        $path = new ValidationPath(['a\\', 'b']);

        $this->assertSame('a\\.b', $path->get());
        $this->assertSame(
            ['a.b'],
            ValidationPath::create($path->get())->rawSegments(),
        );
    }
}
