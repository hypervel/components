<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Resources\JsonApi;

use BadMethodCallException;
use Hypervel\Database\Eloquent\Attributes\UseResource;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Http\Resources\JsonApi\Exceptions\ResourceIdentificationException;
use Hypervel\Http\Resources\JsonApi\JsonApiRequest;
use Hypervel\Http\Resources\JsonApi\JsonApiResource;
use Hypervel\Tests\TestCase;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

class JsonApiResourceTest extends TestCase
{
    public function testResponseWrapperIsHardCodedToData(): void
    {
        JsonResource::wrap('hypervel');

        $this->assertSame('data', JsonApiResource::$wrap);
    }

    public function testUnableToSetWrapper(): void
    {
        $this->expectExceptionObject(new BadMethodCallException('Using Hypervel\Http\Resources\JsonApi\JsonApiResource::wrap() method is not allowed.'));

        JsonApiResource::wrap('hypervel');
    }

    public function testUnableToUnsetWrapper(): void
    {
        $this->expectExceptionObject(new BadMethodCallException('Using Hypervel\Http\Resources\JsonApi\JsonApiResource::withoutWrapping() method is not allowed.'));

        JsonApiResource::withoutWrapping();
    }

    public function testFlushStateResetsMaxRelationshipDepthToDefault(): void
    {
        $this->assertSame(5, JsonApiResource::$maxRelationshipDepth);

        JsonApiResource::maxRelationshipDepth(10);
        $this->assertSame(10, JsonApiResource::$maxRelationshipDepth);

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

    public function testDefaultAttributesExcludeReservedAndDeclaredRelationshipNames(): void
    {
        $child = new class extends JsonApiResourceTestModel {
            /**
             * Reject serialization of a discarded relationship.
             */
            #[Override]
            public function toArray(): array
            {
                throw new RuntimeException('The declared relationship should not be serialized as an attribute.');
            }
        };

        $model = (new JsonApiResourceTestModel)->forceFill([
            'id' => 1,
            'type' => 'record',
            'name' => 'Example',
            'secret' => 'Hidden',
        ])->makeHidden('secret')->setRelation('childRecords', $child);

        $resource = new class($model) extends JsonApiResource {
            protected array $relationships = ['childRecords'];
        };

        $this->assertSame(['name' => 'Example'], $resource->toAttributes(new JsonApiRequest));
        $this->assertSame(['secret'], $model->getHidden());

        $authored = new class([]) extends JsonApiResource {
            /**
             * Supply an explicitly authored attribute array.
             */
            #[Override]
            public function toArray(Request $request): array
            {
                return ['id' => 'authored'];
            }
        };

        $this->assertSame(['id' => 'authored'], $authored->toAttributes(new JsonApiRequest));
    }

    #[TestWith([['comments', 'unlisted'], ['comments']])]
    #[TestWith([['unlisted'], []])]
    #[TestWith([['comments.author'], ['comments']])]
    public function testRelativeRelationshipSelectionIsRestrictedToResourceDeclarations(array $requested, array $expected): void
    {
        $resource = new class([]) extends JsonApiResource {
            protected array $relationships = ['comments'];

            /**
             * Inspect declaration selection without invoking model methods.
             */
            public function selectedRelationships(array $requested): array
            {
                $this->requestedRelationships = $requested;

                return $this->getResourceRelationships(new JsonApiRequest)->keys()->all();
            }
        };

        $this->assertSame($expected, $resource->selectedRelationships($requested));
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

    public function testRelatedModelWithAPlainResourceIsIdentifiedAsAJsonApiResource(): void
    {
        $parent = (new JsonApiResourceParentModel)->forceFill(['id' => 1]);
        $parent->setRelation('child', (new JsonApiResourcePlainResourceModel)->forceFill(['id' => 2]));
        $resource = new JsonApiGuessedChildParentResource($parent);

        $data = $resource->resolveResourceData(JsonApiRequest::create('/?include=child'));

        $this->assertSame([
            'data' => ['id' => '2', 'type' => 'json_api_resource_plain_resource_models'],
        ], $data['relationships']->child);
    }
}

class JsonApiResourceTestModel extends Model
{
}

#[UseResource(JsonApiPlainResource::class)]
class JsonApiResourcePlainResourceModel extends JsonApiResourceTestModel
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

class JsonApiGuessedChildParentResource extends JsonApiResource
{
    /**
     * Transform the resource into an array.
     */
    public function toAttributes(Request $request): array
    {
        return [];
    }

    /**
     * Get the resource's relationships.
     */
    public function toRelationships(Request $request): array
    {
        return ['child'];
    }
}

class JsonApiPlainResource extends JsonResource
{
}
