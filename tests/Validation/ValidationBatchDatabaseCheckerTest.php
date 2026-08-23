<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\TestCase;
use Hypervel\Validation\BatchDatabaseChecker;
use Hypervel\Validation\DatabasePresenceVerifier;
use Hypervel\Validation\PrecomputedPresenceVerifier;
use Mockery as m;
use RuntimeException;
use stdClass;
use Stringable;

class ValidationBatchDatabaseCheckerTest extends TestCase
{
    public function testUsesTheCompleteQueryShapeAndRetainsRawSqlBindings(): void
    {
        $meta = $this->metadata([
            'connection' => 'named',
            'ignore' => 'ignored',
            'idColumn' => 'uuid',
            'wheres' => ['status' => 'active'],
        ]);
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')
            ->once()
            ->with('users', 'id', [1, 2.5], 'named', 'ignored', 'uuid', ['status' => 'active'])
            ->andReturn(['1', '2.5']);
        $presenceVerifier->shouldReceive('getCount')
            ->once()
            ->with('users', 'id', '1', 'ignored', 'uuid', ['status' => 'active'])
            ->andReturn(0);
        $presenceVerifier->shouldReceive('setConnection')->once()->with('named');

        $verifier = BatchDatabaseChecker::buildVerifier($this->batchGroups($meta, [1, 2.5]), $presenceVerifier);

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $verifier->setConnection('named');
        $this->assertSame(1, $verifier->getCount('users', 'id', 1, 'ignored', 'uuid', ['status' => 'active']));
        $this->assertSame(1, $verifier->getCount('users', 'id', 2.5, 'ignored', 'uuid', ['status' => 'active']));
        $this->assertSame(0, $verifier->getCount('users', 'id', '1', 'ignored', 'uuid', ['status' => 'active']));
    }

    public function testNormalizesOneDimensionalArraysAndCastsStringableValuesOnce(): void
    {
        $casts = 0;
        $stringable = new class($casts) implements Stringable {
            public function __construct(private int &$casts)
            {
            }

            public function __toString(): string
            {
                ++$this->casts;

                return '3';
            }
        };
        $meta = $this->metadata();
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')
            ->once()
            ->with('users', 'id', [1, 2, '3'], null, null, null, [])
            ->andReturn(['1', '2', '3']);

        $verifier = BatchDatabaseChecker::buildVerifier($this->batchGroups($meta, [[1, 2], 2, $stringable]), $presenceVerifier);

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame(1, $casts);
        $this->assertSame(3, $verifier->getMultiCount('users', 'id', [1, 2, '3']));
    }

    public function testEqualLookingIntegerAndStringCandidatesRetainBothBindings(): void
    {
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')
            ->once()
            ->with('users', 'id', ['1', 1], null, null, null, [])
            ->andReturn(['1']);

        $verifier = BatchDatabaseChecker::buildVerifier(
            $this->batchGroups($this->metadata(), ['1', 1]),
            $presenceVerifier,
        );

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame(1, $verifier->getCount('users', 'id', '1'));
        $this->assertSame(1, $verifier->getCount('users', 'id', 1));
        $this->assertSame(1, $verifier->getMultiCount('users', 'id', ['1', 1]));
    }

    public function testChunksLargeValueSetsAndMarksCrossChunkMultiCountsUncertain(): void
    {
        $chunkSizes = [];
        $values = range(1, 1001);
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')
            ->twice()
            ->andReturnUsing(function (string $collection, string $column, array $chunk) use (&$chunkSizes): array {
                $chunkSizes[] = count($chunk);

                return array_map(strval(...), $chunk);
            });
        $presenceVerifier->shouldReceive('getMultiCount')->once()->with('users', 'id', $values, [])->andReturn(1001);

        $verifier = BatchDatabaseChecker::buildVerifier($this->batchGroups($this->metadata(), $values), $presenceVerifier);

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame([1000, 1], $chunkSizes);
        $this->assertSame(1001, $verifier->getMultiCount('users', 'id', $values));
    }

    public function testEmptyFirstStageProvesEverySubmittedValueAbsent(): void
    {
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')->once()->andReturn([]);

        $verifier = BatchDatabaseChecker::buildVerifier(
            $this->batchGroups($this->metadata(), ['first', 'second']),
            $presenceVerifier,
        );

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame(0, $verifier->getCount('users', 'id', 'first'));
        $this->assertSame(0, $verifier->getCount('users', 'id', 'second'));
    }

