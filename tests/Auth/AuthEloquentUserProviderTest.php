<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Authenticatable as AuthenticatableTrait;
use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Auth\GenericUser;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Hashing\Hasher;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Auth\User;
use Hypervel\Hashing\BcryptHasher;
use Hypervel\Tests\Database\Concerns\InteractsWithInMemoryConnection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

class AuthEloquentUserProviderTest extends TestCase
{
    use InteractsWithInMemoryConnection;

    public function testRetrieveByIDReturnsUser(): void
    {
        $user = $this->newProvider()->retrieveById(3);

        $this->assertInstanceOf(EloquentProviderUserStub::class, $user);
        $this->assertSame('taylor', $user->name);
    }

    public function testRetrieveByTokenReturnsUser(): void
    {
        $user = $this->newProvider()->retrieveByToken(3, 'a');

        $this->assertInstanceOf(EloquentProviderUserStub::class, $user);
        $this->assertSame('taylor', $user->name);
    }

    public function testRetrieveTokenWithBadIdentifierReturnsNull(): void
    {
        $this->assertNull($this->newProvider()->retrieveByToken(99, 'a'));
    }

    public function testRetrievingWithOnlyPasswordCredentialReturnsNull(): void
    {
        $user = $this->newProvider()->retrieveByCredentials(['api_password' => 'foo']);

        $this->assertNull($user);
    }

    public function testRetrieveByBadTokenReturnsNull(): void
    {
        $provider = $this->newProvider();

        $this->assertNull($provider->retrieveByToken(1, 'a'));
        $this->assertNull($provider->retrieveByToken(3, 'b'));
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
        $user = $this->newProvider()->retrieveByCredentials(['username' => 'dayle', 'password' => 'foo', 'group' => ['one', 'two']]);

        $this->assertInstanceOf(EloquentProviderUserStub::class, $user);
        $this->assertSame('taylor', $user->name);
    }

    public function testRetrieveByCredentialsAcceptsCallback(): void
    {
        $user = $this->newProvider()->retrieveByCredentials([function (Builder $builder): void {
            $builder->where('username', 'dayle');
            $builder->whereIn('group', ['one', 'two']);
        }]);

        $this->assertSame('taylor', $user->name);
    }

    public function testRetrieveByCredentialsWithMultiplyPasswordsReturnsNull(): void
    {
        $user = $this->newProvider()->retrieveByCredentials([
            'password' => 'dayle',
            'password2' => 'night',
        ]);

        $this->assertNull($user);
    }

    public function testCredentialValidation(): void
    {
        $hasher = new BcryptHasher;
        $provider = new EloquentUserProvider($hasher, 'foo');
        $user = new GenericUser(['password' => $hasher->make('plain')]);
        $result = $provider->validateCredentials($user, ['password' => 'plain']);

        $this->assertTrue($result);
    }

    public function testCredentialValidationFailed(): void
    {
        $hasher = new BcryptHasher;
        $provider = new EloquentUserProvider($hasher, 'foo');
        $user = new GenericUser(['password' => $hasher->make('plain')]);
        $result = $provider->validateCredentials($user, ['password' => 'wrong']);

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
        $hasher = new BcryptHasher(['rounds' => 5]);
        $provider = $this->newProvider($hasher);
        $user = EloquentProviderUserStub::find(3);
        $user->forceFill(['password' => (new BcryptHasher(['rounds' => 4]))->make('plain')])->save();

        $provider->rehashPasswordIfRequired($user, ['password' => 'plain']);

        $hash = $user->fresh()->password;
        $this->assertTrue($hasher->check('plain', $hash));
        $this->assertFalse($hasher->needsRehash($hash));
    }

    public function testRehashPasswordIfRequiredWithCustomPasswordColumn(): void
    {
        $hasher = new BcryptHasher(['rounds' => 5]);
        $provider = $this->newProvider($hasher);
        (new EloquentProviderCustomPasswordUserStub)->getConnection()->statement('alter table "users" rename column "password" to "secret"');
        $user = EloquentProviderCustomPasswordUserStub::find(3);
        $user->forceFill(['secret' => (new BcryptHasher(['rounds' => 4]))->make('plain')])->save();

        $provider->rehashPasswordIfRequired($user, ['password' => 'plain']);

        $hash = $user->fresh()->secret;
        $this->assertTrue($hasher->check('plain', $hash));
        $this->assertFalse($hasher->needsRehash($hash));
    }

    public function testDontRehashPasswordIfNotRequired(): void
    {
        $hasher = m::mock(Hasher::class);
        $hasher->expects('needsRehash')->with('hash')->andReturn(false);
        $hasher->shouldNotReceive('make');

        $provider = $this->newProvider($hasher);
        $user = EloquentProviderUserStub::find(3);
        $provider->rehashPasswordIfRequired($user, ['password' => 'plain']);

        $this->assertSame('hash', $user->fresh()->password);
    }

    public function testModelsCanBeCreated(): void
    {
        $hasher = new BcryptHasher;
        $provider = new EloquentUserProvider($hasher, EloquentProviderUserStub::class);
        $model = $provider->createModel();

        $this->assertInstanceOf(EloquentProviderUserStub::class, $model);
    }

    public function testRegistersQueryHandler(): void
    {
        $callback = function (Builder $builder): void {
            $builder->whereIn('group', ['one', 'two']);
        };

        $provider = $this->newProvider();
        $provider->withQuery($callback);
        $user = $provider->retrieveByCredentials([function (Builder $builder): void {
            $builder->where('username', 'dayle');
        }]);

        $this->assertSame('taylor', $user->name);
        $this->assertSame($callback, $provider->getQueryCallback());
    }

    /**
     * Create a user provider backed by seeded in-memory users.
     */
    protected function newProvider(?Hasher $hasher = null): EloquentUserProvider
    {
        $pdo = $this->useInMemoryConnection()->getPdo();
        $pdo->exec('create table "users" ("id" integer primary key, "username" text, "group" text, "name" text, "remember_token" text, "password" text)');
        $pdo->exec("insert into \"users\" values (1, 'dayle', 'three', 'other', null, null)");
        $pdo->exec("insert into \"users\" values (2, 'sam', 'one', 'third', null, null)");
        $pdo->exec("insert into \"users\" values (3, 'dayle', 'two', 'taylor', 'a', 'hash')");

        return new EloquentUserProvider($hasher ?? new BcryptHasher, EloquentProviderUserStub::class);
    }
}

class EloquentProviderUserStub extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    protected ?string $table = 'users';

    public bool $timestamps = false;
}

class EloquentProviderCustomPasswordUserStub extends EloquentProviderUserStub
{
    protected string $authPasswordName = 'secret';
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
