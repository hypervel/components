<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Hashing\Hasher;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Tests\TestCase;
use Mockery as m;

class AuthEloquentUserProviderTest extends TestCase
{
    public function testRetrieveByIDReturnsUser()
    {
        $provider = $this->getProviderMock();
        $model = m::mock(Model::class);
        $builder = m::mock(Builder::class);
        $expectedUser = m::mock(Authenticatable::class);
        $model->shouldReceive('newQuery')->once()->andReturn($builder);
        $model->shouldReceive('getAuthIdentifierName')->once()->andReturn('id');
        $builder->shouldReceive('where')->once()->with('id', 1)->andReturn($builder);
        $builder->shouldReceive('first')->once()->andReturn($expectedUser);
        $provider->expects($this->once())->method('createModel')->willReturn($model);
        $user = $provider->retrieveById(1);

        $this->assertSame($expectedUser, $user);
    }

    public function testRetrieveByTokenReturnsUser()
    {
        $mockUser = m::mock(Authenticatable::class);
        $mockUser->shouldReceive('getRememberToken')->once()->andReturn('a');

        $provider = $this->getProviderMock();
        $model = m::mock(Model::class);
        $builder = m::mock(Builder::class);
        $model->shouldReceive('newQuery')->once()->andReturn($builder);
        $model->shouldReceive('getAuthIdentifierName')->once()->andReturn('id');
        $builder->shouldReceive('where')->once()->with('id', 1)->andReturn($builder);
        $builder->shouldReceive('first')->once()->andReturn($mockUser);
        $provider->expects($this->once())->method('createModel')->willReturn($model);
        $user = $provider->retrieveByToken(1, 'a');

        $this->assertEquals($mockUser, $user);
    }

    public function testRetrieveTokenWithBadIdentifierReturnsNull()
    {
        $provider = $this->getProviderMock();
        $model = m::mock(Model::class);
        $builder = m::mock(Builder::class);
        $model->shouldReceive('newQuery')->once()->andReturn($builder);
        $model->shouldReceive('getAuthIdentifierName')->once()->andReturn('id');
        $builder->shouldReceive('where')->once()->with('id', 1)->andReturn($builder);
        $builder->shouldReceive('first')->once()->andReturn(null);
        $provider->expects($this->once())->method('createModel')->willReturn($model);
        $user = $provider->retrieveByToken(1, 'a');

        $this->assertNull($user);
    }

    public function testRetrievingWithOnlyPasswordCredentialReturnsNull()
    {
        $provider = $this->getProviderMock();
        $provider->expects($this->never())->method('createModel');
        $user = $provider->retrieveByCredentials(['api_password' => 'foo']);

        $this->assertNull($user);
    }

    public function testRetrieveByBadTokenReturnsNull()
    {
        $mockUser = m::mock(Authenticatable::class);
        $mockUser->shouldReceive('getRememberToken')->once()->andReturn(null);

        $provider = $this->getProviderMock();
        $model = m::mock(Model::class);
        $builder = m::mock(Builder::class);
        $model->shouldReceive('newQuery')->once()->andReturn($builder);
        $model->shouldReceive('getAuthIdentifierName')->once()->andReturn('id');
        $builder->shouldReceive('where')->once()->with('id', 1)->andReturn($builder);
        $builder->shouldReceive('first')->once()->andReturn($mockUser);
        $provider->expects($this->once())->method('createModel')->willReturn($model);
        $user = $provider->retrieveByToken(1, 'a');

        $this->assertNull($user);
    }

    public function testRetrieveByCredentialsReturnsUser()
    {
        $provider = $this->getProviderMock();
        $model = m::mock(Model::class);
        $builder = m::mock(Builder::class);
        $expectedUser = m::mock(Authenticatable::class);
        $model->shouldReceive('newQuery')->once()->andReturn($builder);
        $builder->shouldReceive('where')->once()->with('username', 'dayle');
        $builder->shouldReceive('whereIn')->once()->with('group', ['one', 'two']);
        $builder->shouldReceive('first')->once()->andReturn($expectedUser);
        $provider->expects($this->once())->method('createModel')->willReturn($model);
        $user = $provider->retrieveByCredentials(['username' => 'dayle', 'password' => 'foo', 'group' => ['one', 'two']]);

        $this->assertSame($expectedUser, $user);
    }

