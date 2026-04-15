<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Auth\User;
use Hypervel\Testbench\Factories\UserFactory;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\testbench_path;

class EnvironmentVariablesTest extends TestCase
{
    #[Override]
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app['config']->set('database.default', 'testing');
    }

    #[Override]
    protected function defineDatabaseMigrations(): void
    {
        $this->loadHypervelMigrations(['--database' => 'testing']);
    }

    #[Test]
    public function itCanBeUsedWithoutHavingAnEnvironmentVariablesFile(): void
    {
        if (Env::has('TESTBENCH_PACKAGE_TESTER')) {
            $this->markTestSkipped('Will always fail via `package:test` command');
        }

        $user = UserFactory::new()->create();

        $this->assertFalse(file_exists(testbench_path('workbench/.env')));
        $this->assertFalse(file_exists(base_path('.env')));

        $this->assertInstanceOf(User::class, $user);
    }
}
