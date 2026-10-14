<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use Hypervel\Database\Eloquent\Model;
use RuntimeException;

trait PersistsTokens
{
    /**
     * Save a credential or fail when a model observer cancels persistence.
     */
    protected function saveToken(Model $token): void
    {
        if (! $token->save()) {
            throw new RuntimeException('Credential persistence was canceled.');
        }
    }
}
