<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\TestCase;
use Hypervel\Validation\BatchDatabaseChecker;
use Hypervel\Validation\DatabaseCondition;
use Hypervel\Validation\DatabaseConditionOperator;
use Hypervel\Validation\DatabaseConditions;
use Hypervel\Validation\DatabasePresenceVerifier;
use Hypervel\Validation\PrecomputedPresenceVerifier;
use Hypervel\Validation\PresenceQuery;
use Mockery as m;
use RuntimeException;
use stdClass;
use Stringable;

class ValidationBatchDatabaseCheckerTest extends TestCase
{
    public function testUsesTheCompleteQueryShapeAndRetainsCandidateValues(): void
    {
        $query = new PresenceQuery(
            'named',
            'users',
            'id',
            new DatabaseConditions(new DatabaseCondition('status', DatabaseConditionOperator::Equals, 'active')),
            'ignored',
            'uuid',
        );
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')
            ->once()
            ->with($query, [1, 2.5])
            ->andReturn(['1', '2.5']);
        $presenceVerifier->shouldNotReceive('getCount');

        $verifier = BatchDatabaseChecker::buildVerifier($this->batchGroups($query, [1, 2.5]), $presenceVerifier);

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame(1, $verifier->getCount($query, 1));
        $this->assertSame(1, $verifier->getCount($query, 2.5));
        $this->assertSame(1, $verifier->getCount($query, '1'));
    }

    public function testNormalizesNativeArraysAndDelegatesStringableValuesWithoutCasting(): void
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
        $query = new PresenceQuery(null, 'users', 'id', new DatabaseConditions);
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')
            ->once()
            ->with($query, [1, 2])
            ->andReturn(['1', '2']);
        $presenceVerifier->shouldReceive('getCount')
            ->once()
            ->with($query, m::on(static fn (mixed $value): bool => $value === $stringable))
            ->andReturn(1);

