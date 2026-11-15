<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use DateTimeImmutable;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\DatabaseCondition;
use Hypervel\Validation\DatabaseConditionOperator;
use Hypervel\Validation\DatabaseConditions;
use Hypervel\Validation\PrecomputedPresenceVerifier;
use Hypervel\Validation\PresenceQuery;
use Hypervel\Validation\PresenceVerifierInterface;
use Mockery as m;
use RuntimeException;
use stdClass;
use Stringable;

class ValidationPrecomputedPresenceVerifierTest extends TestCase
{
    public function testLookupKeyModelsTheCompleteEffectiveQueryShape(): void
    {
        $status = new DatabaseCondition('status', DatabaseConditionOperator::Equals, 'active');
        $deleted = new DatabaseCondition('deleted_at', DatabaseConditionOperator::IsNull);
        $conditions = new DatabaseConditions($status, $deleted);
        $base = self::lookupKey(new PresenceQuery(null, 'users', 'email', $conditions));

        $this->assertSame($base, self::lookupKey(new PresenceQuery(
            null,
            'users',
            'email',
            DatabaseConditions::fromParameters(['status', 'active', 'deleted_at', null]),
        )));
        $this->assertSame($base, self::lookupKey(new PresenceQuery(null, 'users', 'email', $conditions, idColumn: 'uuid')));
        $this->assertNotSame($base, self::lookupKey(new PresenceQuery('tenant', 'users', 'email', $conditions)));
        $this->assertNotSame($base, self::lookupKey(new PresenceQuery(null, 'admins', 'email', $conditions)));
        $this->assertNotSame($base, self::lookupKey(new PresenceQuery(null, 'users', 'username', $conditions)));
        $this->assertNotSame($base, self::lookupKey(new PresenceQuery(null, 'users', 'email', new DatabaseConditions($deleted, $status))));
        $this->assertNotSame($base, self::lookupKey(new PresenceQuery(null, 'users', 'email', new DatabaseConditions(
            new DatabaseCondition('status', DatabaseConditionOperator::Equals, 'inactive'),
            $deleted,
        ))));
    }

    public function testLookupKeyUsesEffectiveExclusionAndNormalizedConditions(): void
    {
        $booleans = new DatabaseConditions(
            new DatabaseCondition('active', DatabaseConditionOperator::Equals, true),
            new DatabaseCondition('archived', DatabaseConditionOperator::Equals, false),
        );
        $strings = new DatabaseConditions(
            new DatabaseCondition('active', DatabaseConditionOperator::Equals, '1'),
            new DatabaseCondition('archived', DatabaseConditionOperator::Equals, '0'),
        );
        $defaultId = self::lookupKey(new PresenceQuery(null, 'users', 'email', $booleans, '7'));

        $this->assertSame($defaultId, self::lookupKey(new PresenceQuery(null, 'users', 'email', $strings, '7', 'id')));
        $this->assertNotSame($defaultId, self::lookupKey(new PresenceQuery(null, 'users', 'email', $strings, '8', 'id')));
        $this->assertNotSame($defaultId, self::lookupKey(new PresenceQuery(null, 'users', 'email', $strings, '7', 'uuid')));
    }

    public function testLookupKeyRejectsConditionsThatCannotBeReplayed(): void
    {
        $casts = 0;
        $stringable = new class($casts) implements Stringable {
            public function __construct(private int &$casts)
            {
            }

            public function __toString(): string
            {
                ++$this->casts;

                return 'active';
            }
        };

        foreach ([
            new DatabaseConditions(static function (): void {}),
            new DatabaseConditions(new DatabaseCondition('status', DatabaseConditionOperator::Equals, new stdClass)),
            new DatabaseConditions(new DatabaseCondition('status', DatabaseConditionOperator::In, [$stringable])),
        ] as $conditions) {
            $this->assertNull(PrecomputedPresenceVerifier::lookupKey(
                new PresenceQuery(null, 'users', 'email', $conditions),
            ));
        }
        $this->assertSame(0, $casts);
    }

