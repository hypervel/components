<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Carbon\CarbonInterface;
use Closure;
use DateTimeInterface;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Passport\Bridge\ParsedKey;
use Hypervel\Support\CarbonImmutable;
use InvalidArgumentException;
use League\OAuth2\Server\CryptKey;
use LogicException;
use RuntimeException;
use SensitiveParameter;

class SigningKeyRepository
{
    protected const int LOCK_SECONDS = 120;

    protected const int LOCK_WAIT_SECONDS = 10;

    /**
     * Create the signing-key store and its mutation dependencies.
     */
    public function __construct(
        protected CacheFactory $cache,
        protected Repository $config,
        protected KeyGenerator $generator,
        protected ServerFactory $servers,
    ) {
    }

    /**
     * Get the stored keys, newest first, from the writer.
     *
     * @return Collection<int, SigningKey>
     */
    public function all(): Collection
    {
        return Passport::signingKey()->newQuery()->useWritePdo()
            ->orderByDesc('activates_at')->orderByDesc('id')->get();
    }

    /**
     * Project keys for server settings without decrypting retired private keys.
     *
     * @return list<StoredServerKey>
     */
    public function serverKeys(): array
    {
        $now = CarbonImmutable::now();
        $foundSigningKey = false;
        $keys = [];

        foreach ($this->all() as $key) {
            $privateKey = null;

            if (! $foundSigningKey && $key->getRawOriginal('private_key') !== null) {
                $privateKey = $key->private_key;
                $foundSigningKey = $key->activates_at <= $now;
            }

            $keys[] = new StoredServerKey(
                $key->getKey(),
                $key->max_token_lifetime,
                $key->public_key,
                $privateKey,
                $key->activates_at,
            );
        }

        return $keys;
    }

    /**
     * Provision the first key without replacing an existing key set.
     */
    public function create(int $length = KeyGenerator::DEFAULT_LENGTH): ?SigningKey
    {
        $this->ensureNoTransaction();

        if (Passport::signingKey()->newQuery()->useWritePdo()->exists()) {
            return null;
        }

        $material = $this->generator->generate($length);

        return $this->mutate(function () use ($material): ?SigningKey {
            if (Passport::signingKey()->newQuery()->exists()) {
                return null;
            }

            return $this->storeKey($material, CarbonImmutable::now());
        });
    }

    /**
     * Publish a replacement, retaining any key already waiting to activate.
     */
    public function rotate(int $length = KeyGenerator::DEFAULT_LENGTH): SigningKey
    {
        $waiting = $this->mutate(function (): ?SigningKey {
            $key = $this->waitingKey();

            if ($key !== null) {
                $this->pruneKeys();
            }

            return $key;
        });

        if ($waiting !== null) {
            return $waiting;
        }

        $material = $this->generator->generate($length);

        return $this->mutate(function () use ($material): SigningKey {
            $key = $this->waitingKey() ?? $this->storeKey($material, $this->nextActivation());
            $this->pruneKeys();

            return $key;
        });
    }

    /**
     * Rotate an overdue signing key and prune keys whose retention has passed.
     */
    public function rotateDueKeys(int $length = KeyGenerator::DEFAULT_LENGTH): void
    {
        $this->ensureNoTransaction();
        $key = Passport::signingKey()->newQuery()->useWritePdo()->whereNotNull('private_key')
            ->where('activates_at', '<=', CarbonImmutable::now())->latest('activates_at')->first();

        if ($key !== null && $key->activates_at <= CarbonImmutable::now()->subDays($this->config->integer('passport.key_rotation.days'))) {
            $this->rotate($length);
        } else {
            $this->prune();
        }
    }

