<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pennant\Feature;

use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Pennant\Feature;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Lottery;
use Hypervel\Tests\Pennant\Fixtures\User;
use Hypervel\Tests\Pennant\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class FeatureManagerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function testItCanChainScopeAdditions(): void
    {
        Feature::for('tim@hypervel.com')->for('jess@hypervel.com')->activate('foo');

        $this->assertFalse(Feature::active('foo'));
        $this->assertTrue(Feature::for('tim@hypervel.com')->active('foo'));
        $this->assertTrue(Feature::for('jess@hypervel.com')->active('foo'));
        $this->assertFalse(Feature::for('taylor@hypervel.com')->active('foo'));
        $this->assertTrue(Feature::for('jess@hypervel.com')->for('tim@hypervel.com')->active('foo'));
        $this->assertFalse(Feature::for('tim@hypervel.com')->for('jess@hypervel.com')->for('taylor@hypervel.com')->active('foo'));

        Feature::for('taylor@hypervel.com')->activate('foo');
        $this->assertTrue(Feature::for('tim@hypervel.com')->for('jess@hypervel.com')->for('taylor@hypervel.com')->active('foo'));
    }

    public function testTheAuthenticatedUserIsTheDefaultScope(): void
    {
        $user = new User(['id' => 2]);
        Auth::login($user);

        Feature::activate('foo');

        $this->assertFalse(Feature::for('misc')->active('foo'));
        $this->assertTrue(Feature::active('foo'));
        $this->assertTrue(Feature::for($user)->active('foo'));
    }

    public function testItCanReturnLotteryAsValue(): void
    {
        Lottery::fix([true, true, true, true, false]);

        Feature::define('foo', Lottery::odds(1, 100));

        Feature::load('foo');
        $this->assertTrue(Feature::active('foo'));
        Feature::forget('foo');

        Feature::load('foo');
        $this->assertTrue(Feature::active('foo'));
        Feature::forget('foo');

        Feature::load(['foo']);
        $this->assertTrue(Feature::active('foo'));
        Feature::forget('foo');

        Feature::load(['foo']);
        $this->assertTrue(Feature::active('foo'));
        Feature::forget('foo');

        Feature::load(['foo']);
        $this->assertFalse(Feature::active('foo'));
    }

    public function testItCanApplyMacros(): void
    {
        Feature::resolveScopeUsing(fn () => 'default-scope');
        Feature::macro('forSession', function () {
            return $this->for('session|{Session::getId()');
        });

        $this->assertFalse(Feature::forSession()->active('my-feature'));
        $this->assertFalse(Feature::for('default-scope')->active('my-feature'));

        Feature::for('session|{Session::getId()')->activate('my-feature');

        $this->assertTrue(Feature::forSession()->active('my-feature'));
        $this->assertFalse(Feature::for('default-scope')->active('my-feature'));
    }

    #[DataProvider('scopeTypes')]
    public function testItMatchesScopesAgainstTheResolverParameterType(callable|string $resolver, mixed $scope, bool $expected): void
    {
        $this->assertSame($expected, Feature::isResolverValidForScope($resolver, $scope));
    }

    public static function scopeTypes(): array
    {
        return [
            'object' => [fn (object $scope) => true, new stdClass, true],
            'object with a string' => [fn (object $scope) => true, 'taylor', false],
            'iterable' => [fn (iterable $scope) => true, ['taylor'], true],
            'callable' => [fn (callable $scope) => true, fn () => true, true],
            'float with an integer' => [fn (float $scope) => true, 1, true],
            'float with a numeric string' => [fn (float $scope) => true, '1', false],
            'true' => [fn (true $scope) => true, true, true],
            'true with false' => [fn (true $scope) => true, false, false],
            'false' => [fn (false $scope) => true, false, true],
            'self on a feature class' => [SelfScopedFeature::class, new SelfScopedFeature, true],
            'self on a method' => [[new SelfScopedFeature, 'resolve'], new SelfScopedFeature, true],
            'self with another object' => [SelfScopedFeature::class, new stdClass, false],
            'parent' => [ParentScopedFeature::class, new ScopedFeatureParent, true],
        ];
    }
}

class SelfScopedFeature
{
    public function resolve(self $scope): bool
    {
        return true;
    }
}

class ScopedFeatureParent
{
}

class ParentScopedFeature extends ScopedFeatureParent
{
    public function resolve(parent $scope): bool
    {
        return true;
    }
}
