<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Data;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Saloon\OAuthAccount;

final readonly class OAuthAccountIdentity
{
    public string $key;

    /**
     * Capture the storage identity without retaining a mutable account.
     *
     * @param class-string<OAuthAccount> $model
     */
    public function __construct(
        public string $model,
        public string $connection,
        public string $table,
        public int|string $id,
        public ?string $partitionColumn,
        public ?string $partition,
    ) {
        $this->key = hash('sha256', serialize([$model, $connection, $table, (string) $id, $partitionColumn, $partition]));
    }

    /**
     * Create a source model on the captured connection and table.
     */
    public function newModel(): OAuthAccount
    {
        return (new $this->model)->setConnection($this->connection)->setTable($this->table);
    }

    /**
     * Start an account query with its model scopes and partition intact.
     *
     * @return Builder<OAuthAccount>
     */
    public function query(): Builder
    {
        $query = $this->newModel()->newQuery()->whereKey($this->id);

        if ($this->partitionColumn !== null) {
            $query->where($this->partitionColumn, $this->partition);
        }

        return $query;
    }
}
