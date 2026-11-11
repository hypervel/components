<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Events\Verified;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\MustVerifyEmail;
use Hypervel\Tests\TestCase;
use Mockery as m;

class VerifiedEventTest extends TestCase
{
    public function testAcceptsVerifiableUserThatIsNotAuthenticatable(): void
    {
        $user = m::mock(MustVerifyEmail::class);

        $this->assertNotInstanceOf(Authenticatable::class, $user);
        $this->assertSame($user, (new Verified($user))->user);
    }
}
