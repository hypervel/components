<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Passport\Client;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\TestWith;

final class ClientTest extends TestCase
{
    /**
     * Enable strict access to model attributes.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Model::preventAccessingMissingAttributes();
    }

    public function testClientSecretsAreStoredAsSha256Digests(): void
    {
        $secret = 'a-high-entropy-generated-client-secret';
        $client = new Client(['secret' => $secret]);

        $this->assertSame(hash('sha256', $secret), $client->getAttributes()['secret']);
        $this->assertNotSame($secret, $client->getAttributes()['secret']);
        $this->assertSame($secret, $client->plainSecret);
        $this->assertTrue($client->secretMatches($secret));
        $this->assertFalse($client->secretMatches('wrong-secret'));

        $client->secret = null;
        $this->assertNull($client->getAttributes()['secret']);
        $this->assertNull($client->plainSecret);
        $this->assertFalse($client->secretMatches($secret));
    }

    public function testScopesWhenClientDoesNotHaveScope(): void
    {
        $client = new Client(['scopes' => ['bar']]);
        $client->exists = true;

        $this->assertFalse($client->hasScope('foo'));
    }

    #[TestWith([['owner_id' => null], true])]
    #[TestWith([['owner_id' => 0], false])]
    #[TestWith([['owner_id' => 1, 'third_party' => false], false])]
    #[TestWith([['owner_id' => null, 'third_party' => false], true])]
    #[TestWith([['owner_id' => null, 'third_party' => true], false])]
    public function testFirstPartyClientsExcludeOwnedAndThirdPartyClients(array $attributes, bool $expected): void
    {
        $client = new Client($attributes);
        $client->exists = true;

        $this->assertSame($expected, $client->firstParty());
    }

    public function testScopesWhenClientHasScope(): void
    {
        $client = new Client(['scopes' => ['foo', 'bar']]);
        $client->exists = true;

        $this->assertTrue($client->hasScope('foo'));
    }

    public function testScopesWhenColumnDoesNotExist(): void
    {
        $client = new Client;
        $client->exists = true;

        $this->assertTrue($client->hasScope('foo'));
    }

    public function testScopesWhenColumnIsNull(): void
    {
        $client = new Client(['scopes' => null]);
        $client->exists = true;

        $this->assertTrue($client->hasScope('foo'));
    }

    public function testGrantTypesWhenClientDoesNotHaveGrantType(): void
    {
        $client = new Client(['grant_types' => ['bar']]);
        $client->exists = true;

        $this->assertFalse($client->hasGrantType('foo'));
    }

    public function testGrantTypesWhenClientHasGrantType(): void
    {
        $client = new Client(['grant_types' => ['foo', 'bar']]);
        $client->exists = true;

        $this->assertTrue($client->hasGrantType('foo'));
    }

    // REMOVED: Missing and null grant_types fallback tests require the pre-13 client schema.
}
