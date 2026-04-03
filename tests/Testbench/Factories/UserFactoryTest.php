<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Factories;

use Carbon\CarbonInterface;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\Factories\UserFactory;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * @internal
 * @coversNothing
 */
class UserFactoryTest extends TestCase
{
    use WithWorkbench;

    #[Test]
    public function itHasTheDefaultConfiguration(): void
    {
        $this->assertSame(User::class, config('auth.providers.users.model'));
        $this->assertNull(env('AUTH_MODEL'));
    }

    #[Test]
    public function itCanGenerateUser(): void
    {
        $user = UserFactory::new()->make();

        $this->assertInstanceOf(User::class, $user);
        $this->assertFalse($user->exists);
        $this->assertNotNull($user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertInstanceOf(CarbonInterface::class, $user->email_verified_at);
    }

    #[Test]
    public function itCanGenerateUnverifiedUser(): void
    {
        $user = UserFactory::new()->unverified()->make();

        $this->assertInstanceOf(User::class, $user);
        $this->assertFalse($user->exists);
        $this->assertNotNull($user->email);
        $this->assertNull($user->email_verified_at);
    }
}
