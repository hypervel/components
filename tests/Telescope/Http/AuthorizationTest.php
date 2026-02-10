<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Http;

use Hypervel\Auth\Access\Gate;
use Hypervel\Contracts\Auth\Access\Gate as GateContract;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Http\Request as RequestContract;
use Hypervel\Telescope\Telescope;
use Hypervel\Tests\Telescope\FeatureTestCase;

/**
 * @internal
 * @coversNothing
 */
class AuthorizationTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockGate();
        $this->loadServiceProviders();
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
        $this->actingAs(new Authenticated());

        $this->post('/telescope/telescope-api/requests')
            ->assertStatus(403);
    }

    public function testGuestsGetsUnauthorizedByGate()
    {
        Telescope::auth(function (RequestContract $request) {
            return $this->app->get(GateContract::class)
                ->check('viewTelescope', [$request->user()]);
        });

        $this->app->get(GateContract::class)
            ->define('viewTelescope', function ($user) {
                return false;
            });

        $this->post('/telescope/telescope-api/requests')
            ->assertStatus(403);
    }

    public function testAuthenticatedUserGetsAuthorizedByGate()
    {
        $this->actingAs(new Authenticated());

        Telescope::auth(function (RequestContract $request) {
            return $this->app->get(GateContract::class)
                ->check('viewTelescope', [$request->user()]);
        });

        $this->app->get(GateContract::class)
            ->define('viewTelescope', function (Authenticatable $user) {
                return $user->getAuthIdentifier() === 'telescope-test';
            });

        $this->post('/telescope/telescope-api/requests')
            ->assertStatus(200);
    }

    public function testGuestsCanBeAuthorized()
    {
        Telescope::auth(function (RequestContract $request) {
            return $this->app->get(GateContract::class)
                ->check('viewTelescope', [$request->user()]);
        });

        $this->app->get(GateContract::class)
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

    protected function mockGate(): void
    {
        $gate = new Gate($this->app, function () {
            return new Authenticated('email@foo.bar');
        });

        $this->app->instance(GateContract::class, $gate);
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
}
