<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\TestCase;
use Hypervel\Validation\BatchDatabaseChecker;
use Hypervel\Validation\DatabasePresenceVerifier;
use Hypervel\Validation\PrecomputedPresenceVerifier;
use Mockery as m;
use stdClass;
use Stringable;

class ValidationBatchDatabaseCheckerTest extends TestCase
{
    public function testUsesTheProvidedVerifierWithNormalizedOneDimensionalValues(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return '3';
            }
        };
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')
            ->once()
            ->with('users', 'id', ['1', '2', '3'], 'named', 'ignored', 'uuid', ['status' => 'active'])
            ->andReturn([1, 2, 3]);

        $verifier = BatchDatabaseChecker::buildVerifier([
            'users' => ['meta' => $this->metadata([
                'connection' => 'named',
                'ignore' => 'ignored',
                'idColumn' => 'uuid',
                'wheres' => ['status' => 'active'],
            ]), 'values' => [[1, 2], 2, $stringable]],
        ], $presenceVerifier);

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame(3, $verifier->getMultiCount('users', 'id', [1, 2, 3]));
    }

    public function testChunksLargeValueSetsBeforeCallingTheVerifier(): void
    {
        $chunkSizes = [];
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')
            ->twice()
            ->andReturnUsing(function (string $collection, string $column, array $values) use (&$chunkSizes): array {
                $chunkSizes[] = count($values);

                return $values;
            });

        $verifier = BatchDatabaseChecker::buildVerifier([
            'users' => ['meta' => $this->metadata(), 'values' => range(1, 1001)],
        ], $presenceVerifier);

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame([1000, 1], $chunkSizes);
    }

    public function testUnsupportedNestedValueDeclinesTheWholeGroup(): void
    {
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldNotReceive('getExistingValues');

        $this->assertNull(BatchDatabaseChecker::buildVerifier([
            'users' => ['meta' => $this->metadata(), 'values' => [[1, new stdClass]]],
        ], $presenceVerifier));
    }

    public function testEmptyValueSetDoesNotCreateALookup(): void
    {
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldNotReceive('getExistingValues');

        $this->assertNull(BatchDatabaseChecker::buildVerifier([
            'users' => ['meta' => $this->metadata(), 'values' => []],
        ], $presenceVerifier));
    }

    /**
     * Build batch metadata.
     *
     * @return array{connection: ?string, table: string, column: string, wheres: array<string, mixed>, ignore: mixed, idColumn: string, type: string}
     */
    private function metadata(array $overrides = []): array
    {
        return array_replace([
            'connection' => null,
            'table' => 'users',
            'column' => 'id',
            'wheres' => [],
            'ignore' => null,
            'idColumn' => 'id',
            'type' => 'unique',
        ], $overrides);
    }
}
