<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Passport\Bridge\Client;
use Hypervel\Passport\Bridge\ClientRepository;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;

class BridgeClientRepositoryTest extends PassportTestCase
{
    use WithHypervelMigrations;

    public function testCanGetClient(): void
    {
        $model = ClientFactory::new()->create([
            'name' => 'Client',
            'redirect_uris' => ['http://localhost'],
        ]);

        $client = app(ClientRepository::class)->getClientEntity($model->getKey());

        $this->assertInstanceOf(Client::class, $client);
        $this->assertSame($model->getKey(), $client->getIdentifier());
        $this->assertSame('Client', $client->getName());
        $this->assertEquals(['http://localhost'], $client->getRedirectUri());
        $this->assertTrue($client->isConfidential());
    }

    public function testCanGetPublicClient(): void
    {
        $model = ClientFactory::new()->asPublic()->create();

        $this->assertFalse(app(ClientRepository::class)->getClientEntity($model->getKey())->isConfidential());
    }

    public function testCannotGetRevokedOrMissingClient(): void
    {
        $revoked = ClientFactory::new()->create(['revoked' => true]);

        $repository = app(ClientRepository::class);

        $this->assertNull($repository->getClientEntity($revoked->getKey()));
        $this->assertNull($repository->getClientEntity('missing'));
    }

    public function testCanValidateClient(): void
    {
        $client = ClientFactory::new()->create();
        $secret = $client->plainSecret;

        $repository = app(ClientRepository::class);

        foreach (['authorization_code', 'client_credentials', null] as $grantType) {
            $this->assertTrue($repository->validateClient($client->getKey(), $secret, $grantType));
            $this->assertFalse($repository->validateClient($client->getKey(), 'wrong-secret', $grantType));
            $this->assertFalse($repository->validateClient($client->getKey(), null, $grantType));
            $this->assertFalse($repository->validateClient($client->getKey(), '', $grantType));
        }
    }

    public function testCannotValidateRevokedOrPublicClient(): void
    {
        $revoked = ClientFactory::new()->create(['revoked' => true]);
        $public = ClientFactory::new()->asPublic()->create();

        $repository = app(ClientRepository::class);

        $this->assertFalse($repository->validateClient($revoked->getKey(), $revoked->plainSecret, 'authorization_code'));
        $this->assertFalse($repository->validateClient($public->getKey(), 'secret', 'authorization_code'));
    }

    public function testClientEntityHasAnEmptyNameWhenNoneIsProvided(): void
    {
        $this->assertSame('', (new Client('client'))->getName());
    }
}
