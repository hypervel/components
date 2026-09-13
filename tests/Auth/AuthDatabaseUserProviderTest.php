<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\DatabaseUserProvider;
use Hypervel\Auth\GenericUser;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Hashing\Hasher;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Query\Builder;
use Hypervel\Tests\TestCase;
use Mockery as m;
use stdClass;

use function Hypervel\Coroutine\parallel;

class AuthDatabaseUserProviderTest extends TestCase
{
    public function testRetrieveByIDReturnsUserWhenUserIsFound(): void
    {
        $conn = m::mock(ConnectionInterface::class);
        $query = m::mock(Builder::class);
        $conn->expects('table')->with('foo')->andReturn($query);
        $query->expects('find')->with(1)->andReturn(['id' => 1, 'name' => 'Dayle']);
        $hasher = m::mock(Hasher::class);
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $user = $provider->retrieveById(1);

        $this->assertInstanceOf(GenericUser::class, $user);
        $this->assertSame(1, $user->getAuthIdentifier());
        $this->assertSame('Dayle', $user->name);
    }

    public function testRetrieveByIDReturnsNullWhenUserIsNotFound(): void
    {
        $conn = m::mock(ConnectionInterface::class);
        $query = m::mock(Builder::class);
        $conn->expects('table')->with('foo')->andReturn($query);
        $query->expects('find')->with(1)->andReturn(null);
        $hasher = m::mock(Hasher::class);
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $user = $provider->retrieveById(1);

        $this->assertNull($user);
    }

    public function testResolverBackedProviderResolvesTheConfiguredConnectionForEveryOperation(): void
    {
        $firstConnection = m::mock(ConnectionInterface::class);
        $firstQuery = m::mock(Builder::class);
        $firstConnection->shouldReceive('table')->once()->with('foo')->andReturn($firstQuery);
        $firstQuery->shouldReceive('find')->once()->with(1)->andReturn(['id' => 1, 'name' => 'First']);

        $secondConnection = m::mock(ConnectionInterface::class);
        $secondQuery = m::mock(Builder::class);
        $secondConnection->shouldReceive('table')->once()->with('foo')->andReturn($secondQuery);
        $secondQuery->shouldReceive('find')->once()->with(2)->andReturn(['id' => 2, 'name' => 'Second']);

        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->twice()->with('auth')->andReturn($firstConnection, $secondConnection);

        $provider = new DatabaseUserProvider($resolver, m::mock(Hasher::class), 'foo', 'auth');

        $this->assertSame('First', $provider->retrieveById(1)?->name);
        $this->assertSame('Second', $provider->retrieveById(2)?->name);
    }

    public function testResolverBackedProviderDoesNotRetainConnectionsAcrossExecutions(): void
    {
        $firstConnection = m::mock(ConnectionInterface::class);
        $firstQuery = m::mock(Builder::class);
        $firstConnection->shouldReceive('table')->once()->with('foo')->andReturn($firstQuery);
        $firstQuery->shouldReceive('find')->once()->andReturn(['id' => 1, 'name' => 'First']);

        $secondConnection = m::mock(ConnectionInterface::class);
        $secondQuery = m::mock(Builder::class);
        $secondConnection->shouldReceive('table')->once()->with('foo')->andReturn($secondQuery);
        $secondQuery->shouldReceive('find')->once()->andReturn(['id' => 2, 'name' => 'Second']);

        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->twice()->with('auth')->andReturn($firstConnection, $secondConnection);
        $provider = new DatabaseUserProvider($resolver, m::mock(Hasher::class), 'foo', 'auth');

        $names = parallel([
            fn () => $provider->retrieveById(1)?->name,
            fn () => $provider->retrieveById(2)?->name,
        ]);
        sort($names);

        $this->assertSame(['First', 'Second'], $names);
    }

