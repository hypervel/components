<?php

declare(strict_types=1);

namespace Hypervel\Passport\Concerns;

use Closure;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\UniqueConstraintViolationException;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use RuntimeException;

trait PersistsTokens
{
    /**
     * Persist a credential, translating collisions that League can retry.
     *
     * @param list<string> $columns
     * @param null|Closure(): void $persist
     */
    protected function persistToken(Model $token, array $columns = [], ?Closure $persist = null): void
    {
        try {
            if ($persist !== null) {
                $token->getConnection()->transaction($persist);
            } else {
                $token->newQuery()->withSavepointIfNeeded(fn () => $this->saveToken($token));
            }
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isTokenIdentifierCollision($token, $exception, $columns ?: [$token->getKeyName()])) {
                throw $exception;
            }

            throw UniqueTokenIdentifierConstraintViolationException::create();
        }
    }

    /**
     * Save a credential or fail when a model observer cancels persistence.
     */
    protected function saveToken(Model $token): void
    {
        if (! $token->save()) {
            throw new RuntimeException('Credential persistence was canceled.');
        }
    }

    /**
     * Determine whether the failed insert collided on a regenerated identifier.
     *
     * @param list<string> $columns
     */
    protected function isTokenIdentifierCollision(Model $token, UniqueConstraintViolationException $exception, array $columns): bool
    {
        $connection = $token->getConnection();
        $table = $connection->getQueryGrammar()->wrapTable($token->getTable());

        // A family insert or model observer can fail on an identically named column.
        if (! str_starts_with($exception->getSql(), 'insert into ' . $table . ' ')) {
            return false;
        }

        $failedColumns = $exception->columns;

        if ($failedColumns === [] && $exception->index !== null) {
            // Schema inspection normalizes index names to lowercase.
            $indexName = strtolower($exception->index);

            foreach ($connection->getSchemaBuilder()->getIndexes($token->getTable()) as $index) {
                if ($index['name'] === $indexName) {
                    $failedColumns = $index['columns'];

                    break;
                }
            }
        }

        return array_intersect($columns, $failedColumns) !== [];
    }
}