    public function testSoleNonExactDatabaseMatchIsKnownPresentWithoutASecondQuery(): void
    {
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')->once()->with('users', 'id', ['Case'], null, null, null, [])->andReturn(['case']);

        $verifier = BatchDatabaseChecker::buildVerifier(
            $this->batchGroups($this->metadata(), ['Case']),
            $presenceVerifier,
        );

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame(1, $verifier->getCount('users', 'id', 'Case'));
        $this->assertSame(1, $verifier->getMultiCount('users', 'id', ['Case']));
    }

    public function testMultipleNonExactMatchesDelegateWithoutRepeatingTheGroupedQuery(): void
    {
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')
            ->once()
            ->with('users', 'id', ['Case', 'Other'], null, null, null, [])
            ->andReturn(['case', 'other']);
        $presenceVerifier->shouldReceive('getCount')->once()->with('users', 'id', 'Case', null, null, [])->andReturn(1);
        $presenceVerifier->shouldReceive('getCount')->once()->with('users', 'id', 'Other', null, null, [])->andReturn(1);

        $verifier = BatchDatabaseChecker::buildVerifier(
            $this->batchGroups($this->metadata(), ['Case', 'Other']),
            $presenceVerifier,
        );

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame(1, $verifier->getCount('users', 'id', 'Case'));
        $this->assertSame(1, $verifier->getCount('users', 'id', 'Other'));
    }

    public function testSecondStageProvesMissesAbsentAfterAnExactHit(): void
    {
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')->once()->with('users', 'id', ['exact', 'missing'], null, null, null, [])->andReturn(['exact']);
        $presenceVerifier->shouldReceive('getExistingValues')->once()->with('users', 'id', ['missing'], null, null, null, [])->andReturn([]);

        $verifier = BatchDatabaseChecker::buildVerifier(
            $this->batchGroups($this->metadata(), ['exact', 'missing']),
            $presenceVerifier,
        );

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame(1, $verifier->getCount('users', 'id', 'exact'));
        $this->assertSame(0, $verifier->getCount('users', 'id', 'missing'));
    }

    public function testSecondStageExactMatchesBecomeScalarKnownFacts(): void
    {
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')->once()->with('users', 'id', ['exact', 'Case', 'unknown'], null, null, null, [])->andReturn(['exact', 'case']);
        $presenceVerifier->shouldReceive('getExistingValues')->once()->with('users', 'id', ['Case', 'unknown'], null, null, null, [])->andReturn(['Case']);
        $presenceVerifier->shouldReceive('getCount')->once()->with('users', 'id', 'unknown', null, null, [])->andReturn(0);

        $verifier = BatchDatabaseChecker::buildVerifier(
            $this->batchGroups($this->metadata(), ['exact', 'Case', 'unknown']),
            $presenceVerifier,
        );

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame(1, $verifier->getCount('users', 'id', 'Case'));
        $this->assertSame(0, $verifier->getCount('users', 'id', 'unknown'));
    }

    public function testUnsupportedCandidateDoesNotDisableSafeSiblings(): void
    {
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')->once()->with('users', 'id', ['safe'], null, null, null, [])->andReturn(['safe']);

        $verifier = BatchDatabaseChecker::buildVerifier(
            $this->batchGroups($this->metadata(), [[1, new stdClass], false, 'safe']),
            $presenceVerifier,
        );

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame(1, $verifier->getCount('users', 'id', 'safe'));
    }

    public function testAllUnsupportedOrEmptyCandidatesCreateNoLookup(): void
    {
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldNotReceive('getExistingValues');

        $this->assertNull(BatchDatabaseChecker::buildVerifier(
            $this->batchGroups($this->metadata(), [[1, new stdClass], false, true, []]),
            $presenceVerifier,
        ));
        $this->assertNull(BatchDatabaseChecker::buildVerifier(
            $this->batchGroups($this->metadata(), []),
            $presenceVerifier,
        ));
    }

    /**
     * Build batch groups keyed by the complete query shape.
     */
    private function batchGroups(array $meta, array $values): array
    {
        $lookupKey = PrecomputedPresenceVerifier::lookupKey(
            $meta['connection'],
            $meta['table'],
            $meta['column'],
            $meta['ignore'],
            $meta['idColumn'],
            $meta['wheres'],
        ) ?? throw new RuntimeException('Expected batchable metadata.');

        return [$lookupKey => ['meta' => $meta, 'values' => $values]];
    }

    /**
     * Build batch metadata.
     *
     * @return array{connection: ?string, table: string, column: string, wheres: array<string, mixed>, ignore: null|int|string, idColumn: ?string}
     */
    private function metadata(array $overrides = []): array
    {
        return array_replace([
            'connection' => null,
            'table' => 'users',
            'column' => 'id',
            'wheres' => [],
            'ignore' => null,
            'idColumn' => null,
        ], $overrides);
    }
}
