<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\Builder;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use RuntimeException;

class DatabaseEloquentTimestampsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $db = new DB;

        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $db->bootEloquent();
        $db->setAsGlobal();

        $this->createSchema();
    }

    /**
     * Set up the database schema.
     */
    public function createSchema(): void
    {
        $this->schema()->create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email')->unique();
            $table->timestamps();
        });

        $this->schema()->create('users_created_at', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email')->unique();
            $table->string('created_at');
        });

        $this->schema()->create('users_updated_at', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email')->unique();
            $table->string('updated_at');
        });
    }

    /**
     * Tear down the database schema.
     */
    protected function tearDown(): void
    {
        $this->schema()->drop('users');
        $this->schema()->drop('users_created_at');
        $this->schema()->drop('users_updated_at');

        parent::tearDown();
    }

    public function testUserWithCreatedAtAndUpdatedAt(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $user = UserWithCreatedAndUpdated::create([
            'email' => 'test@test.com',
        ]);

        $this->assertSame($now->toDateTimeString(), $user->created_at->toDateTimeString());
        $this->assertSame($now->toDateTimeString(), $user->updated_at->toDateTimeString());
    }

    public function testUserWithCreatedAt(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $user = UserWithCreated::create([
            'email' => 'test@test.com',
        ]);

        $this->assertSame($now->toDateTimeString(), $user->created_at->toDateTimeString());
    }

    public function testUserWithUpdatedAt(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $user = UserWithUpdated::create([
            'email' => 'test@test.com',
        ]);

        $this->assertSame($now->toDateTimeString(), $user->updated_at->toDateTimeString());
    }

    public function testWithoutTimestamp(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now()->setYear(1995)->startOfYear());
        $user = UserWithCreatedAndUpdated::create(['email' => 'foo@example.com']);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());

        $this->assertTrue($user->usesTimestamps());

        $user->withoutTimestamps(function () use ($user): void {
            $this->assertFalse($user->usesTimestamps());

            $user->withoutTimestamps(function () use ($user): void {
                $this->assertFalse($user->usesTimestamps());
            });

            $this->assertFalse($user->usesTimestamps());
            $user->update([
                'email' => 'bar@example.com',
            ]);
        });

        $this->assertTrue($user->usesTimestamps());
        $this->assertTrue($now->equalTo($user->updated_at));
        $this->assertSame('bar@example.com', $user->email);
    }

    public function testWithoutTimestampWhenAlreadyIgnoringTimestamps(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now()->setYear(1995)->startOfYear());
        $user = UserWithCreatedAndUpdated::create(['email' => 'foo@example.com']);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());

        $user->timestamps = false;

        $this->assertFalse($user->usesTimestamps());

        $user->withoutTimestamps(function () use ($user): void {
            $this->assertFalse($user->usesTimestamps());
            $user->update([
                'email' => 'bar@example.com',
            ]);
        });

        $this->assertFalse($user->usesTimestamps());
        $this->assertTrue($now->equalTo($user->updated_at));
        $this->assertSame('bar@example.com', $user->email);
    }

    public function testWithoutTimestampRestoresWhenClosureThrowsException(): void
    {
        $user = UserWithCreatedAndUpdated::create(['email' => 'foo@example.com']);

        $user->timestamps = true;
        $expectedException = new RuntimeException;
        $usedTimestamps = null;
        $caughtException = null;

        try {
            $user->withoutTimestamps(function () use ($expectedException, $user, &$usedTimestamps): never {
                $usedTimestamps = $user->usesTimestamps();

                throw $expectedException;
            });
        } catch (RuntimeException $exception) {
            $caughtException = $exception;
        }

        $this->assertSame($expectedException, $caughtException);
        $this->assertFalse($usedTimestamps);
        $this->assertTrue($user->timestamps);
    }

    public function testWithoutTimestampsRespectsClasses(): void
    {
        $a = new UserWithCreatedAndUpdated;
        $b = new UserWithCreatedAndUpdated;
        $z = new UserWithUpdated;

        $this->assertTrue($a->usesTimestamps());
        $this->assertTrue($b->usesTimestamps());
        $this->assertTrue($z->usesTimestamps());
        $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithCreatedAndUpdated::class));
        $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithUpdated::class));

        Eloquent::withoutTimestamps(function () use ($a, $b, $z): void {
            $this->assertFalse($a->usesTimestamps());
            $this->assertFalse($b->usesTimestamps());
            $this->assertFalse($z->usesTimestamps());
            $this->assertTrue(Eloquent::isIgnoringTimestamps(UserWithCreatedAndUpdated::class));
            $this->assertTrue(Eloquent::isIgnoringTimestamps(UserWithUpdated::class));
        });

        $this->assertTrue($a->usesTimestamps());
        $this->assertTrue($b->usesTimestamps());
        $this->assertTrue($z->usesTimestamps());
        $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithCreatedAndUpdated::class));
        $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithUpdated::class));

        UserWithCreatedAndUpdated::withoutTimestamps(function () use ($a, $b, $z): void {
            $this->assertFalse($a->usesTimestamps());
            $this->assertFalse($b->usesTimestamps());
            $this->assertTrue($z->usesTimestamps());
            $this->assertTrue(Eloquent::isIgnoringTimestamps(UserWithCreatedAndUpdated::class));
            $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithUpdated::class));
        });

        $this->assertTrue($a->usesTimestamps());
        $this->assertTrue($b->usesTimestamps());
        $this->assertTrue($z->usesTimestamps());
        $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithCreatedAndUpdated::class));
        $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithUpdated::class));

        UserWithUpdated::withoutTimestamps(function () use ($a, $b, $z): void {
            $this->assertTrue($a->usesTimestamps());
            $this->assertTrue($b->usesTimestamps());
            $this->assertFalse($z->usesTimestamps());
            $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithCreatedAndUpdated::class));
            $this->assertTrue(Eloquent::isIgnoringTimestamps(UserWithUpdated::class));
        });

        $this->assertTrue($a->usesTimestamps());
        $this->assertTrue($b->usesTimestamps());
        $this->assertTrue($z->usesTimestamps());
        $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithCreatedAndUpdated::class));
        $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithUpdated::class));

        Eloquent::withoutTimestampsOn([], function () use ($a, $b, $z): void {
            $this->assertTrue($a->usesTimestamps());
            $this->assertTrue($b->usesTimestamps());
            $this->assertTrue($z->usesTimestamps());
            $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithCreatedAndUpdated::class));
            $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithUpdated::class));
        });

        $this->assertTrue($a->usesTimestamps());
        $this->assertTrue($b->usesTimestamps());
        $this->assertTrue($z->usesTimestamps());
        $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithCreatedAndUpdated::class));
        $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithUpdated::class));

        Eloquent::withoutTimestampsOn([UserWithCreatedAndUpdated::class], function () use ($a, $b, $z): void {
            $this->assertFalse($a->usesTimestamps());
            $this->assertFalse($b->usesTimestamps());
            $this->assertTrue($z->usesTimestamps());
            $this->assertTrue(Eloquent::isIgnoringTimestamps(UserWithCreatedAndUpdated::class));
            $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithUpdated::class));
        });

        $this->assertTrue($a->usesTimestamps());
        $this->assertTrue($b->usesTimestamps());
        $this->assertTrue($z->usesTimestamps());
        $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithCreatedAndUpdated::class));
        $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithUpdated::class));

        Eloquent::withoutTimestampsOn([UserWithUpdated::class], function () use ($a, $b, $z): void {
            $this->assertTrue($a->usesTimestamps());
            $this->assertTrue($b->usesTimestamps());
            $this->assertFalse($z->usesTimestamps());
            $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithCreatedAndUpdated::class));
            $this->assertTrue(Eloquent::isIgnoringTimestamps(UserWithUpdated::class));
        });

        $this->assertTrue($a->usesTimestamps());
        $this->assertTrue($b->usesTimestamps());
        $this->assertTrue($z->usesTimestamps());
        $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithCreatedAndUpdated::class));
        $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithUpdated::class));

        Eloquent::withoutTimestampsOn([UserWithCreatedAndUpdated::class, UserWithUpdated::class], function () use ($a, $b, $z): void {
            $this->assertFalse($a->usesTimestamps());
            $this->assertFalse($b->usesTimestamps());
            $this->assertFalse($z->usesTimestamps());
            $this->assertTrue(Eloquent::isIgnoringTimestamps(UserWithCreatedAndUpdated::class));
            $this->assertTrue(Eloquent::isIgnoringTimestamps(UserWithUpdated::class));
        });

        $this->assertTrue($a->usesTimestamps());
        $this->assertTrue($b->usesTimestamps());
        $this->assertTrue($z->usesTimestamps());
        $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithCreatedAndUpdated::class));
        $this->assertFalse(Eloquent::isIgnoringTimestamps(UserWithUpdated::class));
    }

    /**
     * Get a database connection instance.
     */
    protected function connection(): ConnectionInterface
    {
        return Eloquent::getConnectionResolver()->connection();
    }

    /**
     * Get a schema builder instance.
     */
    protected function schema(): Builder
    {
        return $this->connection()->getSchemaBuilder();
    }
}

// Eloquent models.
class UserWithCreatedAndUpdated extends Eloquent
{
    protected ?string $table = 'users';

    protected array $guarded = [];
}

class UserWithCreated extends Eloquent
{
    public const ?string UPDATED_AT = null;

    protected ?string $table = 'users_created_at';

    protected array $guarded = [];

    protected ?string $dateFormat = 'U';
}

class UserWithUpdated extends Eloquent
{
    public const ?string CREATED_AT = null;

    protected ?string $table = 'users_updated_at';

    protected array $guarded = [];

    protected ?string $dateFormat = 'U';
}
