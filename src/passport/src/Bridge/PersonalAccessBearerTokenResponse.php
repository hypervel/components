<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use Hypervel\Contracts\Container\Transient;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;

class PersonalAccessBearerTokenResponse extends BearerTokenResponse implements Transient
{
    /**
     * Include the identifier of the issued personal access token.
     *
     * {@inheritdoc}
     */
    protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
    {
        return [
            'access_token_id' => $accessToken->getIdentifier(),
        ];
    }
}
