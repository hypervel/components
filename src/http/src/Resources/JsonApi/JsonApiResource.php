<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\JsonApi;

use BadMethodCallException;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Support\Arr;
use JsonSerializable;
use Override;

class JsonApiResource extends JsonResource
{
    use Concerns\ResolvesJsonApiElements;
    use Concerns\ResolvesJsonApiRequest;

    /**
     * The "data" wrapper that should be applied.
     */
    public static ?string $wrap = 'data';

    /**
     * The resource's "version" for JSON:API.
     *
     * @var array{version?: string, ext?: array, profile?: array, meta?: array}
     */
    public static array $jsonApiInformation = [];

    /**
     * The resource's "links" for JSON:API.
     */
    protected array $jsonApiLinks = [];

    /**
     * The resource's "meta" for JSON:API.
     */
    protected array $jsonApiMeta = [];

    /**
     * Set the JSON:API version for the request.
     */
    public static function configure(?string $version = null, array $ext = [], array $profile = [], array $meta = []): void
    {
        static::$jsonApiInformation = array_filter([
            'version' => $version,
            'ext' => $ext,
            'profile' => $profile,
            'meta' => $meta,
        ]);
    }

    /**
     * Get the resource's ID.
     */
    public function toId(Request $request): ?string
    {
        return null;
    }

    /**
     * Get the resource's type.
     */
    public function toType(Request $request): ?string
    {
        return null;
    }

    /**
     * Transform the resource into an array.
     */
    #[Override]
    public function toAttributes(Request $request): array|Arrayable|JsonSerializable
    {
        if (property_exists($this, 'attributes')) {
            return $this->attributes;
        }

        return $this->toArray($request);
    }

    /**
     * Get the resource's relationships.
     */
    public function toRelationships(Request $request): Arrayable|array
    {
        if (property_exists($this, 'relationships')) {
            return $this->relationships;
        }

        return [];
    }

    /**
     * Get the resource's links.
     */
    public function toLinks(Request $request): array
    {
        return $this->jsonApiLinks;
    }

    /**
     * Get the resource's meta information.
     */
    public function toMeta(Request $request): array
    {
        return $this->jsonApiMeta;
    }

    /**
     * Get any additional data that should be returned with the resource array.
     */
    #[Override]
    public function with(Request $request): array
    {
        $jsonApiRequest = $this->resolveJsonApiRequestFrom($request);

        return array_filter([
            'included' => $this->resolveIncludedResourceObjects($jsonApiRequest)
                ->uniqueStrict('_uniqueKey')
                ->map(fn ($included) => Arr::except($included, ['_uniqueKey']))
                ->values()
                ->all(),
            ...($implementation = static::$jsonApiInformation)
                ? ['jsonapi' => $implementation]
                : [],
        ]);
    }

    /**
     * Resolve the resource to an array.
     */
    #[Override]
    public function resolve(?Request $request = null): array
    {
        return [
            'data' => $this->resolveResourceData($this->resolveJsonApiRequestFrom($request ?? $this->resolveRequestFromContainer())),
        ];
    }

    /**
     * Resolve the resource data to an array.
     */
    #[Override]
    public function resolveResourceData(Request $request): array|Arrayable|JsonSerializable
    {
        return $this->resolveResourceObject($this->resolveJsonApiRequestFrom($request));
    }

    /**
     * Customize the outgoing response for the resource.
     */
    #[Override]
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->header('Content-Type', 'application/vnd.api+json');
    }

    /**
     * Create an HTTP response that represents the object.
     */
    #[Override]
    public function toResponse(Request $request): JsonResponse
    {
        return parent::toResponse($this->resolveJsonApiRequestFrom($request));
    }

    /**
     * Resolve the HTTP request instance from container.
     */
    #[Override]
    protected function resolveRequestFromContainer(): JsonApiRequest
    {
        return $this->resolveJsonApiRequestFrom(parent::resolveRequestFromContainer());
    }

    /**
     * Create a new resource collection instance.
     */
    #[Override]
    protected static function newCollection(mixed $resource): AnonymousResourceCollection
    {
        return new AnonymousResourceCollection($resource, static::class);
    }

    /**
     * Set the string that should wrap the outer-most resource array.
     *
     * @throws BadMethodCallException
     */
    #[Override]
    public static function wrap(string $value): never
    {
        throw new BadMethodCallException(sprintf('Using %s() method is not allowed.', __METHOD__));
    }

    /**
     * Disable wrapping of the outer-most resource array.
     *
     * @throws BadMethodCallException
     */
    #[Override]
    public static function withoutWrapping(): never
    {
        throw new BadMethodCallException(sprintf('Using %s() method is not allowed.', __METHOD__));
    }

    /**
     * Flush the resource's global state.
     */
    #[Override]
    public static function flushState(): void
    {
        parent::flushState();

        static::$jsonApiInformation = [];
        static::$maxRelationshipDepth = 3;
    }
}
