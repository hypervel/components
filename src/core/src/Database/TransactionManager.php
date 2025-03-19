<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hyperf\Context\Context;
use Hyperf\Database\Events\TransactionCommitted;

class TransactionManager
{
    public function getCallbacks(?string $event = null): array
    {
        return Context::get('_db.transactions')[$this->getEvent($event)] ?? [];
    }

    public function addCallback(callable $callback, ?string $event = null): void
    {
        Context::override('_db.transactions', function (?array $transactions) use ($event, $callback) {
            $transactions = $transactions ?? [];
            $transactions[$this->getEvent($event)][] = $callback;

            return $transactions;
        });
    }

    public function clearCallbacks(?string $event): void
    {
        Context::override('_db.transactions', function (?array $transactions) use ($event) {
            $transactions = $transactions ?? [];
            $transactions[$this->getEvent($event)] = [];

            return $transactions;
        });
    }

    public function runCallbacks(?string $event = null): void
    {
        if (! $callbacks = $this->getCallbacks($this->getEvent($event))) {
            return;
        }

        foreach ($callbacks as $callback) {
            $callback();
        }

        $this->clearCallbacks($event);
    }

    public function getEvent(?string $event = null): string
    {
        return $event ?? TransactionCommitted::class;
    }
}
