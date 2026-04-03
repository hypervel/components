<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Sanctum\Sanctum;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Sanctum\Fixtures\User;

/**
 * @internal
 * @coversNothing
 */
class ActingAsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Register the Sanctum service provider
        $this->app->register(SanctumServiceProvider::class);

        // Configure auth guards
        $this->app->make('config')
            ->set([
                'auth.guards.sanctum' => [
                    'driver' => 'sanctum',
                    'provider' => 'users',
                ],
                'auth.guards.api' => [
                    'driver' => 'sanctum',
                    'provider' => 'users',
                ],
                'auth.providers.users' => [
                    'driver' => 'eloquent',
                    'model' => User::class,
                ],
            ]);
    }

    public function testActingAsSetsUserInContext(): void
    {
        $user = new User();
        $user->id = 123;

        $result = Sanctum::actingAs($user);

        $this->assertSame($user, $result);
    }

    public function testActingAsWithAbilitiesSetsTokenWithCorrectAbilities(): void
    {
        $user = new User();
        $abilities = ['read', 'write'];

        Sanctum::actingAs($user, $abilities);

        $this->assertTrue($user->tokenCan('read'));
        $this->assertTrue($user->tokenCan('write'));
        $this->assertFalse($user->tokenCan('delete'));
    }

    public function testActingAsWithWildcardAbility(): void
    {
        $user = new User();

        Sanctum::actingAs($user, ['*']);

        $this->assertTrue($user->tokenCan('read'));
        $this->assertTrue($user->tokenCan('write'));
        $this->assertTrue($user->tokenCan('delete'));
        $this->assertTrue($user->tokenCan('anything'));
    }

    public function testActingAsWithCustomGuard(): void
    {
        $user = new User();
        $user->id = 456;

        Sanctum::actingAs($user, ['read'], 'api');

        $this->assertSame($user, $this->app->make('auth')->guard('api')->user());
    }

    public function testActingAsRemovesRecentlyCreatedFlag(): void
    {
        $user = new User();
        $user->wasRecentlyCreated = true;

        Sanctum::actingAs($user);

        $this->assertFalse($user->wasRecentlyCreated);
    }
}
