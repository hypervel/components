<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Http;

use Hypervel\Contracts\Auth\Access\Gate as GateContract;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Request;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\TelescopeApplicationServiceProvider;
use Hypervel\Tests\Telescope\FeatureTestCase;

class AuthorizationTest extends FeatureTestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return array_merge(
            parent::getPackageProviders($app),
            [TelescopeApplicationServiceProvider::class]
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Telescope::auth(null);
    }

    public function testCleanTelescopeInstallationDeniesAccessByDefault()
    {
        $this->post('/telescope/telescope-api/requests')
            ->assertStatus(403);
    }

    public function testCleanTelescopeInstallationDeniesAccessByDefaultForAnyAuthUser()
    {
        $this->actingAs(new Authenticated);

        $this->post('/telescope/telescope-api/requests')
            ->assertStatus(403);
    }

    public function testGuestsGetsUnauthorizedByGate()
    {
        Telescope::auth(function (Request $request) {
            return $this->app->make(GateContract::class)
                ->check('viewTelescope', [$request->user()]);
        });

        $this->app->make(GateContract::class)
            ->define('viewTelescope', function ($user) {
                return false;
            });

        $this->post('/telescope/telescope-api/requests')
            ->assertStatus(403);
    }

    public function testAuthenticatedUserGetsAuthorizedByGate()
    {
        $this->actingAs(new Authenticated);

        Telescope::auth(function (Request $request) {
            return $this->app->make(GateContract::class)
                ->check('viewTelescope', [$request->user()]);
        });

        $this->app->make(GateContract::class)
            ->define('viewTelescope', function (Authenticatable $user) {
                return $user->getAuthIdentifier() === 'telescope-test';
            });

        $this->post('/telescope/telescope-api/requests')
            ->assertStatus(200);
    }

    public function testGuestsCanBeAuthorized()
    {
        Telescope::auth(function (Request $request) {
            return $this->app->make(GateContract::class)
                ->check('viewTelescope', [$request->user()]);
        });

        $this->app->make(GateContract::class)
            ->define('viewTelescope', function (?Authenticatable $user) {
                return true;
            });

        $this->post('/telescope/telescope-api/requests')
            ->assertStatus(200);
    }

    public function testUnauthorizedRequests()
    {
        Telescope::auth(function () {
            return false;
        });

        $this->get('/telescope/telescope-api/requests')
            ->assertStatus(403);
    }

    public function testAuthorizedRequests()
    {
        Telescope::auth(function () {
            return true;
        });

        $this->post('/telescope/telescope-api/requests')
            ->assertSuccessful();
    }
}

class Authenticated implements Authenticatable
{
    public $email;

    public function getAuthIdentifierName(): string
    {
        return 'Telescope Test';
    }

    public function getAuthIdentifier(): string
    {
        return 'telescope-test';
    }

    public function getAuthPassword(): string
    {
        return 'secret';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken(string $value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
