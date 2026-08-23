<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use DateTimeImmutable;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\DatabasePresenceVerifierInterface;
use Hypervel\Validation\PrecomputedPresenceVerifier;
use Mockery as m;
use RuntimeException;
use stdClass;
use Stringable;

class ValidationPrecomputedPresenceVerifierTest extends TestCase
{
    public function testLookupKeyModelsTheCompleteEffectiveQueryShape(): void
    {
        $base = self::lookupKey(null, 'users', 'email', extra: ['status' => 'active', 'deleted_at' => 'NULL']);

        $this->assertSame($base, self::lookupKey(null, 'users', 'email', 'NULL', 'uuid', ['status' => 'active', 'deleted_at' => 'NULL']));
        $this->assertNotSame($base, self::lookupKey('tenant', 'users', 'email', extra: ['status' => 'active', 'deleted_at' => 'NULL']));
        $this->assertNotSame($base, self::lookupKey(null, 'admins', 'email', extra: ['status' => 'active', 'deleted_at' => 'NULL']));
        $this->assertNotSame($base, self::lookupKey(null, 'users', 'username', extra: ['status' => 'active', 'deleted_at' => 'NULL']));
        $this->assertNotSame($base, self::lookupKey(null, 'users', 'email', extra: ['deleted_at' => 'NULL', 'status' => 'active']));
        $this->assertNotSame($base, self::lookupKey(null, 'users', 'email', extra: ['status' => 'inactive', 'deleted_at' => 'NULL']));
    }

    public function testLookupKeyUsesEffectiveExclusionAndNormalizedConditions(): void
    {
        $defaultId = self::lookupKey(null, 'users', 'email', '7', null, ['active' => true, 'archived' => false]);

        $this->assertSame($defaultId, self::lookupKey(null, 'users', 'email', '7', 'id', ['active' => '1', 'archived' => '']));
        $this->assertNotSame($defaultId, self::lookupKey(null, 'users', 'email', '8', 'id', ['active' => '1', 'archived' => '']));
        $this->assertNotSame($defaultId, self::lookupKey(null, 'users', 'email', '7', 'uuid', ['active' => '1', 'archived' => '']));
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

        $this->assertNull(PrecomputedPresenceVerifier::lookupKey(null, 'users', 'email', extra: [static function (): void {}]));
        $this->assertNull(PrecomputedPresenceVerifier::lookupKey(null, 'users', 'email', extra: ['status' => new stdClass]));
        $this->assertNull(PrecomputedPresenceVerifier::lookupKey(null, 'users', 'email', extra: ['status' => $stringable]));
        $this->assertSame(0, $casts);
    }

    public function testScalarFactsUseTheirDatabaseProvenState(): void
    {
        $verifier = $this->makeVerifierWithUnusedFallback();
        $lookupKey = self::lookupKey(null, 'users', 'email');
        $verifier->addLookup(
            $lookupKey,
            exactHits: self::bindingMap(['exact@example.com']),
            knownPresent: self::bindingMap(['case@example.com']),
            provenAbsent: self::bindingMap(['missing@example.com']),
            stageOneSingleChunk: true,
        );

        $this->assertSame(1, $verifier->getCount('users', 'email', 'exact@example.com'));
        $this->assertSame(1, $verifier->getCount('users', 'email', 'case@example.com'));
        $this->assertSame(0, $verifier->getCount('users', 'email', 'missing@example.com'));
    }

    public function testFactsRequireTheSubmittedBindingIdentity(): void
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
        $fallback = m::mock(DatabasePresenceVerifierInterface::class);
        $fallback->shouldReceive('getCount')
            ->once()
            ->with('users', 'id', m::on(static fn (mixed $value): bool => $value === $stringable), null, null, [])
            ->andReturn(1);
        $fallback->shouldReceive('getCount')
            ->once()
            ->with('users', 'id', 1, null, null, [])
            ->andReturn(0);
        $fallback->shouldReceive('getCount')
            ->once()
            ->with('users', 'id', '2', null, null, [])
            ->andReturn(0);
        $verifier = new PrecomputedPresenceVerifier($fallback);
        $verifier->addLookup(
            self::lookupKey(null, 'users', 'id'),
            exactHits: self::bindingMap(['1', 2, '3']),
            knownPresent: [],
            provenAbsent: [],
            stageOneSingleChunk: true,
        );

