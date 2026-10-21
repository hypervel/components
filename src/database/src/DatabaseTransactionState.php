<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\NonCopyableContext;
use Hypervel\Support\Collection;

class DatabaseTransactionState implements NonCopyableContext
{
    public const string CONTEXT_KEY = '__database.transactions';

    /** @var Collection<int, DatabaseTransactionRecord> */
    public Collection $committed;

    /** @var Collection<int, DatabaseTransactionRecord> */
    public Collection $pending;

    /** @var array<string, null|DatabaseTransactionRecord> */
    public array $current = [];

    /**
     * Create transaction state owned by one execution.
     */
    public function __construct()
    {
        $this->committed = new Collection;
        $this->pending = new Collection;
    }

    /**
     * Get the current execution's transaction state.
     */
    public static function current(): self
    {
        return CoroutineContext::get(self::CONTEXT_KEY)
            ?? CoroutineContext::set(self::CONTEXT_KEY, new self);
    }
}
