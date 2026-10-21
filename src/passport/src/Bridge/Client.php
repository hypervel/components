<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use Hypervel\Contracts\Container\Transient;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

class Client implements ClientEntityInterface, Transient
{
    use ClientTrait;
    use EntityTrait;

    /**
     * Create a new client instance.
     *
     * @param non-empty-string $identifier
     * @param string[] $redirectUri
     */
    public function __construct(
        string $identifier,
        ?string $name = null,
        array $redirectUri = [],
        bool $isConfidential = false,
        public ?string $provider = null,
        public array $grantTypes = [],
    ) {
        $this->setIdentifier($identifier);

        $this->name = $name ?? '';

        $this->isConfidential = $isConfidential;
        $this->redirectUri = $redirectUri;
    }

    /**
     * Determine whether the client supports the grant type.
     *
     * {@inheritdoc}
     */
    public function supportsGrantType(string $grantType): bool
    {
        return in_array($grantType, $this->grantTypes, true);
    }
}
