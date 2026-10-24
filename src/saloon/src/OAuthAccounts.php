<?php

declare(strict_types=1);

namespace Hypervel\Saloon;

use Closure;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\ResponseException as GuzzleResponseException;
use GuzzleHttp\Exception\ResponseTransferException;
use Hypervel\Cache\ModelCacheCoordinator;
use Hypervel\Cache\ModelCacheStoreValidator;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\RequestException;
use Hypervel\Saloon\Contracts\OAuthAuthenticator;
use Hypervel\Saloon\Data\OAuthAccountIdentity;
use Hypervel\Saloon\Exceptions\OAuthAccountReconnectRequiredException;
use Hypervel\Saloon\Exceptions\Request\FatalRequestException;
use Hypervel\Saloon\Http\Auth\TokenAcquisition;
use Hypervel\Saloon\Http\Auth\TokenExpiry;
use Hypervel\Saloon\Http\RetryAfter;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Sleep;
use Hypervel\Support\Str;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SensitiveParameter;
use Swoole\Coroutine\CanceledException;
use Throwable;
use UnexpectedValueException;

class OAuthAccounts
{
    public const int DEFAULT_CACHE_TTL = 300;

    public const string DEFAULT_CACHE_PREFIX = 'saloon:oauth-accounts:';

    protected const string DEFAULT_ACCOUNT_MODEL = OAuthAccount::class;

    /** @var class-string<OAuthAccount> */
    protected static string $accountModel = self::DEFAULT_ACCOUNT_MODEL;

    protected static bool $initialized = false;

    protected static ?string $partitionColumn = null;

    /** @var null|Closure(): (null|int|string) */
    protected static ?Closure $partitionResolver = null;

    protected ?CacheRepository $cache = null;

    protected int $cacheTtl;

    protected ?CacheRepository $renewalCache = null;

    protected string $renewalStore;

    /**
     * Create the account service.
     */
    public function __construct(
        protected readonly ConfigRepository $config,
        protected readonly CacheFactory $cacheFactory,
        protected readonly ModelCacheCoordinator $cacheCoordinator,
        protected readonly ModelCacheStoreValidator $cacheValidator,
        protected readonly TokenAcquisition $acquisition,
        protected readonly ExceptionHandler $exceptions,
    ) {
    }

    /**
     * Select the account model.
     *
     * Boot-only. The model persists for the worker lifetime and affects every account operation.
     *
     * @param class-string<OAuthAccount> $model
     */
    public static function useAccountModel(string $model): void
    {
        if (static::$initialized) {
            throw new LogicException('Configure the OAuth account model before using accounts.');
        }

        if (! is_a($model, OAuthAccount::class, true)) {
            throw new InvalidArgumentException('The OAuth account model must extend ' . OAuthAccount::class . '.');
        }

        static::$accountModel = $model;
    }

    /**
     * Get the configured account model.
     *
     * @return class-string<OAuthAccount>
     */
    public static function accountModel(): string
    {
        return static::$accountModel;
    }

    /**
     * Configure the account partition resolver.
     *
     * Boot-only. The column and callback persist for the worker lifetime and affect every account operation.
     *
     * @param Closure(): (null|int|string) $resolver
     */
    public static function resolvePartitionUsing(string $column, Closure $resolver): void
    {
        if (static::$initialized || static::$partitionResolver !== null) {
            throw new LogicException('Configure the OAuth account partition once, before using accounts.');
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column) !== 1) {
            throw new InvalidArgumentException('The OAuth account partition column must be a simple SQL identifier.');
        }

