<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Tests\Testbench\Fixtures\Providers\CustomConfigServiceProvider;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

class CustomConfigurationTest extends TestCase
{
    #[Override]
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            CustomConfigServiceProvider::class,
        ];
    }

    #[Test]
    public function itCanOverrideExistingConfigurationOnRegister(): void
    {
        $this->assertSame('bar', config('database.redis.foo'));
    }
}
