<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use SensitiveParameter;

class PersonalAccessBearerTokenResponse extends BearerTokenResponse
{
    /**
     * Include the identifier of the issued personal access token.
     *
     * {@inheritdoc}
     */
    protected function getExtraParams(#[SensitiveParameter] AccessTokenEntityInterface $accessToken): array
    {
        return [
            ...parent::getExtraParams($accessToken),
            'access_token_id' => $accessToken->getIdentifier(),
        ];
    }
}
