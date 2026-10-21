<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Exceptions\Request\ClientException;
use Hypervel\Saloon\Exceptions\Request\RequestException;
use Hypervel\Saloon\Exceptions\Request\ServerException;
use Hypervel\Saloon\Exceptions\Request\Statuses\BadGatewayException;
use Hypervel\Saloon\Exceptions\Request\Statuses\BadRequestException;
use Hypervel\Saloon\Exceptions\Request\Statuses\ConflictException;
use Hypervel\Saloon\Exceptions\Request\Statuses\ForbiddenException;
use Hypervel\Saloon\Exceptions\Request\Statuses\GatewayTimeoutException;
use Hypervel\Saloon\Exceptions\Request\Statuses\InternalServerErrorException;
use Hypervel\Saloon\Exceptions\Request\Statuses\MethodNotAllowedException;
use Hypervel\Saloon\Exceptions\Request\Statuses\NotFoundException;
use Hypervel\Saloon\Exceptions\Request\Statuses\PaymentRequiredException;
use Hypervel\Saloon\Exceptions\Request\Statuses\RequestTimeOutException;
use Hypervel\Saloon\Exceptions\Request\Statuses\ServiceUnavailableException;
use Hypervel\Saloon\Exceptions\Request\Statuses\TooManyRequestsException;
use Hypervel\Saloon\Exceptions\Request\Statuses\UnauthorizedException;
use Hypervel\Saloon\Exceptions\Request\Statuses\UnprocessableEntityException;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\AlwaysHasFailureRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use PHPUnit\Framework\Attributes\DataProvider;

// Saloon request exceptions extend the HTTP client's request exception, so their messages use its format and
// truncation instead of upstream's "Not Found (404) Response: ..." format.
class RequestExceptionTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    #[DataProvider('statusExceptions')]
    public function testTheResponseWillReturnDifferentExceptionsBasedOnStatus(int $status, string $expectedException): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['message' => 'Oh yee-naw!'], $status),
        ]);

        $response = TestConnector::make()->send(new UserRequest, $mockClient);
        $exception = $response->toException();

        $this->assertInstanceOf($expectedException, $exception);
        $this->assertSame(static::message($response), $exception->getMessage());
    }

    /**
     * Get the statuses and the exceptions they produce.
     *
     * @return array<int, array{int, class-string<RequestException>}>
     */
    public static function statusExceptions(): array
    {
        return [
            [400, BadRequestException::class],
            [401, UnauthorizedException::class],
            [402, PaymentRequiredException::class],
            [403, ForbiddenException::class],
            [404, NotFoundException::class],
            [405, MethodNotAllowedException::class],
            [408, RequestTimeOutException::class],
            [409, ConflictException::class],
            [422, UnprocessableEntityException::class],
            [429, TooManyRequestsException::class],
            [500, InternalServerErrorException::class],
            [502, BadGatewayException::class],
            [503, ServiceUnavailableException::class],
            [504, GatewayTimeoutException::class],
            [418, ClientException::class],
            [411, ClientException::class],
            [501, ServerException::class],
        ];
    }

    #[DataProvider('customFailureExceptions')]
    public function testWhenTheFailedMethodIsCustomisedTheResponseWillReturnOkRequestExceptions(int $status, string $expectedException): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['message' => 'Oh yee-naw!'], $status),
        ]);

        $response = TestConnector::make()->send(new AlwaysHasFailureRequest, $mockClient);
        $exception = $response->toException();

        $this->assertSame($expectedException, $exception::class);
        $this->assertSame(static::message($response), $exception->getMessage());
    }

    /**
     * Get the non-error statuses that a custom failure check turns into exceptions.
     *
     * @return array<int, array{int, class-string<RequestException>}>
     */
    public static function customFailureExceptions(): array
    {
        return [
            [302, RequestException::class],
            [200, RequestException::class],
            [201, RequestException::class],
            [100, RequestException::class],
        ];
    }

    /**
     * Get the message the HTTP client's request exception gives a response.
     */
    protected static function message(Response $response): string
    {
        return sprintf("HTTP request returned status code %d:\n%s\n", $response->status(), $response->body());
    }
}
