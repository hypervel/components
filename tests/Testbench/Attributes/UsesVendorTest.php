<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Hypervel\Contracts\Config\Repository as ConfigRepositoryContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Auth\User;
use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Testbench\Attributes\UsesVendor;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\join_paths;
use function Hypervel\Testbench\package_path;

class UsesVendorTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    #[UsesVendor]
    public function itCanUsesVendorAttribute(): void
    {
        $filesystem = new Filesystem;

        $this->assertSame(
            $filesystem->hash(base_path(join_paths('vendor', 'autoload.php'))),
            $filesystem->hash(package_path('vendor', 'autoload.php'))
        );
    }

    #[Test]
    #[UsesVendor]
    public function itCanUsesConfigFromAttribute(): void
    {
        tap($this->app->make('config'), function ($repository): void {
            $this->assertInstanceOf(ConfigRepositoryContract::class, $repository);
        });
    }

    #[Test]
    #[UsesVendor]
    #[WithMigration]
    #[WithConfig('database.default', 'testing')]
    public function itCanResolveConfigFromContainer(): void
    {
        $user = User::query()->count();

        $this->assertSame(0, $user);
    }
}
