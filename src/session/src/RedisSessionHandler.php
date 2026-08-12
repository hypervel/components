<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Closure;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Redis\RedisConnection;
use Hypervel\Session\Concerns\ValidatesUserSessionArguments;
use Hypervel\Session\Contracts\CanManageUserSessions;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\InteractsWithTime;
use JsonException;
use Redis;
use RuntimeException;
use SessionHandlerInterface;
use Throwable;
use UnexpectedValueException;

class RedisSessionHandler implements CanManageUserSessions, SessionHandlerInterface
{
    use InteractsWithTime;
    use ValidatesUserSessionArguments;

    /**
     * Envelope values mirrored by the Lua ownership parser below.
     */
    protected const string ENVELOPE_FAMILY = "\0HVS";

    protected const string ENVELOPE_VERSION = "\0HVS1";

    protected const int OWNER_DIGEST_LENGTH = 32;

    protected const int ENVELOPE_HEADER_LENGTH = 37;

    /**
     * User-index fragments mirrored where Lua constructs dynamic owner keys.
     */
    protected const string USER_INDEX_PREFIX = '_users:';

    protected const string USER_INDEX_SUFFIX = ':sessions';

    protected const string LUA_OWNER_OF = <<<'LUA'
        -- Mirrors RedisSessionHandler's envelope constants.
        local family = string.char(0) .. 'HVS'
        local version = family .. '1'

        local function ownerOf(value)
            if not value or string.sub(value, 1, 4) ~= family then
                return nil
            end

            if string.sub(value, 1, 5) ~= version or string.len(value) < 37 then
                return nil
            end

            local owner = string.sub(value, 6, 37)

            if string.len(owner) ~= 32 or not string.match(owner, '^[0-9a-f]+$') then
                return nil
            end

            return owner
        end
        LUA;

    protected const string LUA_VALID_METADATA = <<<'LUA'
        local function validMetadata(value)
            if not value then
                return nil
            end

            local decoded, metadata = pcall(cjson.decode, value)

            if not decoded or type(metadata) ~= 'table' then
                return nil
            end

            local ipAddress = metadata['ip_address']
            local userAgent = metadata['user_agent']
            local lastActivity = metadata['last_activity']

            if (ipAddress ~= cjson.null and type(ipAddress) ~= 'string')
                or (userAgent ~= cjson.null and type(userAgent) ~= 'string')
                or type(lastActivity) ~= 'number'
                or lastActivity < 0
                or lastActivity ~= math.floor(lastActivity) then
                return nil
            end

            return metadata
        end
        LUA;

    protected const string WRITE_SCRIPT = self::LUA_OWNER_OF . "\n" . self::LUA_VALID_METADATA . "\n" . <<<'LUA'
        local payloadKey = KEYS[1]
        local ttl = ARGV[1]
        local sessionId = ARGV[2]
        local physicalPrefix = ARGV[3]
        local identityState = ARGV[4]
        local resolvedOwner = ARGV[5]
        local payload = ARGV[6]
        local metadata = ARGV[7]
        local hasFreshMetadata = ARGV[8] == '1'
        local lastActivity = tonumber(ARGV[9])
        local oldOwner = ownerOf(redis.call('GET', payloadKey))
        local newOwner = nil

        if identityState == 'resolved' then
            newOwner = resolvedOwner
        elseif identityState == 'unresolved' then
            newOwner = oldOwner
        elseif identityState ~= 'unowned' then
            return redis.error_reply('Invalid session identity state')
        end

        -- Write the resulting index before the authoritative payload and remove
        -- the obsolete index last, so partial failures leave only index drift
        -- that ownership-aware deletion rechecks against the payload.
        if newOwner then
            local indexKey = physicalPrefix .. '_users:' .. newOwner .. ':sessions'

            if not hasFreshMetadata and oldOwner == newOwner then
                local previousMetadata = validMetadata(redis.call('HGET', indexKey, sessionId))

                if previousMetadata then
                    previousMetadata['last_activity'] = lastActivity
                    metadata = cjson.encode(previousMetadata)
                end
            end

            redis.call('HSETEX', indexKey, 'EX', ttl, 'FIELDS', 1, sessionId, metadata)
            redis.call('SETEX', payloadKey, ttl, version .. newOwner .. payload)
        else
            redis.call('SETEX', payloadKey, ttl, payload)
        end

