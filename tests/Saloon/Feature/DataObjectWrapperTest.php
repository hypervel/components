<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Contracts\DataObjects\WithResponse;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Data\User;
use Hypervel\Tests\Saloon\Fixtures\Data\UserWithResponse;
use Hypervel\Tests\Saloon\Fixtures\Requests\DTORequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\DTOWithResponseRequest;

class DataObjectWrapperTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testIfADtoDoesNotImplementTheWithResponseInterfaceAndHasResponseTraitSaloonWillNotAddTheOriginalResponse(): void
    {
        $mockClient = new MockClient([
            new MockResponse(['name' => 'Sammyjo20', 'actual_name' => 'Sam', 'twitter' => '@carre_sam']),
        ]);

        $response = (new TestConnector)->send(new DTORequest, $mockClient);
        $dto = $response->dto();

        $this->assertInstanceOf(User::class, $dto);
        $this->assertNotInstanceOf(WithResponse::class, $dto);
    }

    public function testIfADtoImplementsTheWithResponseInterfaceAndHasResponseTraitSaloonWillAddTheOriginalResponse(): void
    {
        $mockClient = new MockClient([
            new MockResponse(['name' => 'Sammyjo20', 'actual_name' => 'Sam', 'twitter' => '@carre_sam']),
        ]);

        $request = new DTOWithResponseRequest;
        $response = (new TestConnector)->send($request, $mockClient);

        $dto = $response->dto();

        $this->assertInstanceOf(UserWithResponse::class, $dto);
        $this->assertInstanceOf(WithResponse::class, $dto);
        $this->assertSame($response, $dto->getResponse());
    }
}