    public function testResolverBackedProviderUsesTheDefaultConnectionWhenNoNameIsConfigured(): void
    {
        $connection = m::mock(ConnectionInterface::class);
        $query = m::mock(Builder::class);
        $connection->shouldReceive('table')->once()->with('foo')->andReturn($query);
        $query->shouldReceive('find')->once()->with(1)->andReturn(null);

        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->once()->with(null)->andReturn($connection);

        $provider = new DatabaseUserProvider($resolver, m::mock(Hasher::class), 'foo');

        $this->assertNull($provider->retrieveById(1));
    }

    public function testResolverContractTakesPrecedenceForObjectsImplementingBothConnectionContracts(): void
    {
        $resolvedConnection = m::mock(ConnectionInterface::class);
        $query = m::mock(Builder::class);
        $resolvedConnection->shouldReceive('table')->once()->with('foo')->andReturn($query);
        $query->shouldReceive('find')->once()->with(1)->andReturn(null);

        $connectionResolver = m::mock(ConnectionInterface::class . ', ' . ConnectionResolverInterface::class);
        $connectionResolver->shouldReceive('connection')->once()->with('auth')->andReturn($resolvedConnection);
        $connectionResolver->shouldNotReceive('table');

        $provider = new DatabaseUserProvider($connectionResolver, m::mock(Hasher::class), 'foo', 'auth');

        $this->assertNull($provider->retrieveById(1));
    }

    public function testRetrieveByTokenReturnsUser(): void
    {
        $mockUser = new stdClass;
        $mockUser->remember_token = 'a';

        $conn = m::mock(ConnectionInterface::class);
        $query = m::mock(Builder::class);
        $conn->expects('table')->with('foo')->andReturn($query);
        $query->expects('find')->with(1)->andReturn($mockUser);
        $hasher = m::mock(Hasher::class);
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $user = $provider->retrieveByToken(1, 'a');

        $this->assertEquals(new GenericUser((array) $mockUser), $user);
    }

    public function testRetrieveTokenWithBadIdentifierReturnsNull(): void
    {
        $conn = m::mock(ConnectionInterface::class);
        $query = m::mock(Builder::class);
        $conn->expects('table')->with('foo')->andReturn($query);
        $query->expects('find')->with(1)->andReturn(null);
        $hasher = m::mock(Hasher::class);
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $user = $provider->retrieveByToken(1, 'a');

        $this->assertNull($user);
    }

    public function testRetrieveByBadTokenReturnsNull(): void
    {
        $mockUser = new stdClass;
        $mockUser->remember_token = null;

        $conn = m::mock(ConnectionInterface::class);
        $query = m::mock(Builder::class);
        $conn->expects('table')->with('foo')->andReturn($query);
        $query->expects('find')->with(1)->andReturn($mockUser);
        $hasher = m::mock(Hasher::class);
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $user = $provider->retrieveByToken(1, 'a');

        $this->assertNull($user);
    }

    public function testRetrieveByCredentialsReturnsUserWhenUserIsFound(): void
    {
        $conn = m::mock(ConnectionInterface::class);
        $query = m::mock(Builder::class);
        $conn->expects('table')->with('foo')->andReturn($query);
        $query->expects('where')->with('username', 'dayle');
        $query->expects('whereIn')->with('group', ['one', 'two']);
        $query->expects('first')->andReturn(['id' => 1, 'name' => 'taylor']);
        $hasher = m::mock(Hasher::class);
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $user = $provider->retrieveByCredentials(['username' => 'dayle', 'password' => 'foo', 'group' => ['one', 'two']]);

        $this->assertInstanceOf(GenericUser::class, $user);
        $this->assertSame(1, $user->getAuthIdentifier());
        $this->assertSame('taylor', $user->name);
    }

    public function testRetrieveByCredentialsAcceptsCallback(): void
    {
        $conn = m::mock(ConnectionInterface::class);
        $query = m::mock(Builder::class);
        $conn->expects('table')->with('foo')->andReturn($query);
        $query->expects('where')->with('username', 'dayle');
        $query->expects('whereIn')->with('group', ['one', 'two']);
        $query->expects('first')->andReturn(['id' => 1, 'name' => 'taylor']);
        $hasher = m::mock(Hasher::class);
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');

        $user = $provider->retrieveByCredentials([function (Builder $builder): void {
            $builder->where('username', 'dayle');
            $builder->whereIn('group', ['one', 'two']);
        }]);

        $this->assertInstanceOf(GenericUser::class, $user);
        $this->assertSame(1, $user->getAuthIdentifier());
        $this->assertSame('taylor', $user->name);
    }

