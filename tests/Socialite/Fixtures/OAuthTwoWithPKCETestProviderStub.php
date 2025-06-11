<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite\Fixtures;

class OAuthTwoWithPKCETestProviderStub extends OAuthTwoTestProviderStub
{
    protected bool $usesPKCE = true;
}