        $this->assertSame(1, $verifier->getCount('users', 'id', '1'));
        $this->assertSame(1, $verifier->getCount('users', 'id', 2));
        $this->assertSame(1, $verifier->getCount('users', 'id', $stringable));
        $this->assertSame(0, $casts);
        $this->assertSame(0, $verifier->getCount('users', 'id', 1));
        $this->assertSame(0, $verifier->getCount('users', 'id', '2'));
    }

    public function testUnknownScalarFallbackCountsAreMemoizedPerQueryShapeAndValue(): void
    {
        $fallback = m::mock(DatabasePresenceVerifierInterface::class);
        $fallback->shouldReceive('getCount')
            ->with('users', 'email', 'shared', null, null, ['status' => 'active'])
            ->once()
            ->andReturn(1);
        $fallback->shouldReceive('getCount')
            ->with('users', 'email', 'shared', null, null, ['status' => 'inactive'])
            ->once()
            ->andReturn(0);
        $fallback->shouldReceive('getCount')
            ->with('admins', 'email', 'shared', null, null, [])
            ->once()
            ->andReturn(2);
        $fallback->shouldReceive('getCount')
            ->with('users', 'email', m::on(static fn (mixed $value): bool => $value === '1'), null, null, [])
            ->once()
            ->andReturn(1);
        $fallback->shouldReceive('getCount')
            ->with('users', 'email', m::on(static fn (mixed $value): bool => $value === 1), null, null, [])
            ->once()
            ->andReturn(0);

        $verifier = new PrecomputedPresenceVerifier($fallback);
        $verifier->addLookup(self::lookupKey(null, 'users', 'email', extra: ['status' => 'active']), [], [], [], true);
        $verifier->addLookup(self::lookupKey(null, 'users', 'email', extra: ['status' => 'inactive']), [], [], [], true);
        $verifier->addLookup(self::lookupKey(null, 'admins', 'email'), [], [], [], true);
        $verifier->addLookup(self::lookupKey(null, 'users', 'email'), [], [], [], true);

        for ($iteration = 0; $iteration < 2; ++$iteration) {
            $this->assertSame(1, $verifier->getCount('users', 'email', 'shared', extra: ['status' => 'active']));
            $this->assertSame(0, $verifier->getCount('users', 'email', 'shared', extra: ['status' => 'inactive']));
            $this->assertSame(2, $verifier->getCount('admins', 'email', 'shared'));
            $this->assertSame(1, $verifier->getCount('users', 'email', '1'));
            $this->assertSame(0, $verifier->getCount('users', 'email', 1));
        }
    }

    public function testUnregisteredAndUnsupportedScalarShapesDelegateWithoutMemoization(): void
    {
        $unsupported = false;
        $fallback = m::mock(DatabasePresenceVerifierInterface::class);
        $fallback->shouldReceive('getCount')
            ->with('users', 'email', 'unregistered', null, null, ['status' => 'active'])
            ->twice()
            ->andReturn(1);
        $fallback->shouldReceive('getCount')
            ->with('users', 'email', $unsupported, null, null, [])
            ->twice()
            ->andReturn(0);

        $verifier = new PrecomputedPresenceVerifier($fallback);
        $verifier->addLookup(self::lookupKey(null, 'users', 'email'), [], [], [], true);

        for ($iteration = 0; $iteration < 2; ++$iteration) {
            $this->assertSame(1, $verifier->getCount('users', 'email', 'unregistered', extra: ['status' => 'active']));
            $this->assertSame(0, $verifier->getCount('users', 'email', $unsupported));
        }
    }

    public function testConnectionIsPartOfTheLookupAndForwardedToTheFallback(): void
    {
        $fallback = m::mock(DatabasePresenceVerifierInterface::class);
        $fallback->shouldReceive('setConnection')->once()->with('tenant');
        $fallback->shouldReceive('getCount')->once()->with('users', 'email', 'unknown', null, null, [])->andReturn(1);

        $verifier = new PrecomputedPresenceVerifier($fallback);
        $verifier->addLookup(
            self::lookupKey('tenant', 'users', 'email'),
            exactHits: self::bindingMap(['known']),
            knownPresent: [],
            provenAbsent: [],
            stageOneSingleChunk: true,
        );
        $verifier->setConnection('tenant');

        $this->assertSame(1, $verifier->getCount('users', 'email', 'known'));
        $this->assertSame(1, $verifier->getCount('users', 'email', 'unknown'));
    }

    public function testMultiCountUsesExactAndAbsentFactsFromOneDistinctQuery(): void
    {
        $verifier = $this->makeVerifierWithUnusedFallback();
        $verifier->addLookup(
            self::lookupKey(null, 'users', 'email'),
            exactHits: self::bindingMap(['first', 'second']),
            knownPresent: [],
            provenAbsent: self::bindingMap(['missing']),
            stageOneSingleChunk: true,
        );

        $this->assertSame(2, $verifier->getMultiCount('users', 'email', ['first', 'first', 'second', 'missing']));
    }

    public function testMultiCountUsesKnownPresentOnlyForASoleDistinctInput(): void
    {
        $fallback = m::mock(DatabasePresenceVerifierInterface::class);
        $fallback->shouldReceive('getMultiCount')->once()->with('users', 'email', ['case', 'exact'], [])->andReturn(1);
        $verifier = new PrecomputedPresenceVerifier($fallback);
        $verifier->addLookup(
            self::lookupKey(null, 'users', 'email'),
            exactHits: self::bindingMap(['exact']),
            knownPresent: self::bindingMap(['case']),
            provenAbsent: [],
            stageOneSingleChunk: true,
        );

        $this->assertSame(1, $verifier->getMultiCount('users', 'email', ['case']));
        $this->assertSame(1, $verifier->getMultiCount('users', 'email', ['case', 'exact']));
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
        $fallback = m::mock(DatabasePresenceVerifierInterface::class);
        $fallback->shouldReceive('getMultiCount')->once()->with('users', 'email', ['unknown'], [])->andReturn(1);
        $fallback->shouldReceive('getMultiCount')->once()->with('users', 'email', [false], [])->andReturn(0);
        $fallback->shouldReceive('getMultiCount')
            ->once()
            ->with('users', 'email', m::on(static fn (array $values): bool => $values === [$stringable]), [])
            ->andReturn(1);
        $fallback->shouldReceive('getMultiCount')->once()->with('users', 'email', ['exact'], [])->andReturn(1);
        $verifier = new PrecomputedPresenceVerifier($fallback);
        $verifier->addLookup(
            self::lookupKey(null, 'users', 'email'),
            exactHits: self::bindingMap(['exact']),
            knownPresent: [],
            provenAbsent: [],
            stageOneSingleChunk: false,
        );

        $this->assertSame(1, $verifier->getMultiCount('users', 'email', ['unknown']));
        $this->assertSame(0, $verifier->getMultiCount('users', 'email', [false]));
        $this->assertSame(1, $verifier->getMultiCount('users', 'email', [$stringable]));
        $this->assertSame(0, $casts);
        $this->assertSame(1, $verifier->getMultiCount('users', 'email', ['exact']));
    }

    public function testDateTimeBindingsDelegateToTheOrdinaryVerifier(): void
    {
        $value = new class('2025-01-01 00:00:00') extends DateTimeImmutable implements Stringable {
            public function __toString(): string
            {
                return $this->format(DATE_ATOM);
            }
        };
        $fallback = m::mock(DatabasePresenceVerifierInterface::class);
        $fallback->shouldReceive('getCount')
            ->once()
            ->with('users', 'created_at', $value, null, null, [])
            ->andReturn(1);
        $verifier = new PrecomputedPresenceVerifier($fallback);
        $verifier->addLookup(self::lookupKey(null, 'users', 'created_at'), [], [], [], true);

        $this->assertNull(PrecomputedPresenceVerifier::bindingKey($value));
        $this->assertSame(1, $verifier->getCount('users', 'created_at', $value));
    }

    public function testHasLookupsReflectsRegisteredQueryShapes(): void
    {
        $verifier = $this->makeVerifierWithUnusedFallback();

        $this->assertFalse($verifier->hasLookups());

        $verifier->addLookup(self::lookupKey(null, 'users', 'email'), [], [], [], true);

        $this->assertTrue($verifier->hasLookups());
    }

    /**
     * Build a non-null lookup key for scalar query conditions.
     */
    private static function lookupKey(
        ?string $connection,
        string $collection,
        string $column,
        int|string|null $excludeId = null,
        ?string $idColumn = null,
        array $extra = [],
    ): string {
        return PrecomputedPresenceVerifier::lookupKey(
            $connection,
            $collection,
            $column,
            $excludeId,
            $idColumn,
            $extra,
        ) ?? throw new RuntimeException('Expected a scalar lookup shape.');
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
        $fallback = m::mock(DatabasePresenceVerifierInterface::class);
        $fallback->shouldNotReceive('getCount');
        $fallback->shouldNotReceive('getMultiCount');

        return new PrecomputedPresenceVerifier($fallback);
    }
}
