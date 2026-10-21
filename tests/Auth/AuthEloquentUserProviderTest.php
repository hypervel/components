<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Hashing\Hasher;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Auth\User;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;

class AuthEloquentUserProviderTest extends TestCase
{
    public function testRetrieveByIDReturnsUser(): void
    {
        $provider = $this->getProviderMock();
        $model = m::mock(Model::class);
        $builder = m::mock(Builder::class);
        $expectedUser = m::mock(Authenticatable::class);
        $model->expects('newQuery')->andReturn($builder);
        $model->expects('getAuthIdentifierName')->andReturn('id');
        $builder->expects('where')->with('id', 1)->andReturn($builder);
        $builder->expects('first')->andReturn($expectedUser);
        $provider->expects($this->once())->method('createModel')->willReturn($model);
        $user = $provider->retrieveById(1);

        $this->assertSame($expectedUser, $user);
    }

    public function testRetrieveByTokenReturnsUser(): void
    {
        $mockUser = m::mock(Authenticatable::class);
        $mockUser->expects('getRememberToken')->andReturn('a');

        $provider = $this->getProviderMock();
        $model = m::mock(Model::class);
        $builder = m::mock(Builder::class);
        $model->expects('newQuery')->andReturn($builder);
        $model->expects('getAuthIdentifierName')->andReturn('id');
        $builder->expects('where')->with('id', 1)->andReturn($builder);
        $builder->expects('first')->andReturn($mockUser);
        $provider->expects($this->once())->method('createModel')->willReturn($model);
        $user = $provider->retrieveByToken(1, 'a');

        $this->assertEquals($mockUser, $user);
    }

    public function testRetrieveTokenWithBadIdentifierReturnsNull(): void
    {
        $provider = $this->getProviderMock();
        $model = m::mock(Model::class);
        $builder = m::mock(Builder::class);
        $model->expects('newQuery')->andReturn($builder);
        $model->expects('getAuthIdentifierName')->andReturn('id');
        $builder->expects('where')->with('id', 1)->andReturn($builder);
        $builder->expects('first')->andReturn(null);
        $provider->expects($this->once())->method('createModel')->willReturn($model);
        $user = $provider->retrieveByToken(1, 'a');

        $this->assertNull($user);
    }

    public function testRetrievingWithOnlyPasswordCredentialReturnsNull(): void
    {
        $provider = $this->getProviderMock();
        $provider->expects($this->never())->method('createModel');
        $user = $provider->retrieveByCredentials(['api_password' => 'foo']);

        $this->assertNull($user);
    }

    public function testRetrieveByBadTokenReturnsNull(): void
    {
        $mockUser = m::mock(Authenticatable::class);
        $mockUser->expects('getRememberToken')->andReturn(null);

        $provider = $this->getProviderMock();
        $model = m::mock(Model::class);
        $builder = m::mock(Builder::class);
        $model->expects('newQuery')->andReturn($builder);
        $model->expects('getAuthIdentifierName')->andReturn('id');
        $builder->expects('where')->with('id', 1)->andReturn($builder);
        $builder->expects('first')->andReturn($mockUser);
        $provider->expects($this->once())->method('createModel')->willReturn($model);
        $user = $provider->retrieveByToken(1, 'a');

        $this->assertNull($user);
    }

    public function testUpdateRememberTokenRestoresTimestampsAfterSaving(): void
    {
        $user = new EloquentProviderRememberUserStub;
        $provider = new EloquentUserProvider(m::mock(Hasher::class), $user::class);

        $provider->updateRememberToken($user, 'remember-token');

        $this->assertFalse($user->timestampsDuringSave);
        $this->assertTrue($user->timestamps);
        $this->assertSame('remember-token', $user->getRememberToken());
    }

    public function testUpdateRememberTokenPreservesInitiallyDisabledTimestamps(): void
    {
        $user = new EloquentProviderRememberUserStub;
        $user->timestamps = false;
        $provider = new EloquentUserProvider(m::mock(Hasher::class), $user::class);

        $provider->updateRememberToken($user, 'remember-token');

        $this->assertFalse($user->timestampsDuringSave);
        $this->assertFalse($user->timestamps);
    }

