<?php

declare(strict_types=1);

namespace Hypervel\Saloon;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Saloon\Contracts\OAuthAuthenticator;
use Hypervel\Saloon\Http\Auth\AccessTokenAuthenticator;
use Hypervel\Saloon\Http\Auth\OAuthAccountAuthenticator;
use Hypervel\Saloon\Http\Auth\TokenExpiry;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;

/**
 * @property string $access_token
 * @property ?string $refresh_token
 * @property ?CarbonImmutable $expires_at
 * @property ?CarbonImmutable $refresh_at
 * @property null|list<string> $scopes
 * @property string $credential_revision
 * @property ?string $refresh_claim
 * @property ?CarbonImmutable $refresh_started_at
 */
class OAuthAccount extends Model
{
    use HasUuids;

    protected ?string $table = 'oauth_accounts';

    protected array $fillable = [
        'provider',
        'provider_account_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
    ];

    protected array $hidden = ['access_token', 'refresh_token'];

    protected array $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'immutable_datetime',
        'refresh_at' => 'immutable_datetime',
        'refresh_started_at' => 'immutable_datetime',
        'scopes' => 'array',
    ];

    /**
     * Register credential revisions and committed cache invalidation.
     */
    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            $accounts = Container::getInstance()->make(OAuthAccounts::class);

            $accounts->prepareAccount($account);

            if (! $account->exists || $account->isDirty(['access_token', 'refresh_token', 'expires_at', 'scopes'])) {
                $account->credential_revision = (string) Str::uuid();
                $account->refresh_claim = null;
                $account->refresh_started_at = null;
            }

            if (! $account->exists || $account->isDirty(['access_token', 'expires_at'])) {
                $account->setAttribute('refresh_at', TokenExpiry::refreshAt($account->expires_at));
            }
        });

        static::saved(static fn (self $account) => $account->clearCredentialCache());
        static::deleting(static function (self $account): void {
            Container::getInstance()->make(OAuthAccounts::class)->identity($account);
        });
        static::deleted(static fn (self $account) => $account->clearCredentialCache());
    }

    /**
     * Get the account owner.
     */
    public function owner(): MorphTo
    {
        return $this->morphTo('owner');
    }

    /**
     * Create an authenticator from these credential values.
     */
    public function newOAuthAuthenticator(): OAuthAuthenticator
    {
        return new AccessTokenAuthenticator($this->access_token, $this->refresh_token, $this->expires_at, $this->scopes);
    }

    /**
     * Authenticate using the latest persisted credentials and renew them when needed.
     *
     * @param Closure(OAuthAuthenticator): OAuthAuthenticator $refreshUsing
     */
    public function authenticator(Closure $refreshUsing): OAuthAccountAuthenticator
    {
        return new OAuthAccountAuthenticator($this, $refreshUsing);
    }

    /**
     * Clear this account's cached credentials after the current transaction commits.
     */
    public function clearCredentialCache(): void
    {
        $accounts = Container::getInstance()->make(OAuthAccounts::class);
        $accounts->invalidate($accounts->identity($this));
    }
}
