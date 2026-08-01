<?php

declare(strict_types=1);

namespace Hypervel\Permission;

use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Eloquent\MissingAttributeException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Contracts\PermissionsTeamResolver;

final class DefaultTeamResolver implements PermissionsTeamResolver
{
    public const TEAM_ID_CONTEXT_KEY = '__permission.team_id';

    /**
     * Set the current permissions team id.
     */
    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        if ($id instanceof Model) {
            $model = $id;
            $id = $model->getKey();

            if ($id === null) {
                throw new MissingAttributeException($model, $model->getKeyName());
            }
        }

        CoroutineContext::set(self::TEAM_ID_CONTEXT_KEY, $id);
    }

    /**
     * Get the current permissions team id.
     */
    public function getPermissionsTeamId(): int|string|null
    {
        return CoroutineContext::get(self::TEAM_ID_CONTEXT_KEY);
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        CoroutineContext::forget(self::TEAM_ID_CONTEXT_KEY);
    }
}
