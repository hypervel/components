<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\TestCase;
use Hypervel\Validation\PrecomputedPresenceVerifier;
use Hypervel\Validation\PresenceVerifierInterface;
use Mockery as m;

class ValidationPrecomputedPresenceVerifierTest extends TestCase
{
    public function testGetCountReturnsOneForExistingValue()
    {
        $verifier = new PrecomputedPresenceVerifier;
        $verifier->addLookup('users', 'email', ['foo@bar.com', 'baz@bar.com']);

        $this->assertSame(1, $verifier->getCount('users', 'email', 'foo@bar.com'));
    }

    public function testGetCountReturnsZeroForMissingValue()
    {
        $verifier = new PrecomputedPresenceVerifier;
        $verifier->addLookup('users', 'email', ['foo@bar.com']);

        $this->assertSame(0, $verifier->getCount('users', 'email', 'missing@bar.com'));
    }

    public function testGetCountCastsValueToStringForComparison()
    {
        $verifier = new PrecomputedPresenceVerifier;
        $verifier->addLookup('users', 'id', [1, 2, 3]);

        $this->assertSame(1, $verifier->getCount('users', 'id', '2'));
        $this->assertSame(0, $verifier->getCount('users', 'id', '99'));
    }

    public function testGetMultiCountCountsMatches()
    {
        $verifier = new PrecomputedPresenceVerifier;
        $verifier->addLookup('users', 'email', ['a@b.com', 'c@d.com', 'e@f.com']);

        $this->assertSame(2, $verifier->getMultiCount('users', 'email', ['a@b.com', 'c@d.com', 'missing@x.com']));
    }

    public function testFallbackUsedWhenNoLookupRegistered()
    {
        $fallback = m::mock(PresenceVerifierInterface::class);
        $fallback->shouldReceive('getCount')
            ->with('users', 'email', 'foo@bar.com', null, null, [])
            ->once()
            ->andReturn(1);

        $verifier = new PrecomputedPresenceVerifier($fallback);

        $this->assertSame(1, $verifier->getCount('users', 'email', 'foo@bar.com'));
    }

    public function testFallbackUsedForGetMultiCountWhenNoLookup()
    {
        $fallback = m::mock(PresenceVerifierInterface::class);
        $fallback->shouldReceive('getMultiCount')
            ->with('users', 'email', ['a@b.com'], [])
            ->once()
            ->andReturn(1);

        $verifier = new PrecomputedPresenceVerifier($fallback);

        $this->assertSame(1, $verifier->getMultiCount('users', 'email', ['a@b.com']));
    }

    public function testNoFallbackReturnsZero()
    {
        $verifier = new PrecomputedPresenceVerifier;

        $this->assertSame(0, $verifier->getCount('users', 'email', 'foo@bar.com'));
        $this->assertSame(0, $verifier->getMultiCount('users', 'email', ['foo@bar.com']));
    }

    public function testHasLookupsReturnsTrueWhenLookupsRegistered()
    {
        $verifier = new PrecomputedPresenceVerifier;

        $this->assertFalse($verifier->hasLookups());

        $verifier->addLookup('users', 'email', ['foo@bar.com']);

        $this->assertTrue($verifier->hasLookups());
    }

    public function testNullValuesAreExcludedFromLookup()
    {
        $verifier = new PrecomputedPresenceVerifier;
        $verifier->addLookup('users', 'email', [null, 'foo@bar.com', null]);

        $this->assertSame(1, $verifier->getCount('users', 'email', 'foo@bar.com'));
        $this->assertSame(0, $verifier->getCount('users', 'email', ''));
    }

    public function testNonScalarValueReturnsZero()
    {
        $verifier = new PrecomputedPresenceVerifier;
        $verifier->addLookup('users', 'email', ['foo@bar.com']);

        $this->assertSame(0, $verifier->getCount('users', 'email', ['array']));
    }

    public function testSeparateTableColumnScoping()
    {
        $verifier = new PrecomputedPresenceVerifier;
        $verifier->addLookup('users', 'email', ['user@a.com']);
        $verifier->addLookup('admins', 'email', ['admin@a.com']);

        $this->assertSame(1, $verifier->getCount('users', 'email', 'user@a.com'));
        $this->assertSame(0, $verifier->getCount('users', 'email', 'admin@a.com'));
        $this->assertSame(1, $verifier->getCount('admins', 'email', 'admin@a.com'));
        $this->assertSame(0, $verifier->getCount('admins', 'email', 'user@a.com'));
    }
}
