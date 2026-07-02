<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Fortify\Fortify;

class FortifyGuardTest extends TestCase
{
    public function testFortifyGuardFollowsCurrentDefaultGuardSelectedByShouldUse(): void
    {
        /** @var AuthFactory $auth */
        $auth = $this->app->make(AuthFactory::class);

        $auth->shouldUse('admin');

        $this->assertSame('admin', Fortify::guardName());
        $this->assertSame($auth->guard('admin'), Fortify::guard());
        $this->assertInstanceOf(StatefulGuard::class, Fortify::guard());
    }
}
