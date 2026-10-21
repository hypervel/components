<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\GatePolicyResolutionTest;

use Hypervel\Auth\Access\Events\GateEvaluated;
use Hypervel\Database\Eloquent\Attributes\UsePolicy;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Gate;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Integration\Auth\Fixtures\AuthTestUser;
use Hypervel\Tests\Integration\Auth\Fixtures\Models\AuthTestUser as ModelAuthTestUser;
use Hypervel\Tests\Integration\Auth\Fixtures\Models\Nested\SubTestUser;
use Hypervel\Tests\Integration\Auth\Fixtures\Models\Nested\TopTestUser;
use Hypervel\Tests\Integration\Auth\Fixtures\Models\Policies\Nested\SubTestUserPolicy;
use Hypervel\Tests\Integration\Auth\Fixtures\Policies\AuthTestUserPolicy;
use Hypervel\Tests\Integration\Auth\Fixtures\Policies\Nested\TopTestUserPolicy;

class GatePolicyResolutionTest extends TestCase
{
    public function testGateEvaluationEventIsFired(): void
    {
        Event::fake();

        Gate::check('foo');

        Event::assertDispatched(GateEvaluated::class);
    }

    public function testPolicyCanBeGuessedUsingClassConventions(): void
    {
        $this->assertInstanceOf(
            AuthTestUserPolicy::class,
            Gate::getPolicyFor(AuthTestUser::class)
        );

        $this->assertInstanceOf(
            AuthTestUserPolicy::class,
            Gate::getPolicyFor(ModelAuthTestUser::class)
        );

        $this->assertNull(
            Gate::getPolicyFor(static::class)
        );
    }

    public function testPolicyCanBeGuessedForParallelClassHierarchies(): void
    {
        $this->assertInstanceOf(
            TopTestUserPolicy::class,
            Gate::getPolicyFor(TopTestUser::class)
        );

        $this->assertInstanceOf(
            SubTestUserPolicy::class,
            Gate::getPolicyFor(SubTestUser::class)
        );
    }

    public function testPolicyCanBeGuessedUsingCallback(): void
    {
        Gate::guessPolicyNamesUsing(function () {
            return AuthTestUserPolicy::class;
        });

        $this->assertInstanceOf(
            AuthTestUserPolicy::class,
            Gate::getPolicyFor(AuthTestUser::class)
        );
    }

    public function testPolicyCanBeGuessedMultipleTimes(): void
    {
        Gate::guessPolicyNamesUsing(function () {
            return [
                'App\Policies\TestUserPolicy',
                AuthTestUserPolicy::class,
            ];
        });

        $this->assertInstanceOf(
            AuthTestUserPolicy::class,
            Gate::getPolicyFor(AuthTestUser::class)
        );
    }

    public function testPolicyCanBeGivenByAttribute(): void
    {
        Gate::guessPolicyNamesUsing(fn () => [AuthTestUserPolicy::class]);

        $this->assertInstanceOf(PostPolicy::class, Gate::getPolicyFor(Post::class));
    }

    public function testPolicyGivenByAttributeIsInheritedByChildClasses(): void
    {
        $this->assertInstanceOf(PostPolicy::class, Gate::getPolicyFor(ChildPost::class));
        $this->assertInstanceOf(PostPolicy::class, Gate::getPolicyFor(GrandchildPost::class));
    }

    public function testPolicyGivenByAttributeOnChildClassOverridesParentAttribute(): void
    {
        $this->assertInstanceOf(AudioPostPolicy::class, Gate::getPolicyFor(AudioPost::class));
    }

    public function testRegisteredPolicyTakesPrecedenceOverInheritedAttribute(): void
    {
        Gate::policy(ChildPost::class, AudioPostPolicy::class);

        $this->assertInstanceOf(AudioPostPolicy::class, Gate::getPolicyFor(ChildPost::class));
    }

    public function testGuessedPolicyTakesPrecedenceOverInheritedAttribute(): void
    {
        Gate::guessPolicyNamesUsing(fn () => [AuthTestUserPolicy::class]);

        $this->assertInstanceOf(AuthTestUserPolicy::class, Gate::getPolicyFor(ChildPost::class));
    }
}

#[UsePolicy(PostPolicy::class)]
class Post extends Model
{
}

class ChildPost extends Post
{
}

class GrandchildPost extends ChildPost
{
}

#[UsePolicy(AudioPostPolicy::class)]
class AudioPost extends Post
{
}

class PostPolicy
{
}

class AudioPostPolicy
{
}
