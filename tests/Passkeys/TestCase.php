<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Passkeys\PasskeysServiceProvider;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase as TestbenchTestCase;
use Hypervel\Tests\Passkeys\Fixtures\User;

abstract class TestCase extends TestbenchTestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    /**
     * Get package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            PasskeysServiceProvider::class,
        ];
    }

    /**
     * Set up the package environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set([
            'app.key' => 'base64:' . base64_encode(str_repeat('a', 32)),
            'app.url' => 'https://localhost',
            'auth.defaults.guard' => 'web',
            'auth.guards.web' => ['driver' => 'session', 'provider' => 'users'],
            'auth.providers.users' => ['driver' => 'eloquent', 'model' => User::class],
            'passkeys.relying_party_id' => 'localhost',
            'passkeys.allowed_origins' => ['https://localhost'],
            'passkeys.user_handle_secret' => 'test-passkey-secret',
            'passkeys.redirect' => '/',
            'passkeys.timeout' => 60000,
        ]);
    }

    /**
     * Get the migrations to run for the test.
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => false,
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => [
                dirname(__DIR__, 2) . '/src/passkeys/database/migrations',
            ],
        ];
    }

    /**
     * Create fixture tables after refreshing the database.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->rememberToken();
            $table->timestamps();
        });
    }
}