        $verifier = BatchDatabaseChecker::buildVerifier($this->batchGroups($query, [[1, 2], 2, $stringable]), $presenceVerifier);

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame(2, $verifier->getMultiCount($query, [1, 2]));
        $this->assertSame(1, $verifier->getCount($query, $stringable));
        $this->assertSame(0, $casts);
    }

    public function testArrayContainingStringableDelegatesAsAWholeWithoutDisablingSafeSiblings(): void
    {
        $query = new PresenceQuery(null, 'users', 'id', new DatabaseConditions);
        $casts = 0;
        $stringable = new class($casts) implements Stringable {
            public function __construct(private int &$casts)
            {
            }

            public function __toString(): string
            {
                ++$this->casts;

                return 'unsafe';
            }
        };
        $candidate = ['safe-in-array', $stringable];
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')
            ->once()
            ->with($query, ['safe-sibling'])
            ->andReturn(['safe-sibling']);
        $presenceVerifier->shouldReceive('getMultiCount')
            ->once()
            ->with($query, m::on(static fn (array $values): bool => $values === $candidate))
            ->andReturn(1);

        $verifier = BatchDatabaseChecker::buildVerifier(
            $this->batchGroups($query, [$candidate, 'safe-sibling']),
            $presenceVerifier,
        );

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame(1, $verifier->getCount($query, 'safe-sibling'));
        $this->assertSame(1, $verifier->getMultiCount($query, $candidate));
        $this->assertSame(0, $casts);
    }

    public function testEqualLookingIntegerAndStringCandidatesShareOneBinding(): void
    {
        $query = new PresenceQuery(null, 'users', 'id', new DatabaseConditions);
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')
            ->once()
            ->with($query, ['1'])
            ->andReturn(['1']);

        $verifier = BatchDatabaseChecker::buildVerifier(
            $this->batchGroups($query, ['1', 1]),
            $presenceVerifier,
        );

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame(1, $verifier->getCount($query, '1'));
        $this->assertSame(1, $verifier->getCount($query, 1));
        $this->assertSame(1, $verifier->getMultiCount($query, ['1', 1]));
    }

    public function testChunksLargeValueSetsAndMarksCrossChunkMultiCountsUncertain(): void
    {
        $query = new PresenceQuery(null, 'users', 'id', new DatabaseConditions);
        $chunkSizes = [];
        $values = range(1, 1001);
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')
            ->twice()
            ->andReturnUsing(function (PresenceQuery $query, array $chunk) use (&$chunkSizes): array {
                $chunkSizes[] = count($chunk);

                return array_map(strval(...), $chunk);
            });
        $presenceVerifier->shouldReceive('getMultiCount')->once()->with($query, $values)->andReturn(1001);

        $verifier = BatchDatabaseChecker::buildVerifier($this->batchGroups($query, $values), $presenceVerifier);

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame([1000, 1], $chunkSizes);
        $this->assertSame(1001, $verifier->getMultiCount($query, $values));
    }

    public function testEmptyFirstStageProvesEverySubmittedValueAbsent(): void
    {
        $query = new PresenceQuery(null, 'users', 'id', new DatabaseConditions);
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')->once()->andReturn([]);

        $verifier = BatchDatabaseChecker::buildVerifier(
            $this->batchGroups($query, ['first', 'second']),
            $presenceVerifier,
        );

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame(0, $verifier->getCount($query, 'first'));
        $this->assertSame(0, $verifier->getCount($query, 'second'));
    }

    public function testSoleNonExactDatabaseMatchIsKnownPresentWithoutASecondQuery(): void
    {
        $query = new PresenceQuery(null, 'users', 'id', new DatabaseConditions);
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')->once()->with($query, ['Case'])->andReturn(['case']);

        $verifier = BatchDatabaseChecker::buildVerifier(
            $this->batchGroups($query, ['Case']),
            $presenceVerifier,
        );

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame(1, $verifier->getCount($query, 'Case'));
        $this->assertSame(1, $verifier->getMultiCount($query, ['Case']));
    }

    public function testMultipleNonExactMatchesDelegateWithoutRepeatingTheGroupedQuery(): void
    {
        $query = new PresenceQuery(null, 'users', 'id', new DatabaseConditions);
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')
            ->once()
            ->with($query, ['Case', 'Other'])
            ->andReturn(['case', 'other']);
        $presenceVerifier->shouldReceive('getCount')->once()->with($query, 'Case')->andReturn(1);
        $presenceVerifier->shouldReceive('getCount')->once()->with($query, 'Other')->andReturn(1);

        $verifier = BatchDatabaseChecker::buildVerifier(
            $this->batchGroups($query, ['Case', 'Other']),
            $presenceVerifier,
        );

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame(1, $verifier->getCount($query, 'Case'));
        $this->assertSame(1, $verifier->getCount($query, 'Other'));
    }

    public function testSecondStageProvesMissesAbsentAfterAnExactHit(): void
    {
        $query = new PresenceQuery(null, 'users', 'id', new DatabaseConditions);
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')->once()->with($query, ['exact', 'missing'])->andReturn(['exact']);
        $presenceVerifier->shouldReceive('getExistingValues')->once()->with($query, ['missing'])->andReturn([]);

        $verifier = BatchDatabaseChecker::buildVerifier(
            $this->batchGroups($query, ['exact', 'missing']),
            $presenceVerifier,
        );

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame(1, $verifier->getCount($query, 'exact'));
        $this->assertSame(0, $verifier->getCount($query, 'missing'));
    }

    public function testSecondStageExactMatchesBecomeScalarKnownFacts(): void
    {
        $query = new PresenceQuery(null, 'users', 'id', new DatabaseConditions);
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')->once()->with($query, ['exact', 'Case', 'unknown'])->andReturn(['exact', 'case']);
        $presenceVerifier->shouldReceive('getExistingValues')->once()->with($query, ['Case', 'unknown'])->andReturn(['Case']);
        $presenceVerifier->shouldReceive('getCount')->once()->with($query, 'unknown')->andReturn(0);

        $verifier = BatchDatabaseChecker::buildVerifier(
            $this->batchGroups($query, ['exact', 'Case', 'unknown']),
            $presenceVerifier,
        );

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame(1, $verifier->getCount($query, 'Case'));
        $this->assertSame(0, $verifier->getCount($query, 'unknown'));
    }

    public function testUnsupportedCandidateDoesNotDisableSafeSiblings(): void
    {
        $query = new PresenceQuery(null, 'users', 'id', new DatabaseConditions);
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldReceive('getExistingValues')->once()->with($query, ['safe'])->andReturn(['safe']);

        $verifier = BatchDatabaseChecker::buildVerifier(
            $this->batchGroups($query, [[1, new stdClass], false, 'safe']),
            $presenceVerifier,
        );

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame(1, $verifier->getCount($query, 'safe'));
    }

    public function testAllUnsupportedOrEmptyCandidatesCreateNoLookup(): void
    {
        $query = new PresenceQuery(null, 'users', 'id', new DatabaseConditions);
        $presenceVerifier = m::mock(DatabasePresenceVerifier::class);
        $presenceVerifier->shouldNotReceive('getExistingValues');

        $this->assertNull(BatchDatabaseChecker::buildVerifier(
            $this->batchGroups($query, [[1, new stdClass], false, true, []]),
            $presenceVerifier,
        ));
        $this->assertNull(BatchDatabaseChecker::buildVerifier(
            $this->batchGroups($query, []),
            $presenceVerifier,
        ));
    }

    /**
     * Build batch groups keyed by the complete query shape.
     */
    private function batchGroups(PresenceQuery $query, array $values): array
    {
        $lookupKey = PrecomputedPresenceVerifier::lookupKey($query)
            ?? throw new RuntimeException('Expected batchable conditions.');

        return [$lookupKey => ['query' => $query, 'values' => $values]];
    }
}
