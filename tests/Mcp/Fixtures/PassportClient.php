<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Passport\Client;

class PassportClient extends Client
{
    public bool $timestamps = false;
}
