<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai;

use Hypervel\Ai\AiServiceProvider;
use Hypervel\Ai\Files\UntrustedUrl;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Register the AI package's services.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            AiServiceProvider::class,
        ];
    }

    /**
     * Resolve remote test attachments without external DNS lookups.
     */
    protected function setUp(): void
    {
        parent::setUp();

        UntrustedUrl::resolveUsing(fn (string $host): array => ['93.184.216.34']);
    }

    /**
     * Register the package's conversation migrations.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../src/ai/database/migrations');
    }
}
