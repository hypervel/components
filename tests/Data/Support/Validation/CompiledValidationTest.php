<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Validation;

use Hypervel\Data\Support\Validation\CompiledValidation;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Tests\TestCase;
use stdClass;

class CompiledValidationTest extends TestCase
{
    /**
     * Test preserved values are restored through exact raw path segments.
     */
    public function testRestoresPreservedValuesByExactPath(): void
    {
        $preserved = new stdClass;
        $compiled = new CompiledValidation(
            rules: [],
            preservedPaths: [
                ValidationPath::create('items')->item('first.item'),
            ],
        );

        $payload = $compiled->restorePreservedValues(
            ['items' => ['other' => 'value']],
            ['items' => ['first.item' => $preserved]],
        );

        $this->assertSame($preserved, $payload['items']['first.item']);
        $this->assertSame('value', $payload['items']['other']);
        $this->assertArrayNotHasKey('first', $payload['items']);
    }

    /**
     * Test wildcard paths restore each existing source leaf without key collisions.
     */
    public function testRestoresPreservedValuesByWildcardPath(): void
    {
        $compiled = new CompiledValidation(
            rules: [],
            preservedPaths: [
                ValidationPath::create('items')->wildcard()->property('secret'),
            ],
        );

        $payload = $compiled->restorePreservedValues(
            [
                'items' => [
                    0 => ['id' => 1],
                    1 => ['id' => 2],
                    'literal.item' => ['id' => 3],
                    '*' => ['id' => 4],
                ],
            ],
            [
                'items' => [
                    0 => ['id' => 1],
                    1 => ['id' => 2, 'secret' => 'two'],
                    'literal.item' => ['id' => 3, 'secret' => null],
                    '*' => ['id' => 4, 'secret' => 'star'],
                ],
            ],
        );

        $this->assertArrayNotHasKey('secret', $payload['items'][0]);
        $this->assertSame('two', $payload['items'][1]['secret']);
        $this->assertArrayHasKey('secret', $payload['items']['literal.item']);
        $this->assertNull($payload['items']['literal.item']['secret']);
        $this->assertSame('star', $payload['items']['*']['secret']);
    }

    /**
     * Test a literal asterisk item key does not become a structural wildcard.
     */
    public function testRestoresPreservedValuesByLiteralAsteriskPath(): void
    {
        $compiled = new CompiledValidation(
            rules: [],
            preservedPaths: [
                ValidationPath::create('items')->item('*')->property('secret'),
            ],
        );

        $payload = $compiled->restorePreservedValues(
            [
                'items' => [
                    '*' => ['id' => 1],
                    'other' => ['id' => 2],
                ],
            ],
            [
                'items' => [
                    '*' => ['id' => 1, 'secret' => 'star'],
                    'other' => ['id' => 2, 'secret' => 'other'],
                ],
            ],
        );

        $this->assertSame('star', $payload['items']['*']['secret']);
        $this->assertArrayNotHasKey('secret', $payload['items']['other']);
    }
}