        if oldOwner and oldOwner ~= newOwner then
            local oldIndexKey = physicalPrefix .. '_users:' .. oldOwner .. ':sessions'
            redis.call('HDEL', oldIndexKey, sessionId)
        end

        return 1
        LUA;

    protected const string DESTROY_SCRIPT = self::LUA_OWNER_OF . "\n" . <<<'LUA'
        local payloadKey = KEYS[1]
        local sessionId = ARGV[1]
        local physicalPrefix = ARGV[2]
        local owner = ownerOf(redis.call('GET', payloadKey))
        local deleted = redis.call('DEL', payloadKey)

        if owner then
            redis.call('HDEL', physicalPrefix .. '_users:' .. owner .. ':sessions', sessionId)
        end

        return deleted
        LUA;

    protected const string DESTROY_USER_SESSION_SCRIPT = self::LUA_OWNER_OF . "\n" . <<<'LUA'
        local payloadKey = KEYS[1]
        local userIndexKey = KEYS[2]
        local requestedOwner = ARGV[1]
        local sessionId = ARGV[2]

        -- Same-slot execution makes index-first cleanup safe and prevents an
        -- index type error from deleting the payload before ownership is proven.
        redis.call('HDEL', userIndexKey, sessionId)

        if ownerOf(redis.call('GET', payloadKey)) == requestedOwner then
            return redis.call('DEL', payloadKey)
        end

        return 0
        LUA;

    protected const string DESTROY_USER_SESSIONS_SCRIPT = self::LUA_OWNER_OF . "\n" . <<<'LUA'
        local userIndexKey = KEYS[1]
        local requestedOwner = ARGV[1]
        local physicalPrefix = ARGV[2]
        local exceptions = {}
        local deleted = 0

        for index = 3, #ARGV do
            exceptions[ARGV[index]] = true
        end

        for _, sessionId in ipairs(redis.call('HKEYS', userIndexKey)) do
            if not exceptions[sessionId] then
                -- Mirrors Hypervel\Session\SessionId before a dynamic key is derived.
                local validSessionId = string.len(sessionId) == 40
                    and string.match(sessionId, '^[A-Za-z0-9]+$')

                if validSessionId then
                    local payloadKey = physicalPrefix .. sessionId

                    if ownerOf(redis.call('GET', payloadKey)) == requestedOwner then
                        deleted = deleted + redis.call('DEL', payloadKey)
                    end
                end

                redis.call('HDEL', userIndexKey, sessionId)
            end
        end

        return deleted
        LUA;

    protected const string CLUSTER_WRITE_SCRIPT = self::LUA_OWNER_OF . "\n" . <<<'LUA'
        local payloadKey = KEYS[1]
        local ttl = ARGV[1]
        local identityState = ARGV[2]
        local resolvedOwner = ARGV[3]
        local payload = ARGV[4]
        local oldOwner = ownerOf(redis.call('GET', payloadKey))
        local newOwner = nil

        if identityState == 'resolved' then
            newOwner = resolvedOwner
        elseif identityState == 'unresolved' then
            newOwner = oldOwner
        elseif identityState ~= 'unowned' then
            return redis.error_reply('Invalid session identity state')
        end

        if newOwner then
            redis.call('SETEX', payloadKey, ttl, version .. newOwner .. payload)
        else
            redis.call('SETEX', payloadKey, ttl, payload)
        end

        return {oldOwner or '', newOwner or ''}
        LUA;

    protected const string CLUSTER_UPDATE_INDEX_SCRIPT = self::LUA_VALID_METADATA . "\n" . <<<'LUA'
        local userIndexKey = KEYS[1]
        local sessionId = ARGV[1]
        local ttl = ARGV[2]
        local metadata = ARGV[3]

        if ARGV[4] == '1' then
            local previousMetadata = validMetadata(redis.call('HGET', userIndexKey, sessionId))

            if previousMetadata then
                previousMetadata['last_activity'] = tonumber(ARGV[5])
                metadata = cjson.encode(previousMetadata)
            end
        end

        redis.call('HSETEX', userIndexKey, 'EX', ttl, 'FIELDS', 1, sessionId, metadata)

        return 1
        LUA;

    protected const string CLUSTER_DESTROY_SCRIPT = self::LUA_OWNER_OF . "\n" . <<<'LUA'
        local payloadKey = KEYS[1]
        local requestedOwner = ARGV[1]
        local owner = ownerOf(redis.call('GET', payloadKey))
        local deleted = 0

        if requestedOwner == '' or owner == requestedOwner then
            deleted = redis.call('DEL', payloadKey)
        end

