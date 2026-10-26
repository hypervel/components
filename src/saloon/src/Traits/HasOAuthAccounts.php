<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Saloon\OAuthAccount;
use Hypervel\Saloon\OAuthAccounts;

/**
 * @phpstan-require-extends Model
 */
trait HasOAuthAccounts
{
    /**
     * Get the owner's connected OAuth accounts.
     *
     * @return MorphMany<OAuthAccount, $this>
     */
    public function oauthAccounts(): MorphMany
    {
        return $this->morphMany(OAuthAccounts::accountModel(), 'owner');
    }
}
