<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Hypervel\Passport\Bridge\AuthorizationServer;
use Hypervel\Passport\Bridge\ParsedKey;
use Hypervel\Passport\Bridge\SigningCryptKey;
use Hypervel\Support\CarbonImmutable;
use League\OAuth2\Server\ResourceServer;
use LogicException;

/**
 * Keep parsed keys and reusable servers together for one immutable settings set.
 */
class ServerEntry
{
    /** @var array{keys: list<array<string, string>>} */
    public readonly array $jwks;

    /** @var array<string, int> */
    public array $retentionBounds = [];

    /** @var array<string, AuthorizationServer> */
    public array $authorizationServers = [];

    /** @var array<string, AuthorizationServer> */
    public array $personalAccessServers = [];

    public ?ResourceServer $resourceServer = null;

    /**
     * Keep a settings set and its parsed keys for reuse by the worker.
     *
     * @param array<string, ServerKey> $keys
     * @param array<string, ParsedKey> $verificationKeys
     * @param array<string, SigningCryptKey> $signingKeys
     */
    public function __construct(
        public readonly ServerSettings $settings,
        public readonly array $keys,
        public readonly array $verificationKeys,
        public readonly array $signingKeys,
    ) {
        foreach ($keys as $key) {
            if ($key instanceof StoredServerKey) {
                $this->retentionBounds[$key->id] = $key->maxTokenLifetime;
            }
        }

        $this->jwks = ['keys' => array_values(array_map(static fn (ParsedKey $key): array => $key->getJwk(), $verificationKeys))];
    }

    /**
     * Select the newest signing key active at the start of an issuance.
     */
    public function signingKey(CarbonImmutable $at): SigningCryptKey
    {
        foreach ($this->keys as $identifier => $key) {
            if (isset($this->signingKeys[$identifier]) && ($key->activatesAt === null || $key->activatesAt <= $at)) {
                return $this->signingKeys[$identifier];
            }
        }

        throw new LogicException('No active Passport signing key is available. Generate or import a signing key before issuing tokens.');
    }
}