        return {deleted, owner or ''}
        LUA;

    /**
     * Create a new Redis session handler instance.
     */
    public function __construct(
        protected RedisFactory $redis,
        protected string $connection,
        protected string $prefix,
        protected int $minutes,
        protected bool $trackUserSessions = false,
        protected ?Container $container = null,
    ) {
    }

    /**
     * Open the session.
     */
    public function open(string $savePath, string $sessionName): bool
    {
        return true;
    }

    /**
     * Close the session.
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * Read the session data.
     */
    public function read(string $sessionId): string
    {
        $value = $this->withConnection(
            fn (RedisConnection $connection): mixed => $connection->get($this->payloadKey($sessionId)),
        );

        if ($value === false) {
            return '';
        }

        if (! is_string($value)) {
            throw new UnexpectedValueException('Redis returned an invalid session payload.');
        }

        return $this->payloadFromStoredValue($value);
    }

    /**
     * Write the session data.
     */
    public function write(string $sessionId, string $data): bool
    {
        if (! $this->trackUserSessions) {
            return $this->withConnection(
                fn (RedisConnection $connection): bool => $connection->setex(
                    $this->payloadKey($sessionId),
                    $this->lifetimeInSeconds(),
                    $data,
                ) === true,
            );
        }

        $identity = UserSessionIdentity::resolve($this->container, $sessionId);
        $identityState = $identity->isResolved()
            ? 'resolved'
            : ($identity->isUnowned() ? 'unowned' : 'unresolved');
        $resolvedOwner = $identity->authProvider === null || $identity->userId === null
            ? ''
            : $this->ownerDigest($identity->authProvider, $identity->userId);
        $lastActivity = $this->currentTime();
        // No request means preserve prior metadata; fresh nulls are real values.
        $hasFreshMetadata = $this->container !== null && RequestContext::has();
        $metadata = $this->encodeMetadata($lastActivity, $hasFreshMetadata);

        return $this->withConnection(function (RedisConnection $connection, bool $cluster) use (
            $sessionId,
            $data,
            $identityState,
            $resolvedOwner,
            $lastActivity,
            $hasFreshMetadata,
            $metadata,
        ): bool {
            if ($cluster) {
                return $this->writeCluster(
                    $connection,
                    $sessionId,
                    $data,
                    $identityState,
                    $resolvedOwner,
                    $lastActivity,
                    $hasFreshMetadata,
                    $metadata,
                );
            }

            return $connection->evalWithShaCache(
                self::WRITE_SCRIPT,
                [$this->payloadKey($sessionId)],
                [
                    (string) $this->lifetimeInSeconds(),
                    $sessionId,
                    $this->physicalPrefix($connection),
                    $identityState,
                    $resolvedOwner,
                    $data,
                    $metadata,
                    $hasFreshMetadata ? '1' : '0',
                    (string) $lastActivity,
                ],
            ) === 1;
        });
    }