    public function testRetrieveByCredentialsAcceptsCallback()
    {
        $provider = $this->getProviderMock();
        $model = m::mock(Model::class);
        $builder = m::mock(Builder::class);
        $expectedUser = m::mock(Authenticatable::class);
        $model->shouldReceive('newQuery')->once()->andReturn($builder);
        $builder->shouldReceive('where')->once()->with('username', 'dayle');
        $builder->shouldReceive('whereIn')->once()->with('group', ['one', 'two']);
        $builder->shouldReceive('first')->once()->andReturn($expectedUser);
        $provider->expects($this->once())->method('createModel')->willReturn($model);
        $user = $provider->retrieveByCredentials([function ($builder) {
            $builder->where('username', 'dayle');
            $builder->whereIn('group', ['one', 'two']);
        }]);

        $this->assertSame($expectedUser, $user);
    }

    public function testRetrieveByCredentialsWithMultiplyPasswordsReturnsNull()
    {
        $provider = $this->getProviderMock();
        $provider->expects($this->never())->method('createModel');
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

    public function testCredentialValidationFailed()
    {
        $hasher = m::mock(Hasher::class);
        $hasher->shouldReceive('check')->once()->with('plain', 'hash')->andReturn(false);
        $provider = new EloquentUserProvider($hasher, 'foo');
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthPassword')->once()->andReturn('hash');
        $result = $provider->validateCredentials($user, ['password' => 'plain']);

        $this->assertFalse($result);
    }

    public function testCredentialValidationFailsGracefullyWithNullPassword()
    {
        $hasher = m::mock(Hasher::class);
        $hasher->shouldReceive('check')->never();
        $provider = new EloquentUserProvider($hasher, 'foo');
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthPassword')->once()->andReturn(null);
        $result = $provider->validateCredentials($user, ['password' => 'plain']);

        $this->assertFalse($result);
    }

    public function testRehashPasswordIfRequired()
    {
        $hasher = m::mock(Hasher::class);
        $hasher->shouldReceive('needsRehash')->once()->with('hash')->andReturn(true);
        $hasher->shouldReceive('make')->once()->with('plain')->andReturn('rehashed');

        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthPassword')->once()->andReturn('hash');
        $user->shouldReceive('getAuthPasswordName')->once()->andReturn('password_attribute');
        $user->shouldReceive('forceFill')->once()->with(['password_attribute' => 'rehashed'])->andReturnSelf();
        $user->shouldReceive('save')->once();

        $provider = new EloquentUserProvider($hasher, 'foo');
        $provider->rehashPasswordIfRequired($user, ['password' => 'plain']);
    }

    public function testDontRehashPasswordIfNotRequired()
    {
        $hasher = m::mock(Hasher::class);
        $hasher->shouldReceive('needsRehash')->once()->with('hash')->andReturn(false);
        $hasher->shouldNotReceive('make');

        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthPassword')->once()->andReturn('hash');
        $user->shouldNotReceive('getAuthPasswordName');
        $user->shouldNotReceive('forceFill');
        $user->shouldNotReceive('save');

        $provider = new EloquentUserProvider($hasher, 'foo');
        $provider->rehashPasswordIfRequired($user, ['password' => 'plain']);
    }

    public function testModelsCanBeCreated()
    {
        $hasher = m::mock(Hasher::class);
        $provider = new EloquentUserProvider($hasher, EloquentProviderUserStub::class);
        $model = $provider->createModel();

        $this->assertInstanceOf(EloquentProviderUserStub::class, $model);
    }

    public function testRegistersQueryHandler()
    {
        $callback = function ($builder) {
            $builder->whereIn('group', ['one', 'two']);
        };

        $provider = $this->getProviderMock();
        $model = m::mock(Model::class);
        $builder = m::mock(Builder::class);
        $model->shouldReceive('newQuery')->once()->andReturn($builder);
        $builder->shouldReceive('where')->once()->with('username', 'dayle');
        $builder->shouldReceive('whereIn')->once()->with('group', ['one', 'two']);
        $expectedUser = m::mock(Authenticatable::class);
        $builder->shouldReceive('first')->once()->andReturn($expectedUser);
        $provider->expects($this->once())->method('createModel')->willReturn($model);
        $provider->withQuery($callback);
        $user = $provider->retrieveByCredentials([function ($builder) {
            $builder->where('username', 'dayle');
        }]);

        $this->assertSame($expectedUser, $user);
        $this->assertSame($callback, $provider->getQueryCallback());
    }

    protected function getProviderMock()
    {
        $hasher = m::mock(Hasher::class);

        return $this->getMockBuilder(EloquentUserProvider::class)->onlyMethods(['createModel'])->setConstructorArgs([$hasher, 'foo'])->getMock();
    }
}

class EloquentProviderUserStub extends Model
{
}
