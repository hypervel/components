<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\ManagedTransactions;

use Closure;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\PdoConnection;
use Hypervel\Support\Facades\DB;

class Ledger
{
    public function commit(): void
    {
    }
}

class RetryingConnection extends PdoConnection
{
    public function transaction(Closure $callback, int $attempts = 1): mixed
    {
        return $callback($this);
    }
}

class AuditedConnection extends PdoConnection
{
    public function commit(): void
    {
    }
}

class TenantDB extends DB
{
    public static function connection(?string $name = null): PdoConnection
    {
        return parent::connection($name);
    }

    public static function transaction(Closure $callback, int $attempts = 1): mixed
    {
        return $callback();
    }
}

class Transfers
{
    public ConnectionInterface $current {
        get => $this->connection;
    }

    public function __construct(
        private ConnectionInterface $connection,
        private Ledger $ledger,
    ) {
    }

    public function endedEarly(): void
    {
        DB::transaction(function () {
            DB::commit();
        });
        DB::transaction(fn () => DB::rollBack());
        DB::transaction(function (ConnectionInterface $connection) {
            $connection->rollBack(null);
        });
        DB::connection('mysql')->transaction(function () {
            DB::connection('mysql')->commit();
        });

        $connection = $this->connection;
        $connection->transaction(function () use ($connection) {
            $connection->commit();
        });
        DB::transaction(function () {
            $this->ledger->commit();
            DB::commit();
        });
    }

    public function rollsBackToZero(): void
    {
        DB::transaction(function () {
            DB::beginTransaction();
            DB::rollBack(0);
        });
        DB::transaction(function () {
            DB::rollBack(toLevel: 0);
        });
    }

    public function balanced(): void
    {
        DB::transaction(function () {
            DB::beginTransaction();
            DB::beginTransaction();
            DB::commit();
            DB::rollBack();
            $this->ledger->commit();
        });
        DB::transaction(function () {
            DB::beginTransaction();
            DB::rollBack(2);
            DB::commit();
        });
    }

    public function uncertain(bool $retry): void
    {
        $connection = $this->connection;

        DB::transaction(function () use ($retry) {
            if ($retry) {
                DB::beginTransaction();
            }

            DB::commit();
        });
        DB::transaction(function () {
            return true;

            DB::commit();
        });
        DB::transaction(function () {
            DB::connection('mysql')->commit();
            DB::commit();
        });
        $connection->transaction(function () use ($connection) {
            $connection = DB::connection('other');
            $connection->commit();
        });
        DB::transaction(function () use ($retry) {
            $retry ? DB::commit() : DB::rollBack();
        });
        DB::transaction(function () {
            DB::afterCommit(function () {
                DB::commit();
            });
        });
    }

    public function overrides(PdoConnection $inherited, RetryingConnection $retrying, AuditedConnection $audited): void
    {
        $inherited->transaction(function (PdoConnection $connection) {
            $connection->commit();
        });
        $retrying->transaction(function (PdoConnection $connection) {
            $connection->commit();
        });
        $audited->transaction(function (AuditedConnection $connection) {
            $connection->commit();
        });
        TenantDB::transaction(function () {
            DB::commit();
        });
        TenantDB::connection('mysql')->transaction(function () {
            TenantDB::connection('mysql')->commit();
        });
    }

    public function properties(): void
    {
        $this->connection->transaction(function () {
            $this->connection->commit();
        });
        $this->connection->transaction(fn () => $this->connection->rollBack());
        $this->connection->transaction(function () {
            $this->connection = DB::connection('other');
            $this->connection->commit();
        });
        $this->connection->transaction(function () {
            [$this->connection] = [DB::connection('other')];
            $this->connection->commit();
        });
        $this->current->transaction(function () {
            $this->current->commit();
        });
    }

    public function destructuring(): void
    {
        $connection = $this->connection;

        $connection->transaction(function () use ($connection) {
            [$connection] = [DB::connection('other')];
            $connection->commit();
        });
        DB::transaction(function (ConnectionInterface $connection) {
            ['main' => $connection] = ['main' => DB::connection('other')];
            $connection->commit();
        });
    }

    public function generators(RetryingConnection $retrying): void
    {
        DB::transaction(function () {
            yield DB::table('transfers')->get();
        });
        $this->connection->transaction(fn () => yield DB::table('transfers')->count());
        DB::transaction(function () {
            return (function () {
                yield DB::table('transfers')->get();
            })();
        });
        $retrying->transaction(function () {
            yield DB::table('transfers')->get();
        });
    }
}
