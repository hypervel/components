<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Tests\TestCase;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class DatabaseEloquentRelationNoConstraintsTest extends TestCase
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
     * Create the test schema.
     */
    protected function createSchema(): void
    {
        DB::schema()->create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        DB::schema()->create('tokens', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('tokenable_type');
            $table->unsignedInteger('tokenable_id');
            $table->string('token');
        });
    }

    protected function tearDown(): void
    {
        NoConstraintsTestUser::$selectedToken = null;

        DB::schema()->drop('users');
        DB::schema()->drop('tokens');

        parent::tearDown();
    }

    public function testRelationshipAccessWhileResolvingEagerLoadPreservesConstraints(): void
    {
        $admin = NoConstraintsTestUser::create(['name' => 'Admin User']);
        $normalUser = NoConstraintsTestUser::create(['name' => 'Normal User']);

        NoConstraintsTestUser::$selectedToken = NoConstraintsTestToken::create([
            'tokenable_type' => NoConstraintsTestUser::class,
            'tokenable_id' => $normalUser->id,
            'token' => 'secret-token',
        ]);

        $users = NoConstraintsTestUser::with('selectedUserTokens')->get();

        $this->assertCount(0, $users->find($admin->id)->selectedUserTokens);
        $this->assertTrue(NoConstraintsTestUser::$selectedToken->is(
            $users->find($normalUser->id)->selectedUserTokens->sole()
        ));

        NoConstraintsTestUser::$selectedToken->unsetRelation('tokenable');

        $this->assertSame([$normalUser->id], NoConstraintsTestUser::whereHas('selectedUserTokens')->modelKeys());
    }

    public function testRelationshipAccessInsideExplicitNoConstraintsRemainsUnconstrained(): void
    {
        $admin = NoConstraintsTestUser::create(['name' => 'Admin User']);
        $normalUser = NoConstraintsTestUser::create(['name' => 'Normal User']);

        $token = NoConstraintsTestToken::create([
            'tokenable_type' => NoConstraintsTestUser::class,
            'tokenable_id' => $normalUser->id,
            'token' => 'secret-token',
        ]);

        $resolvedUser = Relation::noConstraints(fn (): ?Model => $token->tokenable);

        $this->assertTrue($admin->is($resolvedUser));
    }

    public function testConstraintScopesAreIsolatedBetweenCoroutines(): void
    {
        $results = parallel([
            fn (): bool => Relation::noConstraintsForRelation(function (): bool {
                usleep(5000);

                return Relation::withConstraintsForNestedRelation(Relation::shouldAddConstraints(...));
            }),
            fn (): bool => Relation::noConstraints(function (): bool {
                usleep(10000);

                return Relation::withConstraintsForNestedRelation(Relation::shouldAddConstraints(...));
            }),
        ]);

        $this->assertSame([true, false], $results);
        $this->assertTrue(Relation::shouldAddConstraints());
    }

    public function testConstraintScopesAreRestoredAfterAnException(): void
    {
        $exception = new RuntimeException('Nested relation failed.');

        Relation::noConstraints(function () use ($exception): void {
            try {
                Relation::noConstraintsForRelation(fn (): never => Relation::withConstraintsForNestedRelation(function () use ($exception): never {
                    $this->assertTrue(Relation::shouldAddConstraints());

                    throw $exception;
                }));

                $this->fail('Expected the nested relation to throw.');
            } catch (RuntimeException $caught) {
                $this->assertSame($exception, $caught);
            }

            $this->assertFalse(Relation::shouldAddConstraints());
            $this->assertFalse(Relation::withConstraintsForNestedRelation(Relation::shouldAddConstraints(...)));
        });

        $this->assertTrue(Relation::shouldAddConstraints());
    }
}

class NoConstraintsTestUser extends Model
{
    public static ?NoConstraintsTestToken $selectedToken = null;

    public bool $timestamps = false;

    protected ?string $table = 'users';

    protected array $guarded = [];

    /**
     * Get the selected user's tokens.
     */
    public function selectedUserTokens(): HasMany
    {
        return $this->hasMany(NoConstraintsTestToken::class, 'tokenable_id')
            ->where('tokenable_id', static::$selectedToken->tokenable->getKey());
    }
}

class NoConstraintsTestToken extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'tokens';

    protected array $guarded = [];

    /**
     * Get the token's owner.
     */
    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }
}
