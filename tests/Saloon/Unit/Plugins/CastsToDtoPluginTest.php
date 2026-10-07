<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit\Plugins;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Exceptions\Request\Statuses\InternalServerErrorException;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\DtoConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Data\ApiResponse;
use Hypervel\Tests\Saloon\Fixtures\Data\User;
use Hypervel\Tests\Saloon\Fixtures\Requests\DTORequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use LogicException;

class CastsToDtoPluginTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testItCanCastToADtoThatIsDefinedOnTheRequest(): void
    {
        $mockClient = new MockClient([
            new MockResponse(['name' => 'Sammyjo20', 'actual_name' => 'Sam Carré', 'twitter' => '@carre_sam']),
        ]);

        $response = (new TestConnector)->send(new DTORequest, $mockClient);
        $dto = $response->dto();
        $json = $response->json();

        $this->assertTrue($response->isMocked());
        $this->assertInstanceOf(User::class, $dto);
        $this->assertSame($json['name'], $dto->name);
        $this->assertSame($json['actual_name'], $dto->actualName);
        $this->assertSame($json['twitter'], $dto->twitter);
    }

    public function testItCanCastToADtoThatIsDefinedOnAConnector(): void
    {
        $mockClient = new MockClient([
            new MockResponse(['name' => 'Sammyjo20', 'actual_name' => 'Sam Carré', 'twitter' => '@carre_sam']),
        ]);

        $connector = new DtoConnector;

        $response = $connector->send(new UserRequest, $mockClient);
        $dto = $response->dto();

        $this->assertInstanceOf(ApiResponse::class, $dto);
        $this->assertSame($response->json(), $dto->data);
    }

    public function testTheRequestDtoWillBeReturnedAsAHigherPriorityThanTheConnectorDto(): void
    {
        $mockClient = new MockClient([
            new MockResponse(['name' => 'Sammyjo20', 'actual_name' => 'Sam Carré', 'twitter' => '@carre_sam']),
        ]);

        $connector = new DtoConnector;

        $response = $connector->send(new DTORequest, $mockClient);
        $dto = $response->dto();
        $json = $response->json();

        $this->assertInstanceOf(User::class, $dto);
        $this->assertSame($json['name'], $dto->name);
        $this->assertSame($json['actual_name'], $dto->actualName);
        $this->assertSame($json['twitter'], $dto->twitter);
    }

    public function testYouCanUseTheDtoOrFailMethodToThrowAnExceptionIfTheResponseHasFailed(): void
    {
        $mockClient = new MockClient([
            new MockResponse(['message' => 'Server Error'], 500),
        ]);

        $response = (new TestConnector)->send(new DTORequest, $mockClient);

        try {
            $response->dtoOrFail();

            $this->fail('A failed response was converted into a data transfer object.');
        } catch (LogicException $exception) {
            $this->assertSame('Unable to create data transfer object as the response has failed.', $exception->getMessage());
            $this->assertInstanceOf(InternalServerErrorException::class, $exception->getPrevious());
        }
    }
}
