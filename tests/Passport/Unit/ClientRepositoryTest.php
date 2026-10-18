<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use Closure;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\QueryException;
use Hypervel\Passport\Client;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\Passport;
use Hypervel\Passport\PassportServiceProvider;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PDOException;
use RuntimeException;

class ClientRepositoryTest extends TestCase
{
    /**
     * Register the configured cache and model services.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [PassportServiceProvider::class];
    }

    /**
     * Use a client with a controllable query boundary.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Passport::useClientModel(ClientRepositoryTestClient::class);
    }

    /**
     * Release the test-owned query callback.
     */
    protected function tearDown(): void
    {
        ClientRepositoryTestClient::$query = null;

        parent::tearDown();
    }

    public function testMalformedUuidClientIdsDoNotExecuteALookup(): void
    {
        ClientRepositoryTestClient::$query = fn () => throw new RuntimeException('Unexpected client lookup.');

        $repository = new ClientRepository;

        foreach (['x', '', '1', 1, '550e8400-e29b-41d4-a716-44665544000x'] as $id) {
            $this->assertNull($repository->find($id));
        }
    }

    public function testValidUuidClientIdsAreLookedUp(): void
    {
        $id = '550e8400-e29b-41d4-a716-446655440000';
        $client = new Client;
        $query = m::mock(Builder::class);
        $query->expects('useWritePdo')->andReturnSelf();
        $query->expects('find')->with($id)->andReturn($client);
        ClientRepositoryTestClient::$query = fn () => $query;

        $this->assertSame($client, (new ClientRepository)->find($id));
    }

    // REMOVED: Disabling client UUIDs was a pre-13 schema compatibility option.

    public function testCustomUniqueIdClientIdsAreValidatedByTheModel(): void
    {
        Passport::useClientModel(ClientRepositoryTestUlidClient::class);

        $id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $client = new Client;
        $query = m::mock(Builder::class);
        $query->expects('useWritePdo')->andReturnSelf();
        $query->expects('find')->with($id)->andReturn($client);
        ClientRepositoryTestClient::$query = fn () => $query;

        $repository = new ClientRepository;

        $this->assertNull($repository->find('x'));
        $this->assertNull($repository->find(1));
        $this->assertNull($repository->find('550e8400-e29b-41d4-a716-446655440000'));
        $this->assertSame($client, $repository->find($id));
    }

    public function testLookupFailuresAreNotCaught(): void
    {
        $exception = new QueryException('testing', 'select * from oauth_clients', [], new PDOException('Database unavailable.'));
        ClientRepositoryTestClient::$query = fn () => throw $exception;
        $this->expectExceptionObject($exception);

        (new ClientRepository)->find('550e8400-e29b-41d4-a716-446655440000');
    }
}

class ClientRepositoryTestClient extends Client
{
    public static ?Closure $query = null;

    /**
     * Resolve the query supplied by the test.
     */
    public function newQuery(): Builder
    {
        return (static::$query)();
    }
}

class ClientRepositoryTestUlidClient extends ClientRepositoryTestClient
{
    use HasUlids;
}