    public function testUpdateRememberTokenRestoresTimestampsAfterSaveFailure(): void
    {
        $exception = new RuntimeException('Save failed.');
        $user = new EloquentProviderRememberUserStub;
        $user->saveException = $exception;
        $provider = new EloquentUserProvider(m::mock(Hasher::class), $user::class);

        try {
            $provider->updateRememberToken($user, 'remember-token');
            $this->fail('The save exception was not thrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertFalse($user->timestampsDuringSave);
        $this->assertTrue($user->timestamps);
        $this->assertSame('remember-token', $user->getRememberToken());
    }

    public function testRetrieveByCredentialsReturnsUser(): void
    {
        $provider = $this->getProviderMock();
        $model = m::mock(Model::class);
        $builder = m::mock(Builder::class);
        $expectedUser = m::mock(Authenticatable::class);
        $model->expects('newQuery')->andReturn($builder);
        $builder->expects('where')->with('username', 'dayle');
        $builder->expects('whereIn')->with('group', ['one', 'two']);
        $builder->expects('first')->andReturn($expectedUser);
        $provider->expects($this->once())->method('createModel')->willReturn($model);
        $user = $provider->retrieveByCredentials(['username' => 'dayle', 'password' => 'foo', 'group' => ['one', 'two']]);

        $this->assertSame($expectedUser, $user);
    }

    public function testRetrieveByCredentialsAcceptsCallback(): void
    {
        $provider = $this->getProviderMock();
        $model = m::mock(Model::class);
        $builder = m::mock(Builder::class);
        $expectedUser = m::mock(Authenticatable::class);
        $model->expects('newQuery')->andReturn($builder);
        $builder->expects('where')->with('username', 'dayle');
        $builder->expects('whereIn')->with('group', ['one', 'two']);
        $builder->expects('first')->andReturn($expectedUser);
        $provider->expects($this->once())->method('createModel')->willReturn($model);
        $user = $provider->retrieveByCredentials([function (Builder $builder): void {
            $builder->where('username', 'dayle');
            $builder->whereIn('group', ['one', 'two']);
        }]);

        $this->assertSame($expectedUser, $user);
    }

    public function testRetrieveByCredentialsWithMultiplyPasswordsReturnsNull(): void
    {
        $provider = $this->getProviderMock();
        $provider->expects($this->never())->method('createModel');
        $user = $provider->retrieveByCredentials([
            'password' => 'dayle',
            'password2' => 'night',
        ]);

        $this->assertNull($user);
    }

    public function testCredentialValidation(): void
    {
        $hasher = m::mock(Hasher::class);
        $hasher->expects('check')->with('plain', 'hash')->andReturn(true);
        $provider = new EloquentUserProvider($hasher, 'foo');
        $user = m::mock(Authenticatable::class);
        $user->expects('getAuthPassword')->andReturn('hash');
        $result = $provider->validateCredentials($user, ['password' => 'plain']);

        $this->assertTrue($result);
    }

    public function testCredentialValidationFailed(): void
    {
        $hasher = m::mock(Hasher::class);
        $hasher->expects('check')->with('plain', 'hash')->andReturn(false);
        $provider = new EloquentUserProvider($hasher, 'foo');
        $user = m::mock(Authenticatable::class);
        $user->expects('getAuthPassword')->andReturn('hash');
        $result = $provider->validateCredentials($user, ['password' => 'plain']);

        $this->assertFalse($result);
    }

    public function testCredentialValidationFailsGracefullyWithNullPassword(): void
    {
        $hasher = m::mock(Hasher::class);
        $hasher->shouldReceive('check')->never();
        $provider = new EloquentUserProvider($hasher, 'foo');
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

        $user = m::mock(Authenticatable::class);
        $user->expects('getAuthPassword')->andReturn('hash');
        $user->expects('getAuthPasswordName')->andReturn('password_attribute');
        $user->expects('forceFill')->with(['password_attribute' => 'rehashed'])->andReturnSelf();
        $user->expects('save');

        $provider = new EloquentUserProvider($hasher, 'foo');
        $provider->rehashPasswordIfRequired($user, ['password' => 'plain']);
    }

    public function testDontRehashPasswordIfNotRequired(): void
    {
        $hasher = m::mock(Hasher::class);
        $hasher->expects('needsRehash')->with('hash')->andReturn(false);
        $hasher->shouldNotReceive('make');

        $user = m::mock(Authenticatable::class);
        $user->expects('getAuthPassword')->andReturn('hash');
        $user->shouldNotReceive('getAuthPasswordName');
        $user->shouldNotReceive('forceFill');
        $user->shouldNotReceive('save');

        $provider = new EloquentUserProvider($hasher, 'foo');
        $provider->rehashPasswordIfRequired($user, ['password' => 'plain']);
    }

    public function testModelsCanBeCreated(): void
    {
        $hasher = m::mock(Hasher::class);
        $provider = new EloquentUserProvider($hasher, EloquentProviderUserStub::class);
        $model = $provider->createModel();

        $this->assertInstanceOf(EloquentProviderUserStub::class, $model);
    }

    public function testRegistersQueryHandler(): void
    {
        $callback = function (Builder $builder): void {
            $builder->whereIn('group', ['one', 'two']);
        };

        $provider = $this->getProviderMock();
        $model = m::mock(Model::class);
        $builder = m::mock(Builder::class);
        $model->expects('newQuery')->andReturn($builder);
        $builder->expects('where')->with('username', 'dayle');
        $builder->expects('whereIn')->with('group', ['one', 'two']);
        $expectedUser = m::mock(Authenticatable::class);
        $builder->expects('first')->andReturn($expectedUser);
        $provider->expects($this->once())->method('createModel')->willReturn($model);
        $provider->withQuery($callback);
        $user = $provider->retrieveByCredentials([function (Builder $builder): void {
            $builder->where('username', 'dayle');
        }]);

        $this->assertSame($expectedUser, $user);
        $this->assertSame($callback, $provider->getQueryCallback());
    }

    /**
     * Create a user provider with a mocked model factory.
     */
    protected function getProviderMock(): EloquentUserProvider&MockObject
    {
        $hasher = m::mock(Hasher::class);

        return $this->getMockBuilder(EloquentUserProvider::class)->onlyMethods(['createModel'])->setConstructorArgs([$hasher, 'foo'])->getMock();
    }
}

class EloquentProviderUserStub extends Model
{
}

class EloquentProviderRememberUserStub extends User
{
    public bool $timestampsDuringSave = true;

    public ?RuntimeException $saveException = null;

    /**
     * Capture the timestamp state and optionally fail the save.
     */
    public function save(array $options = []): bool
    {
        $this->timestampsDuringSave = $this->timestamps;

        if ($this->saveException !== null) {
            throw $this->saveException;
        }

        return true;
    }
}