    public function testRetrieveByCredentialsReturnsNullWhenUserIsFound(): void
    {
        $conn = m::mock(ConnectionInterface::class);
        $query = m::mock(Builder::class);
        $conn->expects('table')->with('foo')->andReturn($query);
        $query->expects('where')->with('username', 'dayle');
        $query->expects('first')->andReturn(null);
        $hasher = m::mock(Hasher::class);
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $user = $provider->retrieveByCredentials(['username' => 'dayle']);

        $this->assertNull($user);
    }

    public function testRetrieveByCredentialsWithMultiplyPasswordsReturnsNull(): void
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

    public function testCredentialValidation(): void
    {
        $conn = m::mock(ConnectionInterface::class);
        $hasher = m::mock(Hasher::class);
        $hasher->expects('check')->with('plain', 'hash')->andReturn(true);
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $user = m::mock(Authenticatable::class);
        $user->expects('getAuthPassword')->andReturn('hash');
        $result = $provider->validateCredentials($user, ['password' => 'plain']);

        $this->assertTrue($result);
    }

    public function testCredentialValidationFails(): void
    {
        $conn = m::mock(ConnectionInterface::class);
        $hasher = m::mock(Hasher::class);
        $hasher->expects('check')->with('plain', 'hash')->andReturn(false);
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $user = m::mock(Authenticatable::class);
        $user->expects('getAuthPassword')->andReturn('hash');
        $result = $provider->validateCredentials($user, ['password' => 'plain']);

        $this->assertFalse($result);
    }

    public function testCredentialValidationFailsGracefullyWithNullPassword(): void
    {
        $conn = m::mock(ConnectionInterface::class);
        $hasher = m::mock(Hasher::class);
        $hasher->shouldReceive('check')->never();
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $user = m::mock(Authenticatable::class);
        $user->expects('getAuthPassword')->andReturn(null);
        $result = $provider->validateCredentials($user, ['password' => 'plain']);

        $this->assertFalse($result);
    }

    public function testRehashPasswordIfRequired(): void
    {
        $hasher = m::mock(Hasher::class);
        $hasher->expects('needsRehash')->with('hash')->andReturn(true);
        $hasher->expects('make')->with('plain')->andReturn('rehashed');

        $conn = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        $conn->expects('table')->with('foo')->andReturn($table);
        $table->expects('where')->with('id', 1)->andReturnSelf();
        $table->expects('update')->with(['password_attribute' => 'rehashed']);

        $user = m::mock(Authenticatable::class);
        $user->expects('getAuthIdentifierName')->andReturn('id');
        $user->expects('getAuthIdentifier')->andReturn(1);
        $user->expects('getAuthPassword')->andReturn('hash');
        $user->expects('getAuthPasswordName')->andReturn('password_attribute');

        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $provider->rehashPasswordIfRequired($user, ['password' => 'plain']);
    }

    public function testDontRehashPasswordIfNotRequired(): void
    {
        $hasher = m::mock(Hasher::class);
        $hasher->expects('needsRehash')->with('hash')->andReturn(false);
        $hasher->shouldNotReceive('make');

        $conn = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        $conn->shouldNotReceive('table');
        $table->shouldNotReceive('where');
        $table->shouldNotReceive('update');

        $user = m::mock(Authenticatable::class);
        $user->expects('getAuthPassword')->andReturn('hash');
        $user->shouldNotReceive('getAuthIdentifierName');
        $user->shouldNotReceive('getAuthIdentifier');
        $user->shouldNotReceive('getAuthPasswordName');

        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $provider->rehashPasswordIfRequired($user, ['password' => 'plain']);
    }
}