    /**
     * Write a tracked session to Redis Cluster.
     */
    protected function writeCluster(
        RedisConnection $connection,
        string $sessionId,
        string $data,
        string $identityState,
        string $resolvedOwner,
        int $lastActivity,
        bool $hasFreshMetadata,
        string $metadata,
    ): bool {
        $owners = $connection->evalWithShaCache(
            self::CLUSTER_WRITE_SCRIPT,
            [$this->payloadKey($sessionId)],
            [
                (string) $this->lifetimeInSeconds(),
                $identityState,
                $resolvedOwner,
                $data,
            ],
        );
        [$oldOwner, $newOwner] = $this->parseOwnerTransition($owners);

        if ($newOwner !== '') {
            $result = $hasFreshMetadata
                ? $connection->hsetex(
                    $this->userIndexKey($newOwner),
                    [$sessionId => $metadata],
                    ['EX' => $this->lifetimeInSeconds()],
                )
                : $connection->evalWithShaCache(
                    self::CLUSTER_UPDATE_INDEX_SCRIPT,
                    [$this->userIndexKey($newOwner)],
                    [
                        $sessionId,
                        (string) $this->lifetimeInSeconds(),
                        $metadata,
                        $oldOwner === $newOwner ? '1' : '0',
                        (string) $lastActivity,
                    ],
                );

            if ($result !== 1) {
                return false;
            }
        }

        if ($oldOwner !== '' && $oldOwner !== $newOwner) {
            $result = $connection->hdel($this->userIndexKey($oldOwner), $sessionId);

            if ($result === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Destroy the given session.
     */
    public function destroy(string $sessionId): bool
    {
        if (! $this->trackUserSessions) {
            return $this->withConnection(
                fn (RedisConnection $connection): bool => $connection->del($this->payloadKey($sessionId)) === 1,
            );
        }

        return $this->withConnection(function (RedisConnection $connection, bool $cluster) use ($sessionId): bool {
            if (! $cluster) {
                return $connection->evalWithShaCache(
                    self::DESTROY_SCRIPT,
                    [$this->payloadKey($sessionId)],
                    [$sessionId, $this->physicalPrefix($connection)],
                ) === 1;
            }

            [$deleted, $owner] = $this->parseDestroyResult(
                $connection->evalWithShaCache(
                    self::CLUSTER_DESTROY_SCRIPT,
                    [$this->payloadKey($sessionId)],
                    [''],
                ),
            );

            // Payload-first ordering is required across slots: stale index
            // drift is safe, while leaving a live payload after deletion is not.
            if ($owner !== '') {
                $result = $connection->hdel($this->userIndexKey($owner), $sessionId);

                if ($result === false) {
                    throw new UnexpectedValueException('Redis failed to clean the user session index.');
                }
            }

            return $deleted === 1;
        });
    }

    /**
     * Perform session garbage collection.
     */
    public function gc(int $lifetime): int
    {
        return 0;
    }

    /**
     * Determine if the handler supports user session management.
     */
    public function supportsUserSessionManagement(): bool
    {
        return $this->trackUserSessions;
    }

    /**
     * Get the active sessions for the given user.
     *
     * @return Collection<int, UserSession>
     */
    public function userSessions(string $authProvider, int|string $userId): Collection
    {
        $this->validateAuthProvider($authProvider);
        $owner = $this->ownerDigest($authProvider, UserSessionIdentity::normalize($userId));

        return $this->withConnection(function (RedisConnection $connection) use ($owner): Collection {
            $entries = $connection->hGetAll($this->userIndexKey($owner));

            if (! is_array($entries)) {
                throw new UnexpectedValueException('Redis returned an invalid user session index.');
            }

            $sessions = [];
            $invalidSessionIds = [];

            foreach ($entries as $sessionId => $value) {
                $sessionId = (string) $sessionId;
                $metadata = is_string($value) ? $this->decodeMetadata($value) : null;

                if (! SessionId::isValid($sessionId) || $metadata === null) {
                    $invalidSessionIds[] = $sessionId;

                    continue;
                }

                $lastActivity = CarbonImmutable::createFromTimestampUTC($metadata['last_activity']);
                $sessions[] = new UserSession(
                    $sessionId,
                    $metadata['ip_address'],
                    $metadata['user_agent'],
                    $lastActivity,
                    $lastActivity->addMinutes($this->minutes),
                );
            }

            if ($invalidSessionIds !== []) {
                $result = $connection->hdel($this->userIndexKey($owner), ...$invalidSessionIds);

                if ($result === false) {
                    throw new UnexpectedValueException('Redis failed to prune invalid user session metadata.');
                }
            }

            usort($sessions, static function (UserSession $first, UserSession $second): int {
                return $second->lastActivity->getTimestamp() <=> $first->lastActivity->getTimestamp()
                    ?: $first->id <=> $second->id;
            });

            return new Collection($sessions);
        });
    }

    /**
     * Destroy an active session belonging to the given user.
     */
    public function destroyUserSession(
        string $authProvider,
        int|string $userId,
        string $sessionId,
    ): bool {
        $this->validateAuthProvider($authProvider);
        $owner = $this->ownerDigest($authProvider, UserSessionIdentity::normalize($userId));
        SessionId::validate($sessionId);

        return $this->withConnection(function (RedisConnection $connection, bool $cluster) use ($owner, $sessionId): bool {
            if (! $cluster) {
                return $connection->evalWithShaCache(
                    self::DESTROY_USER_SESSION_SCRIPT,
                    [$this->payloadKey($sessionId), $this->userIndexKey($owner)],
                    [$owner, $sessionId],
                ) === 1;
            }

            [$deleted] = $this->parseDestroyResult(
                $connection->evalWithShaCache(
                    self::CLUSTER_DESTROY_SCRIPT,
                    [$this->payloadKey($sessionId)],
                    [$owner],
                ),
            );

            // The payload script has already proved or rejected ownership.
            // Cross-slot index cleanup follows so drift cannot authorize deletion.
            $result = $connection->hdel($this->userIndexKey($owner), $sessionId);

            if ($result === false) {
                throw new UnexpectedValueException('Redis failed to clean the user session index.');
            }

            return $deleted === 1;
        });
    }

    /**
     * Destroy the active sessions belonging to the given user.
     *
     * @param list<string> $except
     */
    public function destroyUserSessions(
        string $authProvider,
        int|string $userId,
        array $except = [],
    ): int {
        $this->validateAuthProvider($authProvider);
        $owner = $this->ownerDigest($authProvider, UserSessionIdentity::normalize($userId));
        $except = $this->normalizeSessionIds($except);

        return $this->withConnection(function (RedisConnection $connection, bool $cluster) use ($owner, $except): int {
            if (! $cluster) {
                $result = $connection->evalWithShaCache(
                    self::DESTROY_USER_SESSIONS_SCRIPT,
                    [$this->userIndexKey($owner)],
                    [$owner, $this->physicalPrefix($connection), ...$except],
                );

                if (! is_int($result) || $result < 0) {
                    throw new UnexpectedValueException('Redis returned an invalid bulk session deletion result.');
                }

                return $result;
            }

            return $this->destroyUserSessionsInCluster($connection, $owner, $except);
        });
    }

    /**
     * Destroy user sessions across Redis Cluster slots.
     *
     * @param list<string> $except
     */
    protected function destroyUserSessionsInCluster(
        RedisConnection $connection,
        string $owner,
        array $except,
    ): int {
        $sessionIds = $connection->hKeys($this->userIndexKey($owner));

        if (! is_array($sessionIds)) {
            throw new UnexpectedValueException('Redis returned an invalid user session index.');
        }

        $exceptions = array_fill_keys($except, true);
        $cleanupSessionIds = [];
        $deleted = 0;

        foreach ($sessionIds as $sessionId) {
            $sessionId = (string) $sessionId;

            if (isset($exceptions[$sessionId])) {
                continue;
            }

            if (! SessionId::isValid($sessionId)) {
                $cleanupSessionIds[] = $sessionId;

                continue;
            }

            try {
                [$wasDeleted] = $this->parseDestroyResult(
                    $connection->evalWithShaCache(
                        self::CLUSTER_DESTROY_SCRIPT,
                        [$this->payloadKey($sessionId)],
                        [$owner],
                    ),
                );
            } catch (Throwable $payloadFailure) {
                try {
                    $this->cleanUserIndex($connection, $owner, $cleanupSessionIds);
                } catch (Throwable $cleanupFailure) {
                    throw new RuntimeException(
                        sprintf(
                            'Redis session payload deletion failed with %s: %s',
                            $payloadFailure::class,
                            $payloadFailure->getMessage(),
                        ),
                        previous: $cleanupFailure,
                    );
                }

                throw $payloadFailure;
            }

            $deleted += $wasDeleted;
            $cleanupSessionIds[] = $sessionId;
        }

        $this->cleanUserIndex($connection, $owner, $cleanupSessionIds);

        return $deleted;
    }

    /**
     * Clean fields from a user's session index.
     *
     * @param list<string> $sessionIds
     */
    protected function cleanUserIndex(
        RedisConnection $connection,
        string $owner,
        array $sessionIds,
    ): void {
        if ($sessionIds === []) {
            return;
        }

        if ($connection->hdel($this->userIndexKey($owner), ...$sessionIds) === false) {
            throw new UnexpectedValueException('Redis failed to clean the user session index.');
        }
    }

    /**
     * Get a payload key.
     */
    protected function payloadKey(string $sessionId): string
    {
        return $this->prefix . $sessionId;
    }

    /**
     * Get a user session index key.
     */
    protected function userIndexKey(string $owner): string
    {
        return $this->prefix . self::USER_INDEX_PREFIX . $owner . self::USER_INDEX_SUFFIX;
    }

    /**
     * Get the full physical prefix used for dynamically constructed Lua keys.
     */
    protected function physicalPrefix(RedisConnection $connection): string
    {
        return (string) $connection->getOption(Redis::OPT_PREFIX) . $this->prefix;
    }

    /**
     * Get the configured session lifetime in seconds.
     */
    protected function lifetimeInSeconds(): int
    {
        return $this->minutes * 60;
    }

    /**
     * Hash a provider-qualified user identifier for use in Redis keys and envelopes.
     */
    protected function ownerDigest(string $authProvider, string $userId): string
    {
        return hash('xxh128', strlen($authProvider) . ':' . $authProvider . ':' . $userId);
    }

    /**
     * Strip a valid ownership envelope from a stored session payload.
     */
    protected function payloadFromStoredValue(string $value): string
    {
        if (! str_starts_with($value, self::ENVELOPE_FAMILY)) {
            return $value;
        }

        if (strlen($value) < self::ENVELOPE_HEADER_LENGTH
            || ! str_starts_with($value, self::ENVELOPE_VERSION)
            || ! $this->validOwnerDigest(substr($value, strlen(self::ENVELOPE_VERSION), self::OWNER_DIGEST_LENGTH))) {
            return '';
        }

        return substr($value, self::ENVELOPE_HEADER_LENGTH);
    }

    /**
     * Determine if a value is a valid owner digest.
     */
    protected function validOwnerDigest(string $owner): bool
    {
        return preg_match('/\A[0-9a-f]{32}\z/', $owner) === 1;
    }

    /**
     * Encode metadata for a tracked session write.
     *
     * @throws JsonException
     */
    protected function encodeMetadata(int $lastActivity, bool $hasFreshMetadata): string
    {
        return json_encode([
            'ip_address' => $hasFreshMetadata ? $this->ipAddress() : null,
            'user_agent' => $hasFreshMetadata ? $this->userAgent() : null,
            'last_activity' => $lastActivity,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Decode and validate tracked session metadata.
     *
     * @return null|array{ip_address: null|string, user_agent: null|string, last_activity: int}
     */
    protected function decodeMetadata(string $value): ?array
    {
        try {
            $metadata = json_decode($value, false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_object($metadata)
            || ! property_exists($metadata, 'ip_address')
            || ! property_exists($metadata, 'user_agent')
            || ! property_exists($metadata, 'last_activity')
            || (! is_string($metadata->ip_address) && $metadata->ip_address !== null)
            || (! is_string($metadata->user_agent) && $metadata->user_agent !== null)
            || ! is_int($metadata->last_activity)
            || $metadata->last_activity < 0) {
            return null;
        }

        return [
            'ip_address' => $metadata->ip_address,
            'user_agent' => $metadata->user_agent,
            'last_activity' => $metadata->last_activity,
        ];
    }

    /**
     * Get the current request IP address.
     */
    protected function ipAddress(): ?string
    {
        return $this->container->make('request')->ip();
    }

    /**
     * Get the current request user agent.
     */
    protected function userAgent(): string
    {
        return mb_substr(
            mb_convert_encoding((string) $this->container->make('request')->header('User-Agent'), 'UTF-8'),
            0,
            500,
        );
    }

    /**
     * Parse the ownership transition returned by the cluster write script.
     *
     * @return array{string, string}
     */
    protected function parseOwnerTransition(mixed $value): array
    {
        if (! is_array($value)
            || count($value) !== 2
            || ! is_string($value[0] ?? null)
            || ! is_string($value[1] ?? null)
            || ($value[0] !== '' && ! $this->validOwnerDigest($value[0]))
            || ($value[1] !== '' && ! $this->validOwnerDigest($value[1]))) {
            throw new UnexpectedValueException('Redis returned an invalid session ownership transition.');
        }

        return [$value[0], $value[1]];
    }

    /**
     * Parse a cluster payload deletion result.
     *
     * @return array{0|1, string}
     */
    protected function parseDestroyResult(mixed $value): array
    {
        if (! is_array($value)
            || count($value) !== 2
            || ! is_int($value[0] ?? null)
            || ! in_array($value[0], [0, 1], true)
            || ! is_string($value[1] ?? null)
            || ($value[1] !== '' && ! $this->validOwnerDigest($value[1]))) {
            throw new UnexpectedValueException('Redis returned an invalid session deletion result.');
        }

        return [$value[0], $value[1]];
    }

    /**
     * Execute an operation on a pinned raw Redis connection.
     *
     * @param Closure(RedisConnection, bool): mixed $callback
     */
    protected function withConnection(Closure $callback): mixed
    {
        $redis = $this->redis->connection($this->connection);
        $cluster = $redis->isCluster();

        return $redis->withConnection(
            static fn (RedisConnection $connection): mixed => $connection->withoutSerializationOrCompression(
                static fn (): mixed => $callback($connection, $cluster),
            ),
            transform: false,
        );
    }
}
