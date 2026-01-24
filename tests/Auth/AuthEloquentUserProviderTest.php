<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Auth\Authenticatable as AuthenticatableUser;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Auth\Providers\EloquentUserProvider;
use Hypervel\Hashing\Contracts\Hasher;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class AuthEloquentUserProviderTest extends TestCase
{
    public function testRetrieveByIDReturnsUser()
    {
        $provider = $this->getProviderMock();
        $mock = m::mock(User::class);
        $mock->shouldReceive('getAuthIdentifierName')->once()->andReturn('id');
        $builder = m::mock(Builder::class);
        $builder->shouldReceive('where')->once()->with('id', 1)->andReturn($builder);
        $builder->shouldReceive('first')->once()->andReturn($mock);
        $mock->shouldReceive('newQuery')->once()->andReturn($builder);
        $provider->expects($this->once())->method('createModel')->willReturn($mock);
        $user = $provider->retrieveById(1);

        $this->assertSame($mock, $user);
    }

    public function testRetrievingWithOnlyPasswordCredentialReturnsNull()
    {
        $provider = $this->getProviderMock();
        $user = $provider->retrieveByCredentials(['api_password' => 'foo']);

        $this->assertNull($user);
    }

    public function testRetrieveByCredentialsReturnsUser()
    {
        $provider = $this->getProviderMock();
        $mock = m::mock(User::class);
        $builder = m::mock(Builder::class);
        $mock->shouldReceive('newQuery')->once()->andReturn($builder);
        $builder->shouldReceive('where')->once()->with('username', 'dayle');
        $builder->shouldReceive('whereIn')->once()->with('group', ['one', 'two']);
        $builder->shouldReceive('first')->once()->andReturn($mock);
        $provider->expects($this->once())->method('createModel')->willReturn($mock);
        $user = $provider->retrieveByCredentials(['username' => 'dayle', 'password' => 'foo', 'group' => ['one', 'two']]);

        $this->assertSame($mock, $user);
    }

    public function testRetrieveByCredentialsAcceptsCallback()
    {
        $provider = $this->getProviderMock();
        $mock = m::mock(User::class);
        $builder = m::mock(Builder::class);
        $mock->shouldReceive('newQuery')->once()->andReturn($builder);
        $builder->shouldReceive('where')->once()->with('username', 'dayle');
        $builder->shouldReceive('whereIn')->once()->with('group', ['one', 'two']);
        $builder->shouldReceive('first')->once()->andReturn($mock);
        $provider->expects($this->once())->method('createModel')->willReturn($mock);
        $user = $provider->retrieveByCredentials([function ($builder) {
            $builder->where('username', 'dayle');
            $builder->whereIn('group', ['one', 'two']);
        }]);

        $this->assertSame($mock, $user);
    }

    public function testRetrieveByCredentialsWithMultiplyPasswordsReturnsNull()
    {
        $provider = $this->getProviderMock();
        $user = $provider->retrieveByCredentials([
            'password' => 'dayle',
            'password2' => 'night',
        ]);

        $this->assertNull($user);
    }

    public function testCredentialValidation()
    {
        $hasher = m::mock(Hasher::class);
        $hasher->shouldReceive('check')->once()->with('plain', 'hash')->andReturn(true);
        $provider = new EloquentUserProvider($hasher, 'foo');
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthPassword')->once()->andReturn('hash');
        $result = $provider->validateCredentials($user, ['password' => 'plain']);

        $this->assertTrue($result);
    }

    public function testModelsCanBeCreated()
    {
        $hasher = m::mock(Hasher::class);
        $provider = new EloquentUserProvider($hasher, User::class);
        $model = $provider->createModel();

        $this->assertInstanceOf(User::class, $model);
    }

    public function testRegistersQueryHandler()
    {
        $callback = function ($builder) {
            $builder->whereIn('group', ['one', 'two']);
        };

        $provider = $this->getProviderMock();
        $mock = m::mock(User::class);
        $builder = m::mock(Builder::class);
        $mock->shouldReceive('newQuery')->once()->andReturn($builder);
        $builder->shouldReceive('where')->once()->with('username', 'dayle');
        $builder->shouldReceive('whereIn')->once()->with('group', ['one', 'two']);
        $builder->shouldReceive('first')->once()->andReturn($mock);
        $provider->expects($this->once())->method('createModel')->willReturn($mock);
        $provider->withQuery($callback);
        $user = $provider->retrieveByCredentials([function ($builder) {
            $builder->where('username', 'dayle');
        }]);

        $this->assertSame($mock, $user);
        $this->assertSame($callback, $provider->getQueryCallback());
    }

    protected function getProviderMock()
    {
        $hasher = m::mock(Hasher::class);

        return $this->getMockBuilder(EloquentUserProvider::class)->onlyMethods(['createModel'])->setConstructorArgs([$hasher, 'foo'])->getMock();
    }
}

class EloquentProviderUserStub
{
}

class User extends Model implements Authenticatable
{
    use AuthenticatableUser;
}
