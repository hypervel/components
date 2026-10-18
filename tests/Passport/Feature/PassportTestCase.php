<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Passport\Database\Factories\SigningKeyFactory;
use Hypervel\Passport\Passport;
use Hypervel\Passport\PassportServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Passport\Fixtures\User;

// REMOVED: ImplicitGrantTest and PasswordGrantTest cover omitted legacy grants; use authorization code with PKCE or device authorization.
// REMOVED: AuthorizedAccessTokenControllerTest, ClientControllerTest, PersonalAccessTokenControllerTest and ScopeControllerTest cover the deprecated JSON API; use model relations and application-owned routes.
abstract class PassportTestCase extends TestCase
{
    use LazilyRefreshDatabase;

    protected string $keysDirectory;

    /**
     * Prepare isolated key files using one generated test key pair per process.
     */
    protected function setUp(): void
    {
        $this->keysDirectory = ParallelTesting::tempDir('PassportKeys');
        $this->afterApplicationCreated(function (): void {
            $files = new Filesystem;
            $files->deleteDirectory($this->keysDirectory);
            $files->ensureDirectoryExists($this->keysDirectory);
            $material = (new SigningKeyFactory)->definition();
            $files->put($this->keysDirectory . '/oauth-public.key', $material['public_key']);
            $files->put($this->keysDirectory . '/oauth-private.key', $material['private_key']);
            $files->chmod($this->keysDirectory . '/oauth-public.key', 0600);
            $files->chmod($this->keysDirectory . '/oauth-private.key', 0600);
            Passport::loadKeysFrom($this->keysDirectory);
        });

        $this->beforeApplicationDestroyed(function (): void {
            (new Filesystem)->deleteDirectory($this->keysDirectory);
        });

        parent::setUp();
    }

    /**
     * Register the Passport services for feature tests.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [PassportServiceProvider::class];
    }

    /**
     * Load the Passport tables.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../src/passport/database/migrations');
    }

    /**
     * Configure Passport's user provider and test database.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make(Repository::class);

        $config->set([
            'auth.providers.users.model' => User::class,
            'database.default' => 'testing',
        ]);
    }
}
