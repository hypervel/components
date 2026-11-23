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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use RuntimeException;
use stdClass;
use Stringable;

class ValidationBatchDatabaseCheckerTest extends TestCase
{
    use VerifiesDoubles;

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
        $presenceVerifier = Double::for(DatabasePresenceVerifier::class)->strict();
        $presenceVerifier->expects('getExistingValues')
            ->with(Argument::same($query), [1, 2.5])
            ->returns(['1', '2.5']);
        $presenceVerifier->expects('getCount')->never();

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
        $presenceVerifier = Double::for(DatabasePresenceVerifier::class)->strict();
        $presenceVerifier->expects('getExistingValues')
            ->with(Argument::same($query), [1, 2])
            ->returns(['1', '2']);
        $presenceVerifier->expects('getCount')
            ->with(Argument::same($query), Argument::same($stringable))
            ->returns(1);

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
        $presenceVerifier = Double::for(DatabasePresenceVerifier::class)->strict();
        $presenceVerifier->expects('getExistingValues')
            ->with(Argument::same($query), ['safe-sibling'])
            ->returns(['safe-sibling']);
        $presenceVerifier->expects('getMultiCount')
            ->with(Argument::same($query), Argument::same($candidate))
            ->returns(1);

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
        $presenceVerifier = Double::for(DatabasePresenceVerifier::class)->strict();
        $presenceVerifier->expects('getExistingValues')
            ->with(Argument::same($query), ['1'])
            ->returns(['1']);

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
        $presenceVerifier = Double::for(DatabasePresenceVerifier::class)->strict();
        $presenceVerifier->expects('getExistingValues')
            ->times(2)
            ->resolves(function (PresenceQuery $query, array $chunk) use (&$chunkSizes): array {
                $chunkSizes[] = count($chunk);

                return array_map(strval(...), $chunk);
            });
        $presenceVerifier->expects('getMultiCount')->with(Argument::same($query), $values)->returns(1001);

        $verifier = BatchDatabaseChecker::buildVerifier($this->batchGroups($query, $values), $presenceVerifier);

        $this->assertInstanceOf(PrecomputedPresenceVerifier::class, $verifier);
        $this->assertSame([1000, 1], $chunkSizes);
        $this->assertSame(1001, $verifier->getMultiCount($query, $values));
    }

    public function testEmptyFirstStageProvesEverySubmittedValueAbsent(): void
    {
        $query = new PresenceQuery(null, 'users', 'id', new DatabaseConditions);
        $presenceVerifier = Double::for(DatabasePresenceVerifier::class)->strict();
        $presenceVerifier->expects('getExistingValues')->returns([]);

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
        $presenceVerifier = Double::for(DatabasePresenceVerifier::class)->strict();
        $presenceVerifier->expects('getExistingValues')->with(Argument::same($query), ['Case'])->returns(['case']);

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
        $presenceVerifier = Double::for(DatabasePresenceVerifier::class)->strict();
        $presenceVerifier->expects('getExistingValues')
            ->with(Argument::same($query), ['Case', 'Other'])
            ->returns(['case', 'other']);
        $presenceVerifier->expects('getCount')->with(Argument::same($query), 'Case')->returns(1);
        $presenceVerifier->expects('getCount')->with(Argument::same($query), 'Other')->returns(1);

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
        $presenceVerifier = Double::for(DatabasePresenceVerifier::class)->strict();
        $presenceVerifier->expects('getExistingValues')->with(Argument::same($query), ['exact', 'missing'])->returns(['exact']);
        $presenceVerifier->expects('getExistingValues')->with(Argument::same($query), ['missing'])->returns([]);

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
        $presenceVerifier = Double::for(DatabasePresenceVerifier::class)->strict();
        $presenceVerifier->expects('getExistingValues')->with(Argument::same($query), ['exact', 'Case', 'unknown'])->returns(['exact', 'case']);
        $presenceVerifier->expects('getExistingValues')->with(Argument::same($query), ['Case', 'unknown'])->returns(['Case']);
        $presenceVerifier->expects('getCount')->with(Argument::same($query), 'unknown')->returns(0);

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
        $presenceVerifier = Double::for(DatabasePresenceVerifier::class)->strict();
        $presenceVerifier->expects('getExistingValues')->with(Argument::same($query), ['safe'])->returns(['safe']);

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
        $presenceVerifier = Double::for(DatabasePresenceVerifier::class)->strict();
        $presenceVerifier->expects('getExistingValues')->never();

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
