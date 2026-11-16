<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Events\Verified;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\MustVerifyEmail;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class VerifiedEventTest extends TestCase
{
    use VerifiesDoubles;

    public function testAcceptsVerifiableUserThatIsNotAuthenticatable(): void
    {
        $user = Double::for(MustVerifyEmail::class);

        $this->assertNotInstanceOf(Authenticatable::class, $user);
        $this->assertSame($user, (new Verified($user))->user);
    }
}
