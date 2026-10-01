<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Factories;

use Carbon\CarbonInterface;
use Hypervel\Foundation\Auth\User as FoundationUser;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\Factories\UserFactory;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Workbench\App\Models\User;

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
    public function itFallsBackToTheAuthModelEnvironmentVariable(): void
    {
        config(['auth.providers.users' => ['driver' => 'database', 'table' => 'users']]);

        $this->assertSame(FoundationUser::class, UserFactory::new()->modelName());

        try {
            Env::set('AUTH_MODEL', User::class);

            $this->assertSame(User::class, UserFactory::new()->modelName());
        } finally {
            Env::forget('AUTH_MODEL');
        }
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
    public function itCanFlushTheCachedPassword(): void
    {
        $reflection = new ReflectionClass(UserFactory::class);

        UserFactory::new()->make();

        $this->assertNotNull($reflection->getStaticPropertyValue('password'));

        UserFactory::flushState();

        $this->assertNull($reflection->getStaticPropertyValue('password'));
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
