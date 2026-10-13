<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Passport\Contracts\OAuthenticatable;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\HasApiTokens;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Passport\Fixtures\UserFactory;

class HasApiTokensTest extends PassportTestCase
{
    use WithHypervelMigrations;

    public function testGetProvider(): void
    {
        config([
            'auth.providers.admins' => ['driver' => 'eloquent', 'model' => AdminHasApiTokensStub::class],
            'auth.guards.passport-admins' => ['driver' => 'passport', 'provider' => 'admins'],
            'auth.providers.customers' => ['driver' => 'eloquent', 'model' => CustomerHasApiTokensStub::class],
            'auth.guards.passport-customers' => ['driver' => 'passport', 'provider' => 'customers'],
        ]);

        $this->assertSame('users', UserFactory::new()->create()->getProviderName());
        $this->assertSame('admins', (new AdminHasApiTokensStub)->getProviderName());
        $this->assertSame('customers', (new CustomerHasApiTokensStub)->getProviderName());
    }

    public function testTokenListingsCanJoinClientsWithoutAmbiguousProviderColumns(): void
    {
        $user = UserFactory::new()->create();
        $client = ClientFactory::new()->create(['name' => 'My OAuth App', 'provider' => 'users']);

        foreach (['users', 'admins'] as $provider) {
            $user->tokens()->create([
                'id' => $provider,
                'client_id' => $client->id,
                'provider' => $provider,
                'scopes' => [],
                'revoked' => false,
            ]);
        }

        $tokens = $user->tokens()
            ->join('oauth_clients', 'oauth_clients.id', '=', 'oauth_access_tokens.client_id')
            ->get(['oauth_access_tokens.*', 'oauth_clients.name as client_name']);

        $this->assertSame(['users'], $tokens->modelKeys());
        $this->assertSame('My OAuth App', $tokens->first()->client_name);
    }
}

class AdminHasApiTokensStub extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens;
}

class CustomerHasApiTokensStub extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens;
}
