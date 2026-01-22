<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\Query\Builder;
use Hypervel\Auth\Contracts\Authenticatable;
use Hypervel\Auth\GenericUser;
use Hypervel\Auth\Providers\DatabaseUserProvider;
use Hypervel\Hashing\Contracts\Hasher;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class AuthDatabaseUserProviderTest extends TestCase
{
    public function testRetrieveByIDReturnsUserWhenUserIsFound()
    {
        $builder = m::mock(Builder::class);
        $builder->shouldReceive('find')->once()->with(1)->andReturn(['id' => 1, 'name' => 'Dayle']);
        $conn = m::mock(ConnectionInterface::class);
        $conn->shouldReceive('table')->once()->with('foo')->andReturn($builder);
        $hasher = m::mock(Hasher::class);
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $user = $provider->retrieveById(1);

        $this->assertInstanceOf(GenericUser::class, $user);
        $this->assertSame(1, $user->getAuthIdentifier());
        $this->assertSame('Dayle', $user->name);
    }

    public function testRetrieveByIDReturnsNullWhenUserIsNotFound()
    {
        $builder = m::mock(Builder::class);
        $builder->shouldReceive('find')->once()->with(1)->andReturn(null);
        $conn = m::mock(ConnectionInterface::class);
        $conn->shouldReceive('table')->once()->with('foo')->andReturn($builder);
        $hasher = m::mock(Hasher::class);
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $user = $provider->retrieveById(1);

        $this->assertNull($user);
    }

    public function testRetrieveByCredentialsReturnsUserWhenUserIsFound()
    {
        $builder = m::mock(Builder::class);
        $builder->shouldReceive('where')->once()->with('username', 'dayle');
        $builder->shouldReceive('whereIn')->once()->with('group', ['one', 'two']);
        $builder->shouldReceive('first')->once()->andReturn(['id' => 1, 'name' => 'taylor']);
        $conn = m::mock(ConnectionInterface::class);
        $conn->shouldReceive('table')->once()->with('foo')->andReturn($builder);
        $hasher = m::mock(Hasher::class);
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $user = $provider->retrieveByCredentials(['username' => 'dayle', 'password' => 'foo', 'group' => ['one', 'two']]);

        $this->assertInstanceOf(GenericUser::class, $user);
        $this->assertSame(1, $user->getAuthIdentifier());
        $this->assertSame('taylor', $user->name);
    }

    public function testRetrieveByCredentialsAcceptsCallback()
    {
        $builder = m::mock(Builder::class);
        $builder->shouldReceive('where')->once()->with('username', 'dayle');
        $builder->shouldReceive('whereIn')->once()->with('group', ['one', 'two']);
        $builder->shouldReceive('first')->once()->andReturn(['id' => 1, 'name' => 'taylor']);
        $conn = m::mock(ConnectionInterface::class);
        $conn->shouldReceive('table')->once()->with('foo')->andReturn($builder);
        $hasher = m::mock(Hasher::class);
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');

        $user = $provider->retrieveByCredentials([function ($builder) {
            $builder->where('username', 'dayle');
            $builder->whereIn('group', ['one', 'two']);
        }]);

        $this->assertInstanceOf(GenericUser::class, $user);
        $this->assertSame(1, $user->getAuthIdentifier());
        $this->assertSame('taylor', $user->name);
    }

    public function testRetrieveByCredentialsReturnsNullWhenUserIsFound()
    {
        $builder = m::mock(Builder::class);
        $builder->shouldReceive('where')->once()->with('username', 'dayle');
        $builder->shouldReceive('first')->once()->andReturn(null);
        $conn = m::mock(ConnectionInterface::class);
        $conn->shouldReceive('table')->once()->with('foo')->andReturn($builder);
        $hasher = m::mock(Hasher::class);
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $user = $provider->retrieveByCredentials(['username' => 'dayle']);

        $this->assertNull($user);
    }

    public function testRetrieveByCredentialsWithMultiplyPasswordsReturnsNull()
    {
        $conn = m::mock(ConnectionInterface::class);
        $hasher = m::mock(Hasher::class);
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $user = $provider->retrieveByCredentials([
            'password' => 'dayle',
            'password2' => 'night',
        ]);

        $this->assertNull($user);
    }

    public function testCredentialValidation()
    {
        $conn = m::mock(ConnectionInterface::class);
        $hasher = m::mock(Hasher::class);
        $hasher->shouldReceive('check')->once()->with('plain', 'hash')->andReturn(true);
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthPassword')->once()->andReturn('hash');
        $result = $provider->validateCredentials($user, ['password' => 'plain']);

        $this->assertTrue($result);
    }
}
