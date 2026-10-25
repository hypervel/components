<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Auth;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Contracts\OAuthAuthenticator;
use Hypervel\Saloon\Data\OAuthAccountIdentity;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\OAuthAccount;
use Hypervel\Saloon\OAuthAccounts;
use SensitiveParameter;

class OAuthAccountAuthenticator implements Authenticator
{
    protected readonly OAuthAccountIdentity $identity;

    /**
     * Capture the persisted account identity and its renewal callback.
     *
     * @param Closure(OAuthAuthenticator): OAuthAuthenticator $refreshUsing
     */
    public function __construct(#[SensitiveParameter] OAuthAccount $account, protected readonly Closure $refreshUsing)
    {
        $this->identity = Container::getInstance()->make(OAuthAccounts::class)->identity($account);
    }

    /**
     * Apply current credentials without retaining a private token copy.
     */
    public function set(PendingRequest $pendingRequest): void
    {
        $accounts = Container::getInstance()->make(OAuthAccounts::class);
        $token = $accounts->token($this->identity, $this->refreshUsing);
        $accounts->ensurePartition($this->identity);
        $token->set($pendingRequest);
    }
}