    /**
     * Queue a supplied private key after every key already published for activation.
     */
    public function import(#[SensitiveParameter] string $privateKey): SigningKey
    {
        $this->ensureNoTransaction();
        $parsed = ParsedKey::fromPrivateKey($privateKey);

        return $this->mutate(function () use ($privateKey, $parsed): SigningKey {
            foreach ($this->all() as $key) {
                if (ParsedKey::fromPublicKey($key->public_key)->getKeyId() === $parsed->getKeyId()) {
                    throw new InvalidArgumentException('This signing key is already present in the key set.');
                }
            }

            $key = $this->storeKey(['private' => $privateKey, 'public' => $parsed->getPublicKey()], $this->nextActivation());
            $this->pruneKeys();

            return $key;
        });
    }

    /**
     * Import file keys while retaining every token already signed by them.
     *
     * @return Collection<int, SigningKey>
     */
    public function importFiles(?DateTimeInterface $retainUntil = null): Collection
    {
        $this->ensureNoTransaction();
        $materials = [];

        foreach ($this->servers->fileKeys() as $key) {
            $public = new CryptKey($key->publicKey, null, Passport::$validateKeyPermissions);
            $parsed = ParsedKey::fromPublicKey($public->getKeyContents());
            $private = null;

            if ($key->privateKey !== null) {
                $private = (new CryptKey($key->privateKey, null, Passport::$validateKeyPermissions))->getKeyContents();

                if (ParsedKey::fromPrivateKey($private)->getKeyId() !== $parsed->getKeyId()) {
                    throw new InvalidArgumentException('Passport private and public keys must belong to the same key pair.');
                }
            }

            $identifier = $parsed->getKeyId();

            if (! isset($materials[$identifier]) || $private !== null) {
                $materials[$identifier] = ['public' => $parsed->getPublicKey(), 'private' => $private];
            }
        }

        return $this->mutate(function () use ($materials, $retainUntil): Collection {
            if (Passport::signingKey()->newQuery()->exists()) {
                throw new LogicException('Import file keys into an empty database key set.');
            }

            $latestExpiry = Passport::token()->newQuery()->useWritePdo()->max('expires_at');
            $floor = $retainUntil === null ? null : CarbonImmutable::instance($retainUntil);

            if ($latestExpiry !== null && ($floor === null || CarbonImmutable::parse($latestExpiry) > $floor)) {
                $floor = CarbonImmutable::parse($latestExpiry);
            }

            $now = CarbonImmutable::now();
            $keys = new Collection;

            foreach ($materials as $material) {
                $keys->push($this->storeKey($material, $material['private'] === null ? $now->subSecond() : $now, $floor));
            }

            $this->pruneKeys();

            return $keys;
        });
    }

    /**
     * Remove a compromised key, replacing it immediately if it is signing.
     */
    public function revoke(string $keyId, int $length = KeyGenerator::DEFAULT_LENGTH): void
    {
        $needsReplacement = $this->mutate(function () use ($keyId): bool {
            $keys = $this->all();
            $target = $this->findByKeyId($keys, $keyId);

            if ($target === null) {
                return false;
            }

            if ($target->is($this->activeKey($keys))) {
                return true;
            }

            $this->deleteKey($target);

            return false;
        });

        if (! $needsReplacement) {
            return;
        }

        $material = $this->generator->generate($length);

        $this->mutate(function () use ($keyId, $material): void {
            $keys = $this->all();
            $target = $this->findByKeyId($keys, $keyId);

            if ($target === null) {
                return;
            }

            if ($target->is($this->activeKey($keys))) {
                $this->storeKey($material, CarbonImmutable::now());
            }

            $this->deleteKey($target);
        });
    }

    /**
     * Prune replaced keys after their retention deadlines.
     */
    public function prune(): void
    {
        $this->mutate(fn () => $this->pruneKeys());
    }

    /**
     * Recompute retention under the set lock and compare before deleting.
     */
    protected function pruneKeys(): void
    {
        $successor = null;
        $now = CarbonImmutable::now();

        foreach ($this->all() as $key) {
            if ($successor !== null) {
                $deadline = $successor->activates_at->addSeconds($key->max_token_lifetime);

                if ($key->retain_until !== null && $key->retain_until > $deadline) {
                    $deadline = $key->retain_until;
                }

                $query = $key->newQuery()->whereKey($key->getKey())
                    ->where('max_token_lifetime', $key->max_token_lifetime)
                    ->where('retain_until', $key->retain_until);

                if ($successor->activates_at <= $now && $deadline <= $now) {
                    $query->delete();
                } else {
                    $query->update(['retires_at' => $deadline]);
                }
            }

            if ($key->getRawOriginal('private_key') !== null) {
                $successor = $key;
            }
        }
    }

    /**
     * Get the newest private key already waiting to activate.
     */
    protected function waitingKey(): ?SigningKey
    {
        return Passport::signingKey()->newQuery()->whereNotNull('private_key')
            ->where('activates_at', '>', CarbonImmutable::now())->latest('activates_at')->first();
    }

    /**
     * Leave a full publication interval after the newest waiting key.
     */
    protected function nextActivation(): CarbonInterface
    {
        $latest = $this->waitingKey()->activates_at ?? CarbonImmutable::now();

        return $latest->addSeconds($this->config->integer('passport.key_activation_delay'));
    }

    /**
     * Get the newest active private key from a newest-first key set.
     *
     * @param Collection<int, SigningKey> $keys
     */
    protected function activeKey(Collection $keys): ?SigningKey
    {
        $now = CarbonImmutable::now();

        return $keys->first(static fn (SigningKey $key): bool => $key->getRawOriginal('private_key') !== null && $key->activates_at <= $now);
    }

    /**
     * Find a stored key by its public JWK thumbprint.
     *
     * @param Collection<int, SigningKey> $keys
     */
    protected function findByKeyId(Collection $keys, string $keyId): ?SigningKey
    {
        return $keys->first(static fn (SigningKey $key): bool => ParsedKey::fromPublicKey($key->public_key)->getKeyId() === $keyId);
    }

    /**
     * Store key material and fail if an observer cancels the mutation.
     *
     * @param array{private: ?string, public: string} $material
     */
    protected function storeKey(#[SensitiveParameter] array $material, CarbonInterface $activatesAt, ?CarbonInterface $retainUntil = null): SigningKey
    {
        $key = Passport::signingKey()->forceFill([
            'public_key' => $material['public'], 'private_key' => $material['private'],
            'activates_at' => $activatesAt, 'max_token_lifetime' => 0, 'retain_until' => $retainUntil,
        ]);

        if (! $key->save()) {
            throw new RuntimeException('Signing key persistence was canceled.');
        }

        return $key;
    }

    /**
     * Delete a key through its model so key-set observers run atomically.
     */
    protected function deleteKey(SigningKey $key): void
    {
        if (! $key->delete()) {
            throw new RuntimeException('Signing key deletion was canceled.');
        }
    }

    /**
     * Name the key set whose mutations share a lock.
     */
    protected function keySetName(): string
    {
        return 'default';
    }

    /**
     * Prevent a caller's transaction from outliving the key-set lock.
     */
    protected function ensureNoTransaction(): void
    {
        if (Passport::signingKey()->hasApplicableTransaction()) {
            throw new LogicException('Passport signing key management cannot run inside an application transaction.');
        }
    }

    /**
     * Serialize a key-set mutation and commit it before releasing the lock.
     *
     * @template TResult
     * @param Closure(): TResult $callback
     * @return TResult
     */
    protected function mutate(Closure $callback): mixed
    {
        $this->ensureNoTransaction();
        $store = $this->cache->store()->getStore();

        if (! $store instanceof LockProvider || $store instanceof ArrayStore || $store instanceof WorkerArrayStore) {
            throw new LogicException('Passport signing key management requires a shared cache store with atomic locks.');
        }

        return $store->lock('passport:signing-keys:' . $this->keySetName(), self::LOCK_SECONDS)->block(
            self::LOCK_WAIT_SECONDS,
            fn () => Passport::signingKey()->getConnection()->transaction($callback),
        );
    }
}
