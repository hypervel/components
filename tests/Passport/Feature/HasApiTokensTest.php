<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Passport\Consent;
use Hypervel\Passport\Contracts\OAuthenticatable;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\HasApiTokens;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Passport\Fixtures\User;
use Hypervel\Tests\Passport\Fixtures\UserFactory;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

class HasApiTokensTest extends PassportTestCase
{
    use WithHypervelMigrations;

    public function testGetProvider(): void
    {
        config([
            'auth.providers.admins' => ['driver' => 'eloquent', 'model' => AdminHasApiTokensStub::class],
            'auth.guards.api-admins' => ['driver' => 'passport', 'provider' => 'admins'],
            'auth.providers.customers' => ['driver' => 'eloquent', 'model' => CustomerHasApiTokensStub::class],
            'auth.guards.api-customers' => ['driver' => 'passport', 'provider' => 'customers'],
        ]);

        $this->assertSame('users', UserFactory::new()->create()->getProviderName());
        $this->assertSame('admins', (new AdminHasApiTokensStub)->getProviderName());
        $this->assertSame('customers', (new CustomerHasApiTokensStub)->getProviderName());
    }

    #[DataProvider('ambiguousProviderOperations')]
    public function testAmbiguousProvidersCannotBeInferred(string $operation): void
    {
        config([
            'auth.providers.admins' => ['driver' => 'eloquent', 'model' => User::class],
            'auth.guards.api-admins' => ['driver' => 'passport', 'provider' => 'admins'],
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Override getProviderName() to select its provider.');

        $user = new User;

        match ($operation) {
            'provider' => $user->getProviderName(),
            'listing' => $user->tokens(),
            'issuance' => $user->createToken('My token'),
            'consents' => $user->oauthConsents(),
        };
    }

    /**
     * Provide model operations that infer an authentication provider.
     */
    public static function ambiguousProviderOperations(): array
    {
        return [['provider'], ['listing'], ['issuance'], ['consents']];
    }

    public function testMultipleGuardsForOneProviderAreUnambiguous(): void
    {
        config(['auth.guards.other-api' => ['driver' => 'passport', 'provider' => 'users']]);

        $this->assertSame('users', (new User)->getProviderName());
    }

    public function testUnmatchedProviderCannotBeInferred(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unable to determine authentication provider for this model from configuration.');

        (new CustomerHasApiTokensStub)->getProviderName();
    }

    public function testAnExplicitProviderOverrideControlsTokensAndConsents(): void
    {
        config([
            'auth.providers.admins' => ['driver' => 'eloquent', 'model' => User::class],
            'auth.guards.api-admins' => ['driver' => 'passport', 'provider' => 'admins'],
        ]);

        $user = (new ExplicitProviderHasApiTokensStub)->forceFill(['id' => 1]);
        $client = ClientFactory::new()->asPersonalAccessTokenClient()->create(['provider' => 'admins']);
        $result = $user->createToken('Admin token');
        $consent = $user->oauthConsents()->create(['client_id' => $client->id, 'scopes' => [], 'permissions' => []]);
        Consent::factory()->create(['client_id' => $client->id, 'user_id' => 1, 'provider' => 'users']);

        $this->assertSame('admins', $result->token->provider);
        $this->assertSame([$result->token->id], $user->tokens()->pluck('id')->all());
        $this->assertSame('admins', $consent->fresh()->provider);
        $this->assertSame([$consent->id], $user->oauthConsents()->pluck('id')->all());
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

    public function testTokensCreatedThroughTheRelationRecordTheUserProvider(): void
    {
        $user = UserFactory::new()->create();
        $client = ClientFactory::new()->create();
        $token = $user->tokens()->create(['id' => 'users-token', 'client_id' => $client->id, 'revoked' => false]);

        $this->assertSame('users', $token->fresh()->provider);
        $this->assertSame(['users-token'], $user->tokens()->pluck('id')->all());

        config([
            'auth.providers.admins' => ['driver' => 'eloquent', 'model' => AdminHasApiTokensStub::class],
            'auth.guards.api-admins' => ['driver' => 'passport', 'provider' => 'admins'],
        ]);
        $admin = (new AdminHasApiTokensStub)->forceFill(['id' => $user->id]);
        $this->assertSame([], $admin->tokens()->pluck('id')->all());
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

class ExplicitProviderHasApiTokensStub extends User
{
    /**
     * Select the provider for this identity.
     */
    public function getProviderName(): string
    {
        return 'admins';
    }
}
