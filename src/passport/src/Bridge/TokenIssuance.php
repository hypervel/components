<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\NonCopyableContext;
use Hypervel\Contracts\Container\Transient;
use Hypervel\Database\Connection;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Passport\AuthCode;
use Hypervel\Passport\DeviceCode;
use Hypervel\Passport\Events\AccessTokenRevoked;
use Hypervel\Passport\Passport;
use Hypervel\Passport\RefreshToken;
use Hypervel\Passport\ServerEntry;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\SigningKeyRepository;
use Hypervel\Passport\StoredServerKey;
use Hypervel\Passport\Token;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Event;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use LogicException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class TokenIssuance implements NonCopyableContext, Transient
{
    protected const string CONTEXT_KEY = '__passport.issuance';

    protected const int REVOCATION_BATCH_SIZE = 1000;

    public AuthCode|DeviceCode|RefreshToken|null $grant = null;

    public ?ClientEntityInterface $client = null;

    public ?Token $accessToken = null;

    public ?string $tokenName = null;

    /** @var null|list<string> */
    public ?array $approvedScopes = null;

    protected ?Connection $connection = null;

    protected int $transactionLevel = 0;

    protected bool $claimed = false;

    /** @var array<string, mixed> */
    protected array $authorizationAttributes = [];

    /**
     * Pin one issuance to its settings and active signing key.
     */
    public function __construct(
        public readonly ServerEntry $entry,
        public readonly SigningCryptKey $signingKey,
        protected readonly CarbonImmutable $startedAt,
        protected readonly bool $tokenRequest = false,
    ) {
    }

    /**
     * Get the current issuance, if one is running.
     */
    public static function current(): ?self
    {
        return CoroutineContext::get(self::CONTEXT_KEY);
    }

    /**
     * Run a fresh issuance and restore any enclosing operation afterwards.
     *
     * @template TResult
     * @param Closure(): TResult $callback
     * @param array<string, mixed> $attributes
     * @return TResult
     */
    public static function run(ServerFactory $servers, Closure $callback, array $attributes = [], bool $tokenRequest = false): mixed
    {
        $previous = self::current();
        CoroutineContext::forget(self::CONTEXT_KEY);

        try {
            $entry = $servers->entry();
            $startedAt = CarbonImmutable::now();
            $issuance = new self($entry, $entry->signingKey($startedAt), $startedAt, $tokenRequest);
            $issuance->setAuthorizationAttributes($attributes);
            CoroutineContext::set(self::CONTEXT_KEY, $issuance);

            try {
                $result = $callback();
                $issuance->commit();

                return $result;
            } catch (Throwable $exception) {
                try {
                    $issuance->rollBack();
                } catch (CanceledException $cancellation) {
                    if (! $exception instanceof CanceledException) {
                        throw $cancellation;
                    }
                } catch (Throwable) {
                    // A failed rollback must not replace the issuance failure.
                }

                throw $exception;
            }
        } finally {
            if ($previous === null) {
                CoroutineContext::forget(self::CONTEXT_KEY);
            } else {
                CoroutineContext::set(self::CONTEXT_KEY, $previous);
            }
        }
    }

    /**
     * Determine whether this operation exchanges credentials for tokens.
     */
    public function isTokenRequest(): bool
    {
        return $this->tokenRequest;
    }

    /**
     * Record the grant already read during validation.
     */
    public function setGrant(AuthCode|DeviceCode|RefreshToken|null $grant): void
    {
        $this->grant = $grant;
    }

    /**
     * Record the client selected for this operation.
     */
    public function setClient(ClientEntityInterface $client): void
    {
        $this->client = $client;
    }

    /**
     * Keep the saved access token available to response extensions.
     */
    public function setAccessToken(Token $token): void
    {
        $this->accessToken = $token;
    }

    /**
     * Record the attributes captured when the user authorized the request.
     *
     * @param array<string, mixed> $attributes
     */
    public function setAuthorizationAttributes(array $attributes): void
    {
        $this->authorizationAttributes = $attributes;
    }

    /**
     * Get the attributes carried from authorization or the redeemed grant.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->tokenRequest && $this->grant !== null
            ? $this->grant->only(['provider', 'consent_id', ...Passport::$inheritedGrantAttributes])
            : $this->authorizationAttributes;
    }

    /**
     * Reject redemption whose durable security writes could be rolled back by its caller.
     *
     * Device polling timestamps and refresh-token replay revocations must survive OAuth error responses.
     */
    public static function ensureRedemptionAllowed(Connection $connection): void
    {
        if ($connection->hasApplicableTransaction()) {
            throw new LogicException('Passport token redemption cannot run inside an application transaction.');
        }
    }

    /**
     * Begin the credential transaction at the first validated write.
     */
    public function begin(): void
    {
        if ($this->connection !== null) {
            return;
        }

        $connection = Passport::token()->getConnection();
        $this->transactionLevel = $connection->transactionLevel();
        $connection->beginTransaction();
        $this->connection = $connection;

        if ($this->grant instanceof RefreshToken) {
            // Rotation and family revocation take the family lock before token locks.
            $family = Passport::refreshTokenFamily()->newQuery()
                ->whereKey($this->grant->family_id)->lockForUpdate()->first();

            if ($family === null || $family->revoked_at !== null) {
                throw OAuthServerException::invalidGrant();
            }
        }
    }

    /**
     * Claim a validated single-use grant before creating its successor tokens.
     */
    public function claimGrant(): void
    {
        $this->begin();
        $grant = $this->grant ?? throw OAuthServerException::invalidGrant();

        if ($grant->newQuery()->whereKey($grant->getKey())->where('revoked', false)->update(['revoked' => true]) === 0) {
            // End the failed issuance first so replay revocation commits independently of its rollback.
            $this->rollBack();

            if ($grant instanceof RefreshToken) {
                self::revokeRefreshTokenFamily($grant->family_id);
            }

            throw OAuthServerException::invalidGrant();
        }

        $this->claimed = true;
    }

    /**
     * Determine whether this operation already consumed a grant.
     */
    public function hasClaimed(Model $grant, string $id): bool
    {
        return $this->claimed && $this->grant !== null
            && $grant::class === $this->grant::class && $this->grant->getKey() === $id;
    }

    /**
     * Commit the issuance before returning its completed response.
     */
    protected function commit(): void
    {
        try {
            $this->connection?->commit();
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->connection->transactionLevel() !== $this->transactionLevel) {
                throw $exception;
            }

            // After-commit failures cannot undo issuance. Withholding the response would strand consumed grants.
            report($exception);
        }

        $this->connection = null;
    }

    /**
     * Undo the issuance without disturbing an enclosing caller's transaction.
     */
    protected function rollBack(): void
    {
        $connection = $this->connection;
        $this->connection = null;

        if ($connection !== null && $connection->transactionLevel() > $this->transactionLevel) {
            $connection->rollBack($this->transactionLevel);
        }
    }

    /**
     * Retain the signing key until this token's complete lifetime has passed.
     */
    public function retainSigningKey(DateTimeInterface $expiresAt, ?DateInterval $lifetime = null): void
    {
        $key = $this->entry->keys[$this->signingKey->getKeyId()];

        if (! $key instanceof StoredServerKey) {
            return;
        }

        $seconds = (int) ceil((float) $expiresAt->format('U.u') - (float) $this->startedAt->format('U.u'));

        if ($lifetime !== null) {
            $seconds = max($seconds, SigningKeyRepository::lifetimeInSeconds($lifetime));
        }

        if ($seconds <= $this->entry->retentionBounds[$key->id]) {
            return;
        }

        $this->begin();
        $model = Passport::signingKey();
        $query = $model->newQuery()->whereKey($key->id);

        if ((clone $query)->where('max_token_lifetime', '<', $seconds)->update(['max_token_lifetime' => $seconds]) === 0) {
            $stored = $query->lockForUpdate()->value('max_token_lifetime');

            if ($stored === null) {
                throw new LogicException('The Passport signing key was removed during token issuance.');
            }

            $seconds = max($seconds, (int) $stored);
        }

        $entry = $this->entry;
        $model->getConnection()->afterCommitOrNow(static function () use ($entry, $key, $seconds): void {
            $entry->retentionBounds[$key->id] = max($entry->retentionBounds[$key->id], $seconds);
        });
    }

    /**
     * Revoke a refresh grant and every access token still linked to it.
     */
    public static function revokeRefreshTokenFamily(string $familyId): void
    {
        $family = Passport::refreshTokenFamily();
        $connection = $family->getConnection();
        $connection->transaction(static function () use ($family, $familyId): void {
            $record = $family->newQuery()->whereKey($familyId)->lockForUpdate()->first();

            if ($record === null || $record->revoked_at !== null) {
                return;
            }

            $family->newQuery()->whereKey($familyId)->update(['revoked_at' => CarbonImmutable::now()]);
            $refresh = Passport::refreshToken();
            $refresh->newQuery()->where('family_id', $familyId)
                ->select([$refresh->getKeyName(), 'access_token_id'])->useWritePdo()->toBase()
                ->chunkById(self::REVOCATION_BATCH_SIZE, static function (Collection $rows) use ($refresh): void {
                    $refresh->newQuery()->whereKey($rows->pluck($refresh->getKeyName())->all())
                        ->where('revoked', false)->update(['revoked' => true]);
                    self::revokeAccessTokens(Passport::token()->newQuery()->whereKey($rows->pluck('access_token_id')->all()));
                }, $refresh->getKeyName());
        });
    }

    /**
     * Revoke selected access tokens within the caller's credential transaction.
     *
     * @param Builder<Token> $query
     */
    public static function revokeAccessTokens(Builder $query): void
    {
        $token = $query->getModel();
        $connection = $token->getConnection();

        // A locking read sees exchanges committed while revocation waited, even under MySQL REPEATABLE READ.
        $query->select($token->getQualifiedKeyName())->where('revoked', false)->lockForUpdate()->toBase()
            ->chunkById(self::REVOCATION_BATCH_SIZE, static function (Collection $rows) use ($token, $connection): void {
                $ids = $rows->pluck($token->getKeyName())->all();
                $token->newQuery()->whereKey($ids)->update(['revoked' => true]);

                foreach ($ids as $id) {
                    $token::clearTokenCache($id);
                }

                $connection->afterCommitOrNow(static function () use ($ids): void {
                    if (Event::hasListeners(AccessTokenRevoked::class)) {
                        foreach ($ids as $id) {
                            Event::dispatch(new AccessTokenRevoked($id));
                        }
                    }
                });
            }, $token->getKeyName());
    }
}
