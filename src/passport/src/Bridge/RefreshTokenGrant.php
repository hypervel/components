<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use League\OAuth2\Server\Grant\RefreshTokenGrant as LeagueRefreshTokenGrant;

class RefreshTokenGrant extends LeagueRefreshTokenGrant
{
    use AuthenticatesClients;
}
