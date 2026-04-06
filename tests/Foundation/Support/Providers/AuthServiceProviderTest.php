<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Support\Providers\AuthServiceProviderTest;

use Hypervel\Auth\Access\Gate;
use Hypervel\Contracts\Auth\Access\Gate as GateContract;
use Hypervel\Foundation\Support\Providers\AuthServiceProvider;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class AuthServiceProviderTest extends TestCase
{
    public function testPoliciesReturnsEmptyArrayByDefault()
    {
        $provider = new AuthServiceProvider($this->app);

        $this->assertSame([], $provider->policies());
    }

    public function testPoliciesReturnsDefinedPolicies()
    {
        $provider = new AuthServiceProviderWithPolicies($this->app);

        $this->assertSame([
            TestModel::class => TestPolicy::class,
            TestComment::class => TestCommentPolicy::class,
        ], $provider->policies());
    }

    public function testRegisterPoliciesRegistersWithGate()
    {
        $provider = new AuthServiceProviderWithPolicies($this->app);
        $provider->registerPolicies();

        $gate = $this->app->make(GateContract::class);

        $this->assertSame([
            TestModel::class => TestPolicy::class,
            TestComment::class => TestCommentPolicy::class,
        ], $gate->policies());
    }

    public function testRegisterPoliciesDoesNothingWhenNoPoliciesDefined()
    {
        $gate = $this->app->make(GateContract::class);
        $policiesBefore = $gate->policies();

        $provider = new AuthServiceProvider($this->app);
        $provider->registerPolicies();

        $this->assertSame($policiesBefore, $gate->policies());
    }

    public function testRegisterDefersPoliciesToBootingCallback()
    {
        // Fresh Gate so we can observe the empty → populated transition
        $freshGate = new Gate($this->app, fn () => null);
        $this->app->instance(GateContract::class, $freshGate);

        $provider = new AuthServiceProviderWithPolicies($this->app);
        $provider->register();

        // After register(), policies should NOT be registered yet
        $this->assertSame([], $freshGate->policies());

        // After booting callbacks fire, policies should be registered
        $provider->callBootingCallbacks();

        $this->assertSame([
            TestModel::class => TestPolicy::class,
            TestComment::class => TestCommentPolicy::class,
        ], $freshGate->policies());
    }

    public function testPoliciesAreRegisteredDuringAppBoot()
    {
        // Register the provider so it participates in the boot lifecycle.
        // Testbench calls register() + boot() on providers returned here.
        $this->app->register(AuthServiceProviderWithPolicies::class);

        $gate = $this->app->make(GateContract::class);

        $this->assertSame(
            TestPolicy::class,
            $gate->getPolicyFor(new TestModel)::class
        );
        $this->assertSame(
            TestCommentPolicy::class,
            $gate->getPolicyFor(new TestComment)::class
        );
    }
}

class AuthServiceProviderWithPolicies extends AuthServiceProvider
{
    protected array $policies = [
        TestModel::class => TestPolicy::class,
        TestComment::class => TestCommentPolicy::class,
    ];
}

class TestModel
{
}

class TestComment
{
}

class TestPolicy
{
    public function view(?object $user, TestModel $model): bool
    {
        return true;
    }
}

class TestCommentPolicy
{
    public function view(?object $user, TestComment $comment): bool
    {
        return true;
    }
}