        static::$partitionColumn = $column;
        static::$partitionResolver = $resolver;
    }

    /**
     * Resolve the current partition, rejecting missing configured context.
     */
    public function partition(): ?string
    {
        static::$initialized = true;

        if (static::$partitionResolver === null) {
            return null;
        }

        $partition = (static::$partitionResolver)();

        if ((! is_string($partition) && ! is_int($partition)) || $partition === '') {
            throw new LogicException('The current OAuth account partition is not available.');
        }

        return (string) $partition;
    }

    /**
     * Fill the partition of a new account without accepting another context's value.
     */
    public function prepareAccount(OAuthAccount $account): void
    {
        $partition = $this->partition();

        if (static::$partitionColumn === null) {
            return;
        }

        $supplied = $account->getAttribute(static::$partitionColumn);

        if ($supplied !== null && (string) $supplied !== $partition) {
            throw new LogicException('The OAuth account belongs to another partition.');
        }

        $account->setAttribute(static::$partitionColumn, $partition);
    }

    /**
     * Capture an account's persisted storage identity.
     */
    public function identity(OAuthAccount $account): OAuthAccountIdentity
    {
        $current = $this->partition();

        if (! $account->exists && $account->getRawOriginal($account->getKeyName()) === null) {
            throw new LogicException('Save the OAuth account before using its credentials.');
        }

        $partition = null;

        if (static::$partitionColumn !== null) {
            $original = $account->getRawOriginal();
            $value = $original[static::$partitionColumn]
                ?? ($account->wasRecentlyCreated ? $account->getAttribute(static::$partitionColumn) : null);

            if ((! is_int($value) && ! is_string($value)) || $value === '' || (string) $value !== $current) {
                throw new LogicException('The OAuth account belongs to another partition.');
            }

            $partition = (string) $value;
        }

        return new OAuthAccountIdentity(
            $account::class,
            $account->getConnectionName() ?? $account::getConnectionResolver()->getDefaultConnection(),
            $account->getTable(),
            $account->getKey(),
            static::$partitionColumn,
            $partition,
        );
    }

    /**
     * Check that an account reference still belongs to the active context.
     */
    public function ensurePartition(OAuthAccountIdentity $identity): void
    {
        if ($this->partition() !== $identity->partition) {
            throw new LogicException('The OAuth account belongs to another partition.');
        }
    }

    /**
     * Read committed credentials, using the shared cache when enabled.
     */
    public function find(OAuthAccountIdentity $identity): OAuthAccount
    {
        $this->ensurePartition($identity);
        $read = fn (): OAuthAccount => $identity->query()->useWritePdo()->firstOrFail()->unsetRelations();

        $account = $this->cacheEnabled()
            ? $this->cacheCoordinator->fill(
                $this->cache(),
                $identity->newModel(...),
                $this->cacheKey($identity),
                $this->cacheTtl,
                $read,
            )
            : $read();

        $this->ensurePartition($identity);

        return $account;
    }

    /**
     * Invalidate the account after its transaction commits.
     */
    public function invalidate(OAuthAccountIdentity $identity): void
    {
        if (! $this->cacheEnabled()) {
            return;
        }

        $cache = $this->cache();
        $key = $this->cacheKey($identity);

        // The captured key remains safe to invalidate after the caller restores its outer context.
        $identity->newModel()->getConnection()->afterCommitOrNow(function () use ($cache, $key): void {
            $this->cacheCoordinator->invalidate($cache, $key);
        });
    }

    /**
     * Get current credentials, sharing one bounded renewal with waiting callers.
     *
     * @param Closure(OAuthAuthenticator): OAuthAuthenticator $refreshUsing
     */
    public function token(OAuthAccountIdentity $identity, Closure $refreshUsing): OAuthAuthenticator
    {
        if (($token = $this->usableToken($this->find($identity))) !== null) {
            return $token;
        }

        $cache = $this->renewalCache();
        /** @var LockProvider&Store $locks */
        $locks = $cache->getStore();

        return $this->acquisition->run('account:' . $this->renewalStore . ':' . $identity->key, function () use ($identity, $refreshUsing, $locks): OAuthAuthenticator {
            for ($attempt = 1;; ++$attempt) {
                $retryDelay = null;

                try {
                    return $locks->lock('saloon:oauth-renewal:' . $identity->key, 120)->block(
                        120,
                        function () use ($identity, $refreshUsing, $attempt, &$retryDelay): OAuthAuthenticator {
                            return $this->renew($identity, $refreshUsing, $attempt, $retryDelay);
                        },
                    );
                } catch (CanceledException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    if ($retryDelay === null) {
                        throw $exception;
                    }

                    ConnectionResolver::releaseIdleConnections();
                    Sleep::usleep($retryDelay * 1000);
                }
            }
        });
    }

    /**
     * Return a usable token, or null when renewal must run first.
     */
    protected function usableToken(#[SensitiveParameter] OAuthAccount $account): ?OAuthAuthenticator
    {
        if ($account->refresh_claim !== null) {
            return null;
        }

        $token = $account->newOAuthAuthenticator();

        if ($token->isNotRefreshable()) {
            if ($token->hasExpired()) {
                throw new OAuthAccountReconnectRequiredException;
            }

            return $token;
        }

        return $account->expires_at === null
            || ($account->refresh_at ?? $account->expires_at)->getTimestamp() > Date::now()->getTimestamp()
                ? $token : null;
    }

    /**
     * Claim, perform and persist one provider attempt while holding the renewal lock.
     *
     * @param Closure(OAuthAuthenticator): OAuthAuthenticator $refreshUsing
     */
    protected function renew(OAuthAccountIdentity $identity, Closure $refreshUsing, int $attempt, ?int &$retryDelay): OAuthAuthenticator
    {
        $this->ensurePartition($identity);
        $account = $identity->query()->useWritePdo()->firstOrFail();
        $this->ensurePartition($identity);

        if (($token = $this->usableToken($account)) !== null) {
            return $token;
        }

        if ($account->hasApplicableTransaction()) {
            throw new LogicException('OAuth account renewal cannot run inside a database transaction.');
        }

        $abandonedBefore = Date::now()->subSeconds(300);

        if ($account->refresh_claim !== null && ($account->refresh_started_at === null || $account->refresh_started_at > $abandonedBefore)) {
            throw new RuntimeException('The OAuth account is already being refreshed. Try again later.');
        }

        $current = $account->newOAuthAuthenticator();

        $revision = $account->credential_revision;
        $claim = (string) Str::uuid();
        $claimed = $identity->query()->where('credential_revision', $revision)
            ->where(fn ($query) => $query->whereNull('refresh_claim')->orWhere('refresh_started_at', '<=', $abandonedBefore))
            ->update(['refresh_claim' => $claim, 'refresh_started_at' => Date::now()]);

        if ($claimed !== 1) {
            return $this->replacementToken($identity);
        }

        $oauthError = null;

        try {
            $this->invalidate($identity);
            ConnectionResolver::releaseIdleConnections();
            $this->ensurePartition($identity);

            try {
                $renewed = $refreshUsing($current);
            } catch (Throwable $exception) {
                $oauthError = $this->oauthError($exception);
                $retryDelay = $attempt < 3 ? $this->retryDelay($exception, $oauthError, $attempt) : null;
                throw $exception;
            }

            $this->ensurePartition($identity);

            if ($renewed->hasExpired()) {
                throw new UnexpectedValueException('The refreshed OAuth token has already expired.');
            }

            $refreshAt = TokenExpiry::refreshAt($renewed->getExpiresAt());
            $replacement = clone $account;
            $replacement->forceFill([
                'access_token' => $renewed->getAccessToken(),
                'refresh_token' => $renewed->getRefreshToken() ?? $account->refresh_token,
                'expires_at' => $renewed->getExpiresAt(),
                'refresh_at' => $refreshAt === null ? null : CarbonImmutable::createFromTimestampUTC($refreshAt),
                'scopes' => $renewed->getScopes() ?? $account->scopes,
                'credential_revision' => (string) Str::uuid(),
                'refresh_claim' => null,
                'refresh_started_at' => null,
            ]);
            // The snapshot predates the claim, so include the cleared fields even when they are not dirty.
            $written = $identity->query()->where('credential_revision', $revision)->where('refresh_claim', $claim)
                ->update([...$replacement->getDirty(), 'refresh_claim' => null, 'refresh_started_at' => null]);
            $this->invalidate($identity);
            $this->ensurePartition($identity);

            return $written === 1 ? $replacement->newOAuthAuthenticator() : $this->replacementToken($identity);
        } catch (Throwable $exception) {
            try {
                // Release only this captured claim, even if the callback changed the current partition.
                $identity->query()->where('credential_revision', $revision)->where('refresh_claim', $claim)
                    ->update(['refresh_claim' => null, 'refresh_started_at' => null]);
                $this->invalidate($identity);
            } catch (Throwable $cleanupFailure) {
                $retryDelay = null;
                $oauthError = null;
                try {
                    $this->exceptions->report($cleanupFailure);
                } catch (Throwable) {
                    // Reporting must not replace the provider failure or cancellation.
                }
            }

            if ($oauthError === 'invalid_grant') {
                $this->ensurePartition($identity);
                $latest = $identity->query()->useWritePdo()->firstOrFail();
                $this->ensurePartition($identity);

                if ($latest->credential_revision !== $revision) {
                    return $this->usableToken($latest)
                        ?? throw new RuntimeException('The replacement OAuth credentials are awaiting renewal. Try again later.');
                }
            }

            throw $exception;
        }
    }

    /**
     * Use the authoritative replacement after losing a conditional write.
     */
    protected function replacementToken(OAuthAccountIdentity $identity): OAuthAuthenticator
    {
        $this->ensurePartition($identity);
        $account = $identity->query()->useWritePdo()->firstOrFail();
        $this->ensurePartition($identity);

        return $this->usableToken($account)
            ?? throw new RuntimeException('The OAuth account changed during renewal. Try again with the current credentials.');
    }

    /**
     * Calculate a bounded provider retry delay in milliseconds.
     */
    protected function retryDelay(Throwable $exception, ?string $error, int $attempt): ?int
    {
        $response = $this->failureResponse($exception);

        if (in_array($error, ['invalid_grant', 'invalid_client', 'invalid_scope', 'invalid_request', 'unauthorized_client', 'unsupported_grant_type', 'access_denied'], true)) {
            return null;
        }

        $transient = in_array($error, ['server_error', 'temporarily_unavailable'], true)
            || $exception instanceof ConnectionException || $exception instanceof NetworkException
            || $exception instanceof FatalRequestException
            || $exception instanceof ResponseTransferException
            || ($response !== null && in_array($response->getStatusCode(), [408, 429, 500, 502, 503, 504], true));

        if (! $transient) {
            return null;
        }

        $maximum = $attempt === 1 ? 250 : 500;
        $retryAfter = $response === null ? null : RetryAfter::seconds($response->getHeaderLine('Retry-After'));

        if ($retryAfter !== null && $retryAfter > 0) {
            // Retry-After is in whole seconds, beyond this operation's subsecond retry budget.
            return null;
        }

        return random_int(0, $maximum);
    }

    /**
     * Read a structured OAuth error without inspecting exception messages.
     */
    protected function oauthError(Throwable $exception): ?string
    {
        if ($exception instanceof ResponseTransferException) {
            return null;
        }

        $response = $this->failureResponse($exception);

        if ($response === null) {
            return null;
        }

        $body = $exception instanceof RequestException
            ? $exception->response->body()
            : (string) $response->getBody();
        $data = json_decode($body, true);

        return is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : null;
    }

    /**
     * Get the response retained by the supported OAuth clients.
     */
    protected function failureResponse(Throwable $exception): ?ResponseInterface
    {
        return match (true) {
            $exception instanceof RequestException => $exception->response->toPsrResponse(),
            $exception instanceof GuzzleResponseException => $exception->getResponse(),
            default => null,
        };
    }

    /**
     * Determine whether shared credential caching is enabled.
     */
    protected function cacheEnabled(): bool
    {
        return $this->config->boolean('saloon.oauth_accounts.cache.enabled', false);
    }

    /**
     * Resolve and validate the credential cache only when it is used.
     */
    protected function cache(): CacheRepository
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $cache = $this->cacheFactory->store($this->config->get('saloon.oauth_accounts.cache.store'));
        $this->cacheValidator->validate($cache, 'Saloon OAuth account cache');
        $ttl = $this->config->integer('saloon.oauth_accounts.cache.ttl', self::DEFAULT_CACHE_TTL);

        if ($ttl <= 0) {
            throw new InvalidArgumentException('The OAuth account cache TTL must be a positive integer.');
        }

        $this->cacheTtl = $ttl;

        return $this->cache = $cache;
    }

    /**
     * Resolve and validate the renewal lock store only when it is used.
     */
    protected function renewalCache(): CacheRepository
    {
        if ($this->renewalCache !== null) {
            return $this->renewalCache;
        }

        $store = $this->config->get('saloon.oauth_accounts.lock_store')
            ?? $this->config->get('saloon.oauth_accounts.cache.store')
            ?? $this->config->string('cache.default');
        $cache = $this->cacheFactory->store($store);
        $this->cacheValidator->validate($cache, 'Saloon OAuth account renewal');
        $this->renewalStore = $store;

        return $this->renewalCache = $cache;
    }

    /**
     * Build the shared credential key.
     */
    protected function cacheKey(OAuthAccountIdentity $identity): string
    {
        return $this->config->string('saloon.oauth_accounts.cache.prefix', self::DEFAULT_CACHE_PREFIX) . $identity->key;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$accountModel = self::DEFAULT_ACCOUNT_MODEL;
        static::$initialized = false;
        static::$partitionColumn = null;
        static::$partitionResolver = null;
    }
}
