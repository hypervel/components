<?php

declare(strict_types=1);

use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hypervel\ApiClient\ApiClient;
use Hypervel\ApiClient\ApiRequest;
use Hypervel\ApiClient\ApiResource;
use Hypervel\ApiClient\ApiResponse;
use Hypervel\ApiClient\PendingRequest;

use function PHPStan\Testing\assertType;

class ApiClientTypeResource extends ApiResource
{
}

class ApiClientTypeQuery implements JsonSerializable
{
    /**
     * Serialize the query data.
     *
     * @return array{status: string}
     */
    public function jsonSerialize(): array
    {
        return ['status' => 'open'];
    }
}

/**
 * @extends ApiClient<ApiClientTypeResource>
 */
class ConcreteApiClient extends ApiClient
{
    protected string $resource = ApiClientTypeResource::class;
}

$client = new ConcreteApiClient;
$pendingRequest = $client->createPendingRequest();

assertType('Hypervel\ApiClient\PendingRequest<ApiClientTypeResource>', $pendingRequest);
assertType('Hypervel\ApiClient\PendingRequest<ApiClientTypeResource>', $client->acceptJson());
assertType('Hypervel\ApiClient\PendingRequest<ApiClientTypeResource>', $client->withResource(ApiClientTypeResource::class));
assertType('Hypervel\ApiClient\PendingRequest<ApiClientTypeResource>', $pendingRequest->acceptJson()->timeout(10));
assertType('array', $pendingRequest->getOptions());
assertType('string|null', $pendingRequest->getConnection());
assertType(ClientInterface::class, $pendingRequest->buildClient());
assertType(HandlerStack::class, $pendingRequest->buildHandlerStack());

assertType(ApiClientTypeResource::class, $client->get('/issues'));
assertType(ApiClientTypeResource::class, $pendingRequest->get('/issues'));
assertType(ApiClientTypeResource::class, $pendingRequest->head('/issues'));
assertType(ApiClientTypeResource::class, $pendingRequest->head('/issues', new ApiClientTypeQuery));
assertType(ApiClientTypeResource::class, $pendingRequest->query('/issues'));
assertType(ApiClientTypeResource::class, $pendingRequest->post('/issues'));
assertType(ApiClientTypeResource::class, $pendingRequest->patch('/issues'));
assertType(ApiClientTypeResource::class, $pendingRequest->put('/issues'));
assertType(ApiClientTypeResource::class, $pendingRequest->delete('/issues'));
assertType(ApiClientTypeResource::class, $pendingRequest->send('OPTIONS', '/issues'));

$basePendingRequest = new PendingRequest;

assertType(ApiResource::class, $basePendingRequest->get('/issues'));
assertType('Hypervel\ApiClient\PendingRequest<ApiClientTypeResource>', $basePendingRequest->withResource(ApiClientTypeResource::class));
assertType(ApiClientTypeResource::class, $basePendingRequest->get('/issues'));
assertType(ApiClientTypeResource::class, (new PendingRequest)->withResource(ApiClientTypeResource::class)->get('/issues'));

$apiRequest = new ApiRequest(new Request('POST', '/issues'));

assertType(ApiRequest::class, $apiRequest->withMethod('PUT'));
assertType(ApiRequest::class, $apiRequest->withHeader('X-Trace', 'trace'));
assertType(ApiRequest::class, $apiRequest->withData(['title' => 'Example']));
assertType(ApiRequest::class, $apiRequest->mergeData(['body' => 'Details']));
assertType(ApiRequest::class, $apiRequest->withoutData('body'));

$apiResponse = new ApiResponse(new Response(200, [], '{"id": 1}'));
$resource = ApiClientTypeResource::make($apiResponse, $apiRequest);

assertType('array<mixed>', $apiResponse->toArray());
assertType('array<mixed>', $resource->toArray());
assertType('Hypervel\Support\Collection<(int|string), mixed>', collect($apiResponse));
assertType('Hypervel\Support\Collection<(int|string), mixed>', collect($resource));
