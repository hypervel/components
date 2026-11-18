<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\DatabaseUserProvider;
use Hypervel\Auth\GenericUser;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Hashing\Hasher;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Hashing\BcryptHasher;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PDO;

use function Hypervel\Coroutine\parallel;

class AuthDatabaseUserProviderTest extends TestCase
{
    use VerifiesDoubles;

    public function testRetrieveByIDReturnsUserWhenUserIsFound(): void
    {
        $provider = $this->newProvider();
        $user = $provider->retrieveById(3);

        $this->assertInstanceOf(GenericUser::class, $user);
        $this->assertSame(3, $user->getAuthIdentifier());
        $this->assertSame('taylor', $user->name);
    }

    public function testRetrieveByIDReturnsNullWhenUserIsNotFound(): void
    {
        $provider = $this->newProvider();
        $user = $provider->retrieveById(99);

        $this->assertNull($user);
    }

    public function testResolverBackedProviderResolvesTheConfiguredConnectionForEveryOperation(): void
    {
        $firstConnection = Double::for(Connection::class)->strict();
        $firstQuery = Double::for(Builder::class)->strict();
        $firstConnection->expects('table')->with('foo')->returns($firstQuery);
        $firstQuery->expects('find')->with(1)->returns(['id' => 1, 'name' => 'First']);

        $secondConnection = Double::for(Connection::class)->strict();
        $secondQuery = Double::for(Builder::class)->strict();
        $secondConnection->expects('table')->with('foo')->returns($secondQuery);
        $secondQuery->expects('find')->with(2)->returns(['id' => 2, 'name' => 'Second']);

        $resolver = Double::for(ConnectionResolverInterface::class)->strict();
        $resolver->expects('connection')->times(2)->with('auth')->returns($firstConnection, $secondConnection);

        $provider = new DatabaseUserProvider($resolver, Double::for(Hasher::class)->strict(), 'foo', 'auth');

        $this->assertSame('First', $provider->retrieveById(1)?->name);
        $this->assertSame('Second', $provider->retrieveById(2)?->name);
    }

    public function testResolverBackedProviderDoesNotRetainConnectionsAcrossExecutions(): void
    {
        $firstConnection = Double::for(Connection::class)->strict();
        $firstQuery = Double::for(Builder::class)->strict();
        $firstConnection->expects('table')->with('foo')->returns($firstQuery);
        $firstQuery->expects('find')->returns(['id' => 1, 'name' => 'First']);

        $secondConnection = Double::for(Connection::class)->strict();
        $secondQuery = Double::for(Builder::class)->strict();
        $secondConnection->expects('table')->with('foo')->returns($secondQuery);
        $secondQuery->expects('find')->returns(['id' => 2, 'name' => 'Second']);

        $resolver = Double::for(ConnectionResolverInterface::class)->strict();
        $resolver->expects('connection')->times(2)->with('auth')->returns($firstConnection, $secondConnection);
        $provider = new DatabaseUserProvider($resolver, Double::for(Hasher::class)->strict(), 'foo', 'auth');

        $names = parallel([
            fn () => $provider->retrieveById(1)?->name,
            fn () => $provider->retrieveById(2)?->name,
        ]);
        sort($names);

        $this->assertSame(['First', 'Second'], $names);
    }

    public function testResolverBackedProviderUsesTheDefaultConnectionWhenNoNameIsConfigured(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $query = Double::for(Builder::class)->strict();
        $connection->expects('table')->with('foo')->returns($query);
        $query->expects('find')->with(1)->returns(null);

        $resolver = Double::for(ConnectionResolverInterface::class)->strict();
        $resolver->expects('connection')->with(null)->returns($connection);

        $provider = new DatabaseUserProvider($resolver, Double::for(Hasher::class)->strict(), 'foo');

        $this->assertNull($provider->retrieveById(1));
    }

    public function testResolverContractTakesPrecedenceForObjectsImplementingBothConnectionContracts(): void
    {
        $resolvedConnection = Double::for(Connection::class)->strict();
        $query = Double::for(Builder::class)->strict();
        $resolvedConnection->expects('table')->with('foo')->returns($query);
        $query->expects('find')->with(1)->returns(null);

        $connectionResolver = Double::for(ConnectionInterface::class, ConnectionResolverInterface::class)->strict();
        $connectionResolver->expects('connection')->with('auth')->returns($resolvedConnection);
        $connectionResolver->expects('table')->never();

        $provider = new DatabaseUserProvider($connectionResolver, Double::for(Hasher::class)->strict(), 'foo', 'auth');

        $this->assertNull($provider->retrieveById(1));
    }

    public function testRetrieveByTokenReturnsUser(): void
    {
        $provider = $this->newProvider();
        $user = $provider->retrieveByToken(3, 'a');

        $this->assertInstanceOf(GenericUser::class, $user);
        $this->assertSame(3, $user->getAuthIdentifier());
    }

    public function testRetrieveTokenWithBadIdentifierReturnsNull(): void
    {
        $provider = $this->newProvider();
        $user = $provider->retrieveByToken(99, 'a');

        $this->assertNull($user);
    }

    public function testRetrieveByBadTokenReturnsNull(): void
    {
        $provider = $this->newProvider();
        $user = $provider->retrieveByToken(1, 'a');

        $this->assertNull($user);
    }

    public function testRetrieveByCredentialsReturnsUserWhenUserIsFound(): void
    {
        $provider = $this->newProvider();
        $user = $provider->retrieveByCredentials(['username' => 'dayle', 'password' => 'foo', 'group' => ['one', 'two']]);

        $this->assertInstanceOf(GenericUser::class, $user);
        $this->assertSame(3, $user->getAuthIdentifier());
        $this->assertSame('taylor', $user->name);
    }

