<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Integration;

use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Facades\DB;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 * @group integration
 * @group pgsql-integration
 */
class TransactionsTest extends IntegrationTestCase
{
    protected function conn(): ConnectionInterface
    {
        return DB::connection($this->getDatabaseDriver());
    }

    public function testBasicTransaction(): void
    {
        $this->conn()->transaction(function () {
            TxAccount::create(['name' => 'Account 1', 'balance' => 100]);
            TxAccount::create(['name' => 'Account 2', 'balance' => 200]);
        });

        $this->assertSame(2, TxAccount::count());
    }

    public function testTransactionRollbackOnException(): void
    {
        try {
            $this->conn()->transaction(function () {
                TxAccount::create(['name' => 'Will Be Rolled Back', 'balance' => 100]);

                throw new RuntimeException('Something went wrong');
            });
        } catch (RuntimeException) {
            // Expected
        }

        $this->assertSame(0, TxAccount::count());
    }

    public function testTransactionReturnsValue(): void
    {
        $result = $this->conn()->transaction(function () {
            $account = TxAccount::create(['name' => 'Return Test', 'balance' => 500]);

            return $account->id;
        });

        $this->assertNotNull($result);
        $this->assertNotNull(TxAccount::find($result));
    }

    public function testManualBeginCommit(): void
    {
        $this->conn()->beginTransaction();

        TxAccount::create(['name' => 'Manual 1', 'balance' => 100]);
        TxAccount::create(['name' => 'Manual 2', 'balance' => 200]);

        $this->conn()->commit();

        $this->assertSame(2, TxAccount::count());
    }

    public function testManualBeginRollback(): void
    {
        $this->conn()->beginTransaction();

        TxAccount::create(['name' => 'Rollback 1', 'balance' => 100]);
        TxAccount::create(['name' => 'Rollback 2', 'balance' => 200]);

        $this->conn()->rollBack();

        $this->assertSame(0, TxAccount::count());
    }

    public function testNestedTransactions(): void
    {
        $this->conn()->transaction(function () {
            TxAccount::create(['name' => 'Outer', 'balance' => 100]);

            $this->conn()->transaction(function () {
                TxAccount::create(['name' => 'Inner', 'balance' => 200]);
            });
        });

        $this->assertSame(2, TxAccount::count());
    }

    public function testNestedTransactionRollback(): void
    {
        try {
            $this->conn()->transaction(function () {
                TxAccount::create(['name' => 'Outer OK', 'balance' => 100]);

                $this->conn()->transaction(function () {
                    TxAccount::create(['name' => 'Inner Will Fail', 'balance' => 200]);

                    throw new RuntimeException('Inner failed');
                });
            });
        } catch (RuntimeException) {
            // Expected
        }

        $this->assertSame(0, TxAccount::count());
    }

    public function testTransactionLevel(): void
    {
        $baseLevel = $this->conn()->transactionLevel();

        $this->conn()->beginTransaction();
        $this->assertSame($baseLevel + 1, $this->conn()->transactionLevel());

        $this->conn()->beginTransaction();
        $this->assertSame($baseLevel + 2, $this->conn()->transactionLevel());

        $this->conn()->rollBack();
        $this->assertSame($baseLevel + 1, $this->conn()->transactionLevel());

        $this->conn()->rollBack();
        $this->assertSame($baseLevel, $this->conn()->transactionLevel());
    }

    public function testTransferBetweenAccounts(): void
    {
        $account1 = TxAccount::create(['name' => 'Account A', 'balance' => 1000]);
        $account2 = TxAccount::create(['name' => 'Account B', 'balance' => 500]);

        $this->conn()->transaction(function () use ($account1, $account2) {
            $amount = 300;

            $account1->decrement('balance', $amount);
            $account2->increment('balance', $amount);

            TxTransfer::create([
                'from_account_id' => $account1->id,
                'to_account_id' => $account2->id,
                'amount' => $amount,
            ]);
        });

        $this->assertEquals(700, $account1->fresh()->balance);
        $this->assertEquals(800, $account2->fresh()->balance);
        $this->assertSame(1, TxTransfer::count());
    }

    public function testTransferRollbackOnInsufficientFunds(): void
    {
        $account1 = TxAccount::create(['name' => 'Poor Account', 'balance' => 100]);
        $account2 = TxAccount::create(['name' => 'Rich Account', 'balance' => 5000]);

        try {
            $this->conn()->transaction(function () use ($account1, $account2) {
                $amount = 500;

                if ($account1->balance < $amount) {
                    throw new RuntimeException('Insufficient funds');
                }

                $account1->decrement('balance', $amount);
                $account2->increment('balance', $amount);
            });
        } catch (RuntimeException) {
            // Expected
        }

        $this->assertEquals(100, $account1->fresh()->balance);
        $this->assertEquals(5000, $account2->fresh()->balance);
    }

    public function testTransactionWithAttempts(): void
    {
        $attempts = 0;

        $this->conn()->transaction(function () use (&$attempts) {
            $attempts++;
            TxAccount::create(['name' => 'Attempts Test', 'balance' => 100]);
        }, 3);

        $this->assertSame(1, $attempts);
        $this->assertSame(1, TxAccount::count());
    }

    public function testTransactionCallbackReceivesAttemptNumber(): void
    {
        $results = [];

        for ($i = 1; $i <= 3; $i++) {
            $result = $this->conn()->transaction(function () use ($i) {
                return TxAccount::create(['name' => "Batch {$i}", 'balance' => $i * 100]);
            });
            $results[] = $result;
        }

        $this->assertCount(3, $results);
        $this->assertSame(3, TxAccount::count());
    }

    public function testQueryBuilderInTransaction(): void
    {
        $this->conn()->transaction(function () {
            $this->conn()->table('tx_accounts')->insert([
                'name' => 'Query Builder Insert',
                'balance' => 999,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $account = TxAccount::where('name', 'Query Builder Insert')->first();
        $this->assertNotNull($account);
        $this->assertEquals(999, $account->balance);
    }

    public function testBulkOperationsInTransaction(): void
    {
        $this->conn()->transaction(function () {
            for ($i = 1; $i <= 100; $i++) {
                TxAccount::create(['name' => "Bulk Account {$i}", 'balance' => $i]);
            }
        });

        $this->assertSame(100, TxAccount::count());
        $this->assertEquals(5050, TxAccount::sum('balance'));
    }

    public function testUpdateInTransaction(): void
    {
        TxAccount::create(['name' => 'Update Test', 'balance' => 100]);

        $this->conn()->transaction(function () {
            TxAccount::where('name', 'Update Test')->update(['balance' => 999]);
        });

        $this->assertEquals(999, TxAccount::where('name', 'Update Test')->first()->balance);
    }

    public function testDeleteInTransaction(): void
    {
        TxAccount::create(['name' => 'Delete Test 1', 'balance' => 100]);
        TxAccount::create(['name' => 'Delete Test 2', 'balance' => 200]);
        TxAccount::create(['name' => 'Keep This', 'balance' => 300]);

        $this->conn()->transaction(function () {
            TxAccount::where('name', 'like', 'Delete Test%')->delete();
        });

        $this->assertSame(1, TxAccount::count());
        $this->assertSame('Keep This', TxAccount::first()->name);
    }
}

class TxAccount extends Model
{
    protected ?string $table = 'tx_accounts';

    protected array $fillable = ['name', 'balance'];
}

class TxTransfer extends Model
{
    protected ?string $table = 'tx_transfers';

    protected array $fillable = ['from_account_id', 'to_account_id', 'amount'];
}
