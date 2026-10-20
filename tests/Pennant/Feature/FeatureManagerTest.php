<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pennant\Feature;

use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Pennant\Feature;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Lottery;
use Hypervel\Tests\Pennant\Fixtures\User;
use Hypervel\Tests\Pennant\TestCase;

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
}