    public function testScalarFactsUseTheirDatabaseProvenState(): void
    {
        $verifier = $this->makeVerifierWithUnusedFallback();
        $presenceQuery = new PresenceQuery(null, 'users', 'email', new DatabaseConditions);

        $lookupKey = self::lookupKey($presenceQuery);
        $verifier->addLookup(
            $lookupKey,
            exactHits: self::bindingMap(['exact@example.com']),
            knownPresent: self::bindingMap(['case@example.com']),
            provenAbsent: self::bindingMap(['missing@example.com']),
            stageOneSingleChunk: true,
        );

        $this->assertSame(1, $verifier->getCount($presenceQuery, 'exact@example.com'));
        $this->assertSame(1, $verifier->getCount($presenceQuery, 'case@example.com'));
        $this->assertSame(0, $verifier->getCount($presenceQuery, 'missing@example.com'));
    }

    public function testFactsShareEquivalentBindingsWithoutCastingObjects(): void
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
        $fallback = m::mock(PresenceVerifierInterface::class);
        $presenceQuery = new PresenceQuery(null, 'users', 'id', new DatabaseConditions);

        $fallback->shouldReceive('getCount')
            ->once()
            ->with($presenceQuery, m::on(static fn (mixed $value): bool => $value === $stringable))
            ->andReturn(1);
        $verifier = new PrecomputedPresenceVerifier($fallback);
        $verifier->addLookup(
            self::lookupKey($presenceQuery),
            exactHits: self::bindingMap(['1', 2, '3']),
            knownPresent: [],
            provenAbsent: [],
            stageOneSingleChunk: true,
        );

