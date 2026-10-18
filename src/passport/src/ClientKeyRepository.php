<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Firebase\JWT\Key;
use Hypervel\Contracts\Cache\Factory;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Config\Repository;
use LogicException;
use UnexpectedValueException;

class ClientKeyRepository
{
    /**
     * Create the client public-key repository.
     */
    public function __construct(
        protected Factory $cache,
        protected Repository $config,
        protected RemoteDocumentFetcher $documents,
        protected JwkValidator $keys,
    ) {
    }

    /**
     * Find the public key registered for a client assertion.
     */
    public function find(Client $client, ?string $identifier, string $algorithm): ?Key
    {
        if ($client->jwks !== null) {
            $key = $this->select($client->jwks, $identifier, $algorithm);

            return $key === null ? null : $this->keys->validate($key, $algorithm);
        }

        if ($client->jwks_uri === null) {
            return null;
        }

        $name = $this->config->get('passport.cache.store');
        $cache = $this->cache->store($name === '' ? null : $name);
        $cacheKey = $this->config->string('passport.cache.prefix') . ':client-keys:' . hash('sha256', json_encode([
            $client->getKey(), $client->jwks_uri, $algorithm,
        ], JSON_THROW_ON_ERROR));
        $document = $cache->get($cacheKey);

        if ($document !== null && ($key = $this->select($document, $identifier, $algorithm)) !== null) {
            return $this->keys->validate($key, $algorithm);
        }

        $store = $cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('Passport remote client keys require a cache store with atomic locks.');
        }

        // One lock and cooldown per client/key declaration, never per attacker-supplied kid.
        $document = $store->lock($cacheKey . ':lock', 30)->block(5, function () use ($cache, $cacheKey, $client, $identifier, $algorithm): ?array {
            $document = $cache->get($cacheKey);

            if ($document !== null && $this->select($document, $identifier, $algorithm) !== null) {
                return $document;
            }

            if (! $cache->add($cacheKey . ':refresh', true, $this->config->integer('passport.client_keys.refresh_interval'))) {
                return $document;
            }

            $document = $this->documents->fetch($client->jwks_uri);
            $this->validate($document, $algorithm);
            $cache->put($cacheKey, $document, $this->config->integer('passport.client_keys.ttl'));

            return $document;
        });
        $key = $document === null ? null : $this->select($document, $identifier, $algorithm);

        return $key === null ? null : $this->keys->validate($key, $algorithm);
    }

    /**
     * Validate a public key set before registering or caching it.
     */
    public function validate(array $document, string $algorithm): void
    {
        $identifiers = [];
        $signers = 0;

        foreach ($this->keySet($document) as $key) {
            $this->keys->ensurePublic($key);

            // A shared JWKS may also publish encryption keys or other signing algorithms.
            if (! $this->keys->permits($key, $algorithm)) {
                continue;
            }

            if (array_key_exists('kid', $key)) {
                if (! is_string($key['kid']) || $key['kid'] === '' || isset($identifiers[$key['kid']])) {
                    throw new UnexpectedValueException('Public key identifiers must be nonempty and unique.');
                }

                $identifiers[$key['kid']] = true;
            }

            $this->keys->validate($key, $algorithm);
            ++$signers;
        }

        if ($signers === 0) {
            throw new UnexpectedValueException('The public key set contains no key for the selected signature algorithm.');
        }

        if ($signers > 1 && $identifiers === []) {
            throw new UnexpectedValueException('A public key set with multiple signing keys needs a key identifier for selection.');
        }
    }

    /**
     * Select exactly one matching key without trying signatures against every key.
     *
     * @return null|array<string, mixed>
     */
    protected function select(array $document, ?string $identifier, string $algorithm): ?array
    {
        $selected = null;

        foreach ($this->keySet($document) as $key) {
            if (($identifier !== null && ($key['kid'] ?? null) !== $identifier)
                || ! $this->keys->permits($key, $algorithm)) {
                continue;
            }

            if ($selected !== null) {
                return null;
            }

            $selected = $key;
        }

        return $selected;
    }

    /**
     * Read the keys from a JSON Web Key Set.
     *
     * @return list<array<string, mixed>>
     */
    protected function keySet(array $document): array
    {
        $keys = $document['keys'] ?? null;

        if (! is_array($keys) || ! array_is_list($keys) || $keys === []) {
            throw new UnexpectedValueException('The public key set must contain a nonempty keys array.');
        }

        foreach ($keys as $key) {
            if (! is_array($key)) {
                throw new UnexpectedValueException('Each public key must be a JSON object.');
            }
        }

        return $keys;
    }
}
