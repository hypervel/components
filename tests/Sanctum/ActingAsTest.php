<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hyperf\Context\Context;
use Hypervel\Sanctum\Sanctum;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Sanctum\Stub\User;

/**
 * @internal
 * @coversNothing
 */
class ActingAsTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        Context::destroy('__sanctum.acting_as_user');
        Context::destroy('__sanctum.acting_as_guard');
    }

    public function testActingAsSetsUserInContext(): void
    {
        $user = new User();
        $user->id = 123;
        
        $result = Sanctum::actingAs($user);
        
        $this->assertSame($user, $result);
        $this->assertSame($user, Context::get('__sanctum.acting_as_user'));
        $this->assertEquals('sanctum', Context::get('__sanctum.acting_as_guard'));
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
        
        $this->assertSame($user, Context::get('__sanctum.acting_as_user'));
        $this->assertEquals('api', Context::get('__sanctum.acting_as_guard'));
    }

    public function testActingAsRemovesRecentlyCreatedFlag(): void
    {
        $user = new User();
        $user->wasRecentlyCreated = true;
        
        Sanctum::actingAs($user);
        
        $this->assertFalse($user->wasRecentlyCreated);
    }
}