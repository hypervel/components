<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Bus\Dispatchable;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Auth\User;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Factories\UserFactory;
use RuntimeException;

/**
 * Shared test methods for transaction afterCommit behavior.
 *
 * Tests that observer callbacks with $afterCommit = true are deferred
 * until after the database transaction commits.
 */
trait EloquentTransactionWithAfterCommitTests
{
    protected function setUpEloquentTransactionWithAfterCommitTests(): void
    {
        // Note: User::unguard() uses Context which is coroutine-local.
        // We wrap creates in User::unguarded() instead.
    }

    /**
     * Create the required database tables for these tests.
     */
    protected function createTransactionTestTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('remember_token', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function testObserverIsCalledOnTestsWithAfterCommit()
    {
        User::observe($observer = EloquentTransactionWithAfterCommitTestsUserObserver::resetting());

        $user1 = User::unguarded(fn () => User::create(UserFactory::new()->raw()));

        $this->assertTrue($user1->exists);
        $this->assertEquals(1, $observer::$calledTimes, 'Failed to assert the observer was called once.');
    }

    public function testObserverCalledWithAfterCommitWhenInsideTransaction()
    {
        User::observe($observer = EloquentTransactionWithAfterCommitTestsUserObserver::resetting());

        $user1 = DB::transaction(fn () => User::unguarded(fn () => User::create(UserFactory::new()->raw())));

        $this->assertTrue($user1->exists);
        $this->assertEquals(1, $observer::$calledTimes, 'Failed to assert the observer was called once.');
    }

    public function testObserverCalledWithAfterCommitWhenInsideTransactionWithDispatchSync()
    {
        User::observe($observer = EloquentTransactionWithAfterCommitTestsUserObserverUsingDispatchSync::resetting());

        $user1 = DB::transaction(fn () => User::unguarded(fn () => User::create(UserFactory::new()->raw())));

        $this->assertTrue($user1->exists);
        $this->assertEquals(1, $observer::$calledTimes, 'Failed to assert the observer was called once.');

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $user1->email,
            'token' => sha1($user1->email),
        ]);
    }

    public function testObserverIsCalledOnTestsWithAfterCommitWhenUsingSavepoint()
    {
        User::observe($observer = EloquentTransactionWithAfterCommitTestsUserObserver::resetting());

        $user1 = User::unguarded(fn () => User::createOrFirst(UserFactory::new()->raw()));

        $this->assertTrue($user1->exists);
        $this->assertEquals(1, $observer::$calledTimes, 'Failed to assert the observer was called once.');
    }

    public function testObserverIsCalledOnTestsWithAfterCommitWhenUsingSavepointAndInsideTransaction()
    {
        User::observe($observer = EloquentTransactionWithAfterCommitTestsUserObserver::resetting());

        $user1 = DB::transaction(fn () => User::unguarded(fn () => User::createOrFirst(UserFactory::new()->raw())));

        $this->assertTrue($user1->exists);
        $this->assertEquals(1, $observer::$calledTimes, 'Failed to assert the observer was called once.');
    }

    public function testObserverIsCalledEvenWhenDeeplyNestingTransactions()
    {
        User::observe($observer = EloquentTransactionWithAfterCommitTestsUserObserver::resetting());

        $user1 = DB::transaction(function () use ($observer) {
            return tap(DB::transaction(function () use ($observer) {
                return tap(DB::transaction(function () use ($observer) {
                    return tap(User::unguarded(fn () => User::createOrFirst(UserFactory::new()->raw())), function () use ($observer) {
                        $this->assertEquals(0, $observer::$calledTimes, 'Should not have been called');
                    });
                }), function () use ($observer) {
                    $this->assertEquals(0, $observer::$calledTimes, 'Should not have been called');
                });
            }), function () use ($observer) {
                $this->assertEquals(0, $observer::$calledTimes, 'Should not have been called');
            });
        });

        $this->assertTrue($user1->exists);
        $this->assertEquals(1, $observer::$calledTimes, 'Failed to assert the observer was called once.');
    }

    public function testTransactionCallbackExceptions()
    {
        [$firstObject, $secondObject] = [
            new EloquentTransactionWithAfterCommitTestsTestObjectForTransactions(),
            new EloquentTransactionWithAfterCommitTestsTestObjectForTransactions(),
        ];

        $rootTransactionLevel = DB::transactionLevel();

        // After commit callbacks may fail with an exception. When they do, the rest of the callbacks are not
        // executed. It's important that the transaction would already be committed by that point, so the
        // transaction level should be modified before executing any callbacks. Also, exceptions in the
        // callbacks should not affect the connection's transaction level.
        $this->expectException(RuntimeException::class);

        try {
            DB::transaction(function () use ($rootTransactionLevel, $firstObject, $secondObject) {
                DB::transaction(function () use ($rootTransactionLevel, $firstObject) {
                    $this->assertSame($rootTransactionLevel + 2, DB::transactionLevel());

                    DB::afterCommit(function () use ($rootTransactionLevel, $firstObject) {
                        $this->assertSame($rootTransactionLevel, DB::transactionLevel());

                        $firstObject->handle();
                    });
                });

                $this->assertSame($rootTransactionLevel + 1, DB::transactionLevel());

                DB::afterCommit(fn () => throw new RuntimeException());
                DB::afterCommit(fn () => $secondObject->handle());
            });
        } finally {
            $this->assertSame($rootTransactionLevel, DB::transactionLevel());
            $this->assertTrue($firstObject->ran);
            $this->assertFalse($secondObject->ran);
            $this->assertEquals(1, $firstObject->runs);
        }
    }
}

class EloquentTransactionWithAfterCommitTestsUserObserver
{
    public static int $calledTimes = 0;

    public bool $afterCommit = true;

    public static function resetting(): static
    {
        static::$calledTimes = 0;

        return new static();
    }

    public function created(User $user): void
    {
        ++static::$calledTimes;
    }
}

class EloquentTransactionWithAfterCommitTestsUserObserverUsingDispatchSync extends EloquentTransactionWithAfterCommitTestsUserObserver
{
    public function created(User $user): void
    {
        dispatch_sync(new EloquentTransactionWithAfterCommitTestsJob($user->email));

        parent::created($user);
    }
}

class EloquentTransactionWithAfterCommitTestsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public string $email
    ) {
    }

    public function handle(): void
    {
        DB::transaction(function () {
            DB::table('password_reset_tokens')->insert([
                ['email' => $this->email, 'token' => sha1($this->email), 'created_at' => now()],
            ]);
        });
    }
}

class EloquentTransactionWithAfterCommitTestsTestObjectForTransactions
{
    public bool $ran = false;

    public int $runs = 0;

    public function handle(): void
    {
        $this->ran = true;
        ++$this->runs;
    }
}