    public function testRetrieveByCredentialsAcceptsCallback(): void
    {
        $provider = $this->newProvider();

        $user = $provider->retrieveByCredentials([function (Builder $builder): void {
            $builder->where('username', 'dayle');
            $builder->whereIn('group', ['one', 'two']);
        }]);

        $this->assertInstanceOf(GenericUser::class, $user);
        $this->assertSame(3, $user->getAuthIdentifier());
        $this->assertSame('taylor', $user->name);
    }

    public function testRetrieveByCredentialsReturnsNullWhenUserIsFound(): void
    {
        $provider = $this->newProvider();
        $user = $provider->retrieveByCredentials(['username' => 'nobody']);

        $this->assertNull($user);
    }

    public function testRetrieveByCredentialsWithMultiplyPasswordsReturnsNull(): void
    {
        $conn = new PdoConnection(new PDO('sqlite::memory:'));
        $hasher = new BcryptHasher;
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $user = $provider->retrieveByCredentials([
            'password' => 'dayle',
            'password2' => 'night',
        ]);

        $this->assertNull($user);
    }

    public function testCredentialValidation(): void
    {
        $conn = new PdoConnection(new PDO('sqlite::memory:'));
        $hasher = new BcryptHasher(['rounds' => 4]);
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $user = new GenericUser(['password' => $hasher->make('plain')]);
        $result = $provider->validateCredentials($user, ['password' => 'plain']);

        $this->assertTrue($result);
    }

    public function testCredentialValidationFails(): void
    {
        $conn = new PdoConnection(new PDO('sqlite::memory:'));
        $hasher = new BcryptHasher(['rounds' => 4]);
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $user = new GenericUser(['password' => $hasher->make('plain')]);
        $result = $provider->validateCredentials($user, ['password' => 'wrong']);

        $this->assertFalse($result);
    }

    public function testCredentialValidationFailsGracefullyWithNullPassword(): void
    {
        $conn = new PdoConnection(new PDO('sqlite::memory:'));
        $hasher = Double::for(Hasher::class)->strict();
        $hasher->expects('check')->never();
        $provider = new DatabaseUserProvider($conn, $hasher, 'foo');
        $user = Double::for(Authenticatable::class)->strict();
        $user->expects('getAuthPassword')->returns(null);
        $result = $provider->validateCredentials($user, ['password' => 'plain']);

        $this->assertFalse($result);
    }

    public function testRehashPasswordIfRequired(): void
    {
        $connection = $this->newConnection();
        $connection->table('foo')->where('id', 3)->update(['password' => (new BcryptHasher(['rounds' => 4]))->make('plain')]);

        $hasher = new BcryptHasher(['rounds' => 5]);
        $provider = new DatabaseUserProvider($connection, $hasher, 'foo');
        $provider->rehashPasswordIfRequired($provider->retrieveById(3), ['password' => 'plain']);

        $hash = $connection->table('foo')->where('id', 3)->value('password');
        $this->assertTrue($hasher->check('plain', $hash));
        $this->assertFalse($hasher->needsRehash($hash));
    }

    public function testRehashPasswordIfRequiredWithCustomPasswordColumn(): void
    {
        $connection = $this->newConnection();
        $connection->statement('alter table "foo" rename column "password" to "secret"');

        $user = new class(['id' => 3, 'secret' => (new BcryptHasher(['rounds' => 4]))->make('plain')]) extends GenericUser {
            /**
             * Get the name of the password attribute for the user.
             */
            public function getAuthPasswordName(): string
            {
                return 'secret';
            }
        };

        $hasher = new BcryptHasher(['rounds' => 5]);
        $provider = new DatabaseUserProvider($connection, $hasher, 'foo');
        $provider->rehashPasswordIfRequired($user, ['password' => 'plain']);

        $hash = $connection->table('foo')->where('id', 3)->value('secret');
        $this->assertTrue($hasher->check('plain', $hash));
        $this->assertSame($hash, $user->getAuthPassword());
    }

    public function testDontRehashPasswordIfNotRequired(): void
    {
        $hasher = new BcryptHasher(['rounds' => 5]);
        $connection = $this->newConnection();
        $connection->table('foo')->where('id', 3)->update(['password' => $hash = $hasher->make('plain')]);

        $provider = new DatabaseUserProvider($connection, $hasher, 'foo');
        $provider->rehashPasswordIfRequired($provider->retrieveById(3), ['password' => 'plain']);

        $this->assertSame($hash, $connection->table('foo')->where('id', 3)->value('password'));
    }

    /**
     * Create an in-memory connection with seeded users.
     */
    protected function newConnection(): SQLiteConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('create table "foo" ("id" integer primary key, "username" text, "group" text, "name" text, "password" text, "remember_token" text)');
        $pdo->exec("insert into \"foo\" values (1, 'dayle', 'three', 'other', null, null)");
        $pdo->exec("insert into \"foo\" values (2, 'sam', 'one', 'third', null, null)");
        $pdo->exec("insert into \"foo\" values (3, 'dayle', 'one', 'taylor', null, 'a')");

        return new SQLiteConnection($pdo);
    }

    /**
     * Create a provider backed by the seeded connection.
     */
    protected function newProvider(): DatabaseUserProvider
    {
        return new DatabaseUserProvider($this->newConnection(), new BcryptHasher, 'foo');
    }
}
