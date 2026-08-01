<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Resources;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Http\Resources\JsonApi\Exceptions\ResourceIdentificationException;
use Hypervel\Http\Resources\JsonApi\JsonApiRequest;
use Hypervel\Http\Resources\JsonApi\JsonApiResource;
use Hypervel\Tests\TestCase;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;

class JsonApiResourceTest extends TestCase
{
    public function testFlushStateRestoresDefaultRelationshipDepth(): void
    {
        $this->assertSame(5, JsonApiResource::$maxRelationshipDepth);

        JsonApiResource::maxRelationshipDepth(2);

        $this->assertSame(2, JsonApiResource::$maxRelationshipDepth);

        JsonApiResource::flushState();

        $this->assertSame(5, JsonApiResource::$maxRelationshipDepth);
    }

    public function testNullFallbackModelKeyCannotBecomeAnEmptyResourceIdentifier(): void
    {
        $resource = new JsonApiResource(new JsonApiResourceTestModel);

        $this->expectException(ResourceIdentificationException::class);

        $resource->resolveResourceIdentifier(new JsonApiRequest);
    }

    public function testNullGenericKeyCannotBecomeAnEmptyResourceIdentifier(): void
    {
        $resource = new JsonApiResource(new JsonApiResourceTestKeyObject(null));

        $this->expectException(ResourceIdentificationException::class);

        $resource->resolveResourceIdentifier(new JsonApiRequest);
    }

    #[DataProvider('fallbackResourceIdentifierProvider')]
    public function testValidFallbackResourceIdentifiersArePreserved(object $value, string $expected): void
    {
        $resource = new JsonApiResource($value);

        $this->assertSame($expected, $resource->resolveResourceIdentifier(new JsonApiRequest));
    }

    public static function fallbackResourceIdentifierProvider(): array
    {
        return [
            'integer zero model key' => [(new JsonApiResourceTestModel)->forceFill(['id' => 0]), '0'],
            'string zero model key' => [(new JsonApiResourceTestModel)->forceFill(['id' => '0']), '0'],
            'generic key object' => [new JsonApiResourceTestKeyObject('generic-id'), 'generic-id'],
        ];
    }

    public function testCustomResourceIdentifierIsPreservedWithoutAFallbackKey(): void
    {
        $resource = new JsonApiResourceWithCustomIdentifier(new JsonApiResourceTestModel);

        $this->assertSame('custom-id', $resource->resolveResourceIdentifier(new JsonApiRequest));
    }

    public function testEmptySparseFieldsetOmitsResourceAttributes(): void
    {
        $resource = new JsonApiPostResource(new JsonApiResourceTestPost(1, 'Post title'));

        $data = $resource->resolveResourceData(JsonApiRequest::create(uri: '/'));

        $this->assertSame(['id', 'type', 'attributes'], array_keys($data));
        $this->assertSame('Post title', $data['attributes']->title);

        $request = JsonApiRequest::create(uri: '/?' . http_build_query([
            'fields' => ['posts' => ''],
        ]));

        $this->assertSame([
            'id' => '1',
            'type' => 'posts',
        ], $resource->resolveResourceData($request));
    }

    public function testNullRelationshipKeyCannotBecomeAnEmptyResourceIdentifier(): void
    {
        $parent = (new JsonApiResourceParentModel)->forceFill(['id' => 1]);
        $parent->setRelation('child', new JsonApiResourceTestModel);
        $resource = new JsonApiParentResource($parent);
        $request = JsonApiRequest::create('/?include=child');

        $this->expectException(ResourceIdentificationException::class);

        $resource->resolveResourceData($request);
    }
}

class JsonApiResourceTestModel extends Model
{
}

class JsonApiResourceParentModel extends JsonApiResourceTestModel
{
    /**
     * Retain the relationship loaded directly by the test fixture.
     */
    #[Override]
    public function loadMissing(array|string $relations): static
    {
        return $this;
    }
}

class JsonApiResourceTestKeyObject
{
    public function __construct(private readonly mixed $key)
    {
    }

    public function getKey(): mixed
    {
        return $this->key;
    }
}

class JsonApiResourceTestPost
{
    public function __construct(
        private readonly int $key,
        public readonly string $title,
    ) {
    }

    public function getKey(): int
    {
        return $this->key;
    }
}

class JsonApiResourceWithCustomIdentifier extends JsonApiResource
{
    public function toId(Request $request): ?string
    {
        return 'custom-id';
    }
}

class JsonApiPostResource extends JsonApiResource
{
    public function toType(Request $request): ?string
    {
        return 'posts';
    }

    public function toAttributes(Request $request): array
    {
        return ['title' => $this->resource->title];
    }
}

class JsonApiParentResource extends JsonApiResource
{
    public function toAttributes(Request $request): array
    {
        return [];
    }

    public function toRelationships(Request $request): array
    {
        return ['child' => JsonApiChildResource::class];
    }
}

class JsonApiChildResource extends JsonApiResource
{
    public function toAttributes(Request $request): array
    {
        return [];
    }
}
