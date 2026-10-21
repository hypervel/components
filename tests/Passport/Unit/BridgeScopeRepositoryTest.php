<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use Hypervel\Passport\Bridge\Client;
use Hypervel\Passport\Bridge\Scope;
use Hypervel\Passport\Bridge\ScopeRepository;
use Hypervel\Passport\Client as ClientModel;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\Passport;
use Hypervel\Tests\TestCase;
use Mockery as m;

class BridgeScopeRepositoryTest extends TestCase
{
    public function testInvalidScopesAreRemoved(): void
    {
        Passport::tokensCan([
            'scope-1' => 'description',
        ]);

        $client = new ClientModel;

        $clients = m::mock(ClientRepository::class);
        $clients->expects('findActive')->with('id')->andReturn($client);

        $repository = new ScopeRepository($clients);

        $scopes = $repository->finalizeScopes(
            [$scope1 = new Scope('scope-1'), new Scope('scope-2')],
            'client_credentials',
            new Client('id', 'name', ['http://localhost']),
            '1'
        );

        $this->assertEquals([$scope1], $scopes);
    }

    public function testInvalidScopesAreRemovedWithoutAClientRepository(): void
    {
        Passport::tokensCan([
            'scope-1' => 'description',
        ]);

        $clients = m::mock(ClientRepository::class);
        $clients->expects('findActive')->with('id')->andReturn(new ClientModel);

        $repository = new ScopeRepository($clients);

        $scopes = $repository->finalizeScopes(
            [$scope1 = new Scope('scope-1'), new Scope('scope-2')],
            'client_credentials',
            new Client('id', 'name', ['http://localhost']),
            '1'
        );

        $this->assertEquals([$scope1], $scopes);
    }

    public function testClientsDoNotRestrictScopesByDefault(): void
    {
        Passport::tokensCan([
            'scope-1' => 'description',
            'scope-2' => 'description',
        ]);

        $client = new ClientModel;
        $client->scopes = null;

        $clients = m::mock(ClientRepository::class);
        $clients->expects('findActive')->with('id')->andReturn($client);

        $repository = new ScopeRepository($clients);

        $scopes = $repository->finalizeScopes(
            [$scope1 = new Scope('scope-1'), $scope2 = new Scope('scope-2')],
            'client_credentials',
            new Client('id', 'name', ['http://localhost']),
            '1'
        );

        $this->assertEquals([$scope1, $scope2], $scopes);
    }

    public function testScopesDisallowedForClientAreRemoved(): void
    {
        Passport::tokensCan([
            'scope-1' => 'description',
            'scope-2' => 'description',
        ]);

        $client = new ClientModel;
        $client->scopes = ['scope-1'];

        $clients = m::mock(ClientRepository::class);
        $clients->expects('findActive')->with('id')->andReturn($client);

        $repository = new ScopeRepository($clients);

        $scopes = $repository->finalizeScopes(
            [$scope1 = new Scope('scope-1'), new Scope('scope-2')],
            'client_credentials',
            new Client('id', 'name', ['http://localhost']),
            '1'
        );

        $this->assertEquals([$scope1], $scopes);
    }

    public function testScopesDisallowedForClientAreRemovedButInheritedScopesAreNot(): void
    {
        Passport::$withInheritedScopes = true;

        Passport::tokensCan([
            'scope-1' => 'description',
            'scope-1:limited-access' => 'description',
            'scope-2' => 'description',
        ]);

        $client = new ClientModel;
        $client->scopes = ['scope-1'];

        $clients = m::mock(ClientRepository::class);
        $clients->expects('findActive')->with('id')->andReturn($client);

        $repository = new ScopeRepository($clients);

        $scopes = $repository->finalizeScopes(
            [$scope1 = new Scope('scope-1:limited-access'), new Scope('scope-2')],
            'client_credentials',
            new Client('id', 'name', ['http://localhost']),
            '1'
        );

        $this->assertEquals([$scope1], $scopes);
    }

    public function testSuperuserScopeCantBeAppliedIfWrongGrant(): void
    {
        Passport::tokensCan([
            'scope-1' => 'description',
        ]);

        $client = new ClientModel;

        $clients = m::mock(ClientRepository::class);
        $clients->expects('findActive')->with('id')->andReturn($client);

        $repository = new ScopeRepository($clients);

        $scopes = $repository->finalizeScopes(
            [$scope1 = new Scope('*')],
            'refresh_token',
            new Client('id', 'name', ['http://localhost']),
            '1'
        );

        $this->assertEquals([], $scopes);
    }

    public function testSuperuserScopeCantBeAppliedIfWrongGrantWithoutAClientRepository(): void
    {
        Passport::tokensCan([
            'scope-1' => 'description',
        ]);

        $clients = m::mock(ClientRepository::class);
        $clients->expects('findActive')->with('id')->andReturn(new ClientModel);

        $repository = new ScopeRepository($clients);

        $scopes = $repository->finalizeScopes(
            [$scope1 = new Scope('*')],
            'refresh_token',
            new Client('id', 'name', ['http://localhost']),
            '1'
        );

        $this->assertEquals([], $scopes);
    }
}