        $this->assertSame(1, $verifier->getCount($presenceQuery, '1'));
        $this->assertSame(1, $verifier->getCount($presenceQuery, 2));
        $this->assertSame(1, $verifier->getCount($presenceQuery, $stringable));
        $this->assertSame(0, $casts);
        $this->assertSame(1, $verifier->getCount($presenceQuery, 1));
        $this->assertSame(1, $verifier->getCount($presenceQuery, '2'));
    }

    public function testUnknownScalarFallbackCountsAreMemoizedPerQueryShapeAndValue(): void
    {
        $fallback = m::mock(PresenceVerifierInterface::class);
        $activeQuery = new PresenceQuery(null, 'users', 'email', new DatabaseConditions(new DatabaseCondition('status', DatabaseConditionOperator::Equals, 'active')));

        $fallback->shouldReceive('getCount')
            ->with($activeQuery, 'shared')
            ->once()
            ->andReturn(1);
        $inactiveQuery = new PresenceQuery(null, 'users', 'email', new DatabaseConditions(new DatabaseCondition('status', DatabaseConditionOperator::Equals, 'inactive')));

        $fallback->shouldReceive('getCount')
            ->with($inactiveQuery, 'shared')
            ->once()
            ->andReturn(0);
        $adminQuery = new PresenceQuery(null, 'admins', 'email', new DatabaseConditions);

        $fallback->shouldReceive('getCount')
            ->with($adminQuery, 'shared')
            ->once()
            ->andReturn(2);
        $unfilteredQuery = new PresenceQuery(null, 'users', 'email', new DatabaseConditions);

        $fallback->shouldReceive('getCount')
            ->with($unfilteredQuery, m::on(static fn (mixed $value): bool => $value === '1'))
            ->once()
            ->andReturn(1);

        $verifier = new PrecomputedPresenceVerifier($fallback);
        $verifier->addLookup(self::lookupKey($activeQuery), [], [], [], true);
        $verifier->addLookup(self::lookupKey($inactiveQuery), [], [], [], true);
        $verifier->addLookup(self::lookupKey($adminQuery), [], [], [], true);
        $verifier->addLookup(self::lookupKey($unfilteredQuery), [], [], [], true);

        for ($iteration = 0; $iteration < 2; ++$iteration) {
            $this->assertSame(1, $verifier->getCount($activeQuery, 'shared'));
            $this->assertSame(0, $verifier->getCount($inactiveQuery, 'shared'));
            $this->assertSame(2, $verifier->getCount($adminQuery, 'shared'));
            $this->assertSame(1, $verifier->getCount($unfilteredQuery, '1'));
            $this->assertSame(1, $verifier->getCount($unfilteredQuery, 1));
        }
    }

    public function testUnregisteredAndUnsupportedScalarShapesDelegateWithoutMemoization(): void
    {
        $unsupported = false;
        $fallback = m::mock(PresenceVerifierInterface::class);
        $unregisteredQuery = new PresenceQuery(null, 'users', 'email', new DatabaseConditions(new DatabaseCondition('status', DatabaseConditionOperator::Equals, 'active')));

        $fallback->shouldReceive('getCount')
            ->with($unregisteredQuery, 'unregistered')
            ->twice()
            ->andReturn(1);
        $registeredQuery = new PresenceQuery(null, 'users', 'email', new DatabaseConditions);

        $fallback->shouldReceive('getCount')
            ->with($registeredQuery, $unsupported)
            ->twice()
            ->andReturn(0);

        $verifier = new PrecomputedPresenceVerifier($fallback);
        $verifier->addLookup(self::lookupKey($registeredQuery), [], [], [], true);

        for ($iteration = 0; $iteration < 2; ++$iteration) {
            $this->assertSame(1, $verifier->getCount($unregisteredQuery, 'unregistered'));
            $this->assertSame(0, $verifier->getCount($registeredQuery, $unsupported));
        }
    }

    public function testConnectionIsPartOfTheLookupAndForwardedToTheFallback(): void
    {
        $fallback = m::mock(PresenceVerifierInterface::class);
        $presenceQuery = new PresenceQuery('tenant', 'users', 'email', new DatabaseConditions);

        $fallback->shouldReceive('getCount')->once()->with($presenceQuery, 'unknown')->andReturn(1);

        $verifier = new PrecomputedPresenceVerifier($fallback);
        $verifier->addLookup(
            self::lookupKey($presenceQuery),
            exactHits: self::bindingMap(['known']),
            knownPresent: [],
            provenAbsent: [],
            stageOneSingleChunk: true,
        );

        $this->assertSame(1, $verifier->getCount($presenceQuery, 'known'));
        $this->assertSame(1, $verifier->getCount($presenceQuery, 'unknown'));
    }

    public function testMultiCountUsesExactAndAbsentFactsFromOneDistinctQuery(): void
    {
        $verifier = $this->makeVerifierWithUnusedFallback();
        $presenceQuery = new PresenceQuery(null, 'users', 'email', new DatabaseConditions);

        $verifier->addLookup(
            self::lookupKey($presenceQuery),
            exactHits: self::bindingMap(['first', 'second']),
            knownPresent: [],
            provenAbsent: self::bindingMap(['missing']),
            stageOneSingleChunk: true,
        );

        $this->assertSame(2, $verifier->getMultiCount($presenceQuery, ['first', 'first', 'second', 'missing']));
    }

    public function testMultiCountUsesKnownPresentOnlyForASoleDistinctInput(): void
    {
        $fallback = m::mock(PresenceVerifierInterface::class);
        $presenceQuery = new PresenceQuery(null, 'users', 'email', new DatabaseConditions);

        $fallback->shouldReceive('getMultiCount')->once()->with($presenceQuery, ['case', 'exact'])->andReturn(1);
        $verifier = new PrecomputedPresenceVerifier($fallback);
        $verifier->addLookup(
            self::lookupKey($presenceQuery),
            exactHits: self::bindingMap(['exact']),
            knownPresent: self::bindingMap(['case']),
            provenAbsent: [],
            stageOneSingleChunk: true,
        );

        $this->assertSame(1, $verifier->getMultiCount($presenceQuery, ['case']));
        $this->assertSame(1, $verifier->getMultiCount($presenceQuery, ['case', 'exact']));
    }

    public function testMultiCountDelegatesUnknownUnsupportedAndCrossChunkFactsAsAWhole(): void
    {
        $casts = 0;
        $stringable = new class($casts) implements Stringable {
            public function __construct(private int &$casts)
            {
            }

            public function __toString(): string
            {
                ++$this->casts;

                return 'exact';
            }
        };
        $fallback = m::mock(PresenceVerifierInterface::class);
        $presenceQuery = new PresenceQuery(null, 'users', 'email', new DatabaseConditions);

        $fallback->shouldReceive('getMultiCount')->once()->with($presenceQuery, ['unknown'])->andReturn(1);
        $fallback->shouldReceive('getMultiCount')->once()->with($presenceQuery, [false])->andReturn(0);
        $fallback->shouldReceive('getMultiCount')
            ->once()
            ->with($presenceQuery, m::on(static fn (array $values): bool => $values === [$stringable]))
            ->andReturn(1);
        $fallback->shouldReceive('getMultiCount')->once()->with($presenceQuery, ['exact'])->andReturn(1);
        $verifier = new PrecomputedPresenceVerifier($fallback);
        $verifier->addLookup(
            self::lookupKey($presenceQuery),
            exactHits: self::bindingMap(['exact']),
            knownPresent: [],
            provenAbsent: [],
            stageOneSingleChunk: false,
        );

        $this->assertSame(1, $verifier->getMultiCount($presenceQuery, ['unknown']));
        $this->assertSame(0, $verifier->getMultiCount($presenceQuery, [false]));
        $this->assertSame(1, $verifier->getMultiCount($presenceQuery, [$stringable]));
        $this->assertSame(0, $casts);
        $this->assertSame(1, $verifier->getMultiCount($presenceQuery, ['exact']));
    }

    public function testDateTimeBindingsDelegateToTheOrdinaryVerifier(): void
    {
        $value = new class('2025-01-01 00:00:00') extends DateTimeImmutable implements Stringable {
            public function __toString(): string
            {
                return $this->format(DATE_ATOM);
            }
        };
        $fallback = m::mock(PresenceVerifierInterface::class);
        $presenceQuery = new PresenceQuery(null, 'users', 'created_at', new DatabaseConditions);

        $fallback->shouldReceive('getCount')
            ->once()
            ->with($presenceQuery, $value)
            ->andReturn(1);
        $verifier = new PrecomputedPresenceVerifier($fallback);
        $verifier->addLookup(self::lookupKey($presenceQuery), [], [], [], true);

        $this->assertNull(PrecomputedPresenceVerifier::bindingKey($value));
        $this->assertSame(1, $verifier->getCount($presenceQuery, $value));
    }

    public function testHasLookupsReflectsRegisteredQueryShapes(): void
    {
        $verifier = $this->makeVerifierWithUnusedFallback();

        $this->assertFalse($verifier->hasLookups());

        $verifier->addLookup(self::lookupKey(new PresenceQuery(null, 'users', 'email', new DatabaseConditions)), [], [], [], true);

        $this->assertTrue($verifier->hasLookups());
    }

    /**
     * Build a non-null lookup key for scalar query conditions.
     */
    private static function lookupKey(PresenceQuery $query): string
    {
        return PrecomputedPresenceVerifier::lookupKey($query)
            ?? throw new RuntimeException('Expected a scalar lookup shape.');
    }

    /**
     * Build a fact map from submitted binding identities.
     *
     * @return array<string, true>
     */
    private static function bindingMap(array $values): array
    {
        $bindings = [];

        foreach ($values as $value) {
            $bindingKey = PrecomputedPresenceVerifier::bindingKey($value)
                ?? throw new RuntimeException('Expected a supported presence value.');
            $bindings[$bindingKey] = true;
        }

        return $bindings;
    }

    /**
     * Build a verifier whose registered facts must answer every probe.
     */
    private function makeVerifierWithUnusedFallback(): PrecomputedPresenceVerifier
    {
        $fallback = m::mock(PresenceVerifierInterface::class);
        $fallback->shouldNotReceive('getCount');
        $fallback->shouldNotReceive('getMultiCount');

        return new PrecomputedPresenceVerifier($fallback);
    }
}
