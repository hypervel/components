<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use League\OAuth2\Server\Grant\ClientCredentialsGrant as LeagueClientCredentialsGrant;

class ClientCredentialsGrant extends LeagueClientCredentialsGrant
{
    use AuthenticatesClients;
}
