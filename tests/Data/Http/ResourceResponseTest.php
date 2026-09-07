<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Http\ResourceResponseTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Concerns\BaseData as BaseDataConcern;
use Hypervel\Data\Concerns\IncludeableData as IncludeableDataConcern;
use Hypervel\Data\Concerns\TransformableData as TransformableDataConcern;
use Hypervel\Data\Contracts\BaseData as BaseDataContract;
use Hypervel\Data\Contracts\IncludeableData as IncludeableDataContract;
use Hypervel\Data\Contracts\TransformableData as TransformableDataContract;
use Hypervel\Data\CursorPaginatedDataCollection;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Dto;
use Hypervel\Data\Http\Resources\DataCollectionResource;
use Hypervel\Data\Http\Resources\DataResource;
use Hypervel\Data\Lazy;
use Hypervel\Data\PaginatedDataCollection;
use Hypervel\Data\Resource;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Testbench\TestCase;

use function Hypervel\Coroutine\parallel;

abstract class ResourceResponseTestCase extends TestCase
{
    /**
     * Get package providers for the resource response test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }
}

class ResourceResponseTest extends ResourceResponseTestCase
{
    public function testDataAndResourceResponsesAreUnwrappedByDefault(): void
    {
        $data = new ResponseData(1, 'Taylor');
        $resource = new ResponseResource(2, 'Abigail');

        $dataResponse = $data->toResponse(Request::create('/'));
        $resourceResponse = $resource->toResponse(Request::create('/'));

        $this->assertSame(['id' => 1, 'name' => 'Taylor'], $dataResponse->getData(true));
        $this->assertSame(['id' => 2, 'name' => 'Abigail'], $resourceResponse->getData(true));
        $this->assertSame($data, $dataResponse->getOriginalContent());
        $this->assertSame($resource, $resourceResponse->getOriginalContent());
    }

    public function testAdditionalDataUsesTheLaravelFallbackWrapper(): void
    {
        $data = (new ResponseData(1, 'Taylor'))
            ->withoutWrapping()
            ->additional(['meta' => ['source' => 'test']]);

        $this->assertSame([
            'data' => ['id' => 1, 'name' => 'Taylor'],
            'meta' => ['source' => 'test'],
        ], $data->toResponse(Request::create('/'))->getData(true));
    }

    public function testExplicitWrappingAndResponseCollisionsFollowResourceResponseOnce(): void
    {
        $wrapped = (new ResponseData(1, 'Taylor'))->wrap('payload');
        $collision = (new CollisionResponseData(['value' => 'body']))
            ->wrap('data')
            ->additional(['additional' => true]);

        $this->assertSame([
            'payload' => ['id' => 1, 'name' => 'Taylor'],
        ], $wrapped->toResponse(Request::create('/'))->getData(true));
        $this->assertSame([
            'data' => ['value' => 'body'],
            'with' => true,
            'additional' => true,
        ], $collision->toResponse(Request::create('/'))->getData(true));
    }

    public function testJsonOptionsAndResponseHookAreDelegatedWithoutChangingTheOriginal(): void
    {
        $data = new HookedResponseData('https://hypervel.org/data');
        $response = $data->toResponse(Request::create('/', 'POST'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('https://hypervel.org/data', $response->getContent());
        $this->assertSame('applied', $response->headers->get('X-Data-Hook'));
        $this->assertSame($data, $response->getOriginalContent());
    }

    public function testAllowedRequestIncludesAreAppliedToResponseTransformation(): void
    {
        $excluded = new LazyResponseData(1, Lazy::create(static fn (): string => 'secret'));
        $included = new LazyResponseData(1, Lazy::create(static fn (): string => 'secret'));

        $this->assertSame(
            ['id' => 1],
            $excluded->toResponse(Request::create('/'))->getData(true),
        );
        $this->assertSame(
            ['id' => 1, 'secret' => 'secret'],
            $included->toResponse(Request::create('/?include=secret'))->getData(true),
        );
    }

    public function testDtoCollectionsRemainResponseCapableAndDenyRequestPartials(): void
    {
        $collection = new DataCollection(ResponseDto::class, [
            ['id' => 1, 'secret' => 'visible'],
        ]);
        $items = $collection->toCollection();
        $response = $collection->toResponse(Request::create('/?only=id'));

        $this->assertSame([
            ['id' => 1, 'secret' => 'visible'],
        ], $response->getData(true));
        $this->assertSame($items, $response->getOriginalContent());
        $this->assertSame($collection[0], $response->getOriginalContent()[0]);
    }

    public function testNonResponsableTransformableItemsDenyRequestPartials(): void
    {
        $item = new ModularResponseData(
            1,
            Lazy::create(static fn (): string => 'secret'),
        );
        $collection = new DataCollection(ModularResponseData::class, [$item]);

        $this->assertSame([
            ['id' => 1],
        ], $collection->toResponse(Request::create('/?include=secret'))->getData(true));
    }

    public function testLazyCollectionResponseMaterializesTheSourceOnce(): void
    {
        $iterations = 0;
        $source = LazyCollection::make(function () use (&$iterations): iterable {
            ++$iterations;

            yield 'first' => ['id' => 1, 'name' => 'Taylor'];
            yield 'second' => ['id' => 2, 'name' => 'Abigail'];
        });
        $collection = new DataCollection(ResponseData::class, $source);
        $response = $collection->toResponse(Request::create('/'));
        $original = $response->getOriginalContent();

        $this->assertSame(1, $iterations);
        $this->assertSame([
            'first' => ['id' => 1, 'name' => 'Taylor'],
            'second' => ['id' => 2, 'name' => 'Abigail'],
        ], $response->getData(true));
        $this->assertInstanceOf(Collection::class, $original);
        $this->assertInstanceOf(ResponseData::class, $original['first']);
        $this->assertSame('Abigail', $original['second']->name);
    }

    public function testPaginatorResponsesPreserveMetadataAndOriginalDtoItems(): void
    {
        $paginated = new PaginatedDataCollection(
            ResponseDto::class,
            new Paginator(
                [['id' => 1, 'secret' => 'first']],
                15,
                2,
                ['path' => '/items'],
            ),
        );
        $cursorPaginated = new CursorPaginatedDataCollection(
            ResponseDto::class,
            new CursorPaginator(
                [['id' => 2, 'secret' => 'second']],
                15,
                null,
                ['path' => '/cursor-items'],
            ),
        );

        $paginatedResponse = $paginated->toResponse(Request::create('/items?only=id'));
        $cursorResponse = $cursorPaginated->toResponse(Request::create('/cursor-items?only=id'));
        $paginatedBody = $paginatedResponse->getData(true);
        $cursorBody = $cursorResponse->getData(true);

        $this->assertSame([
            ['id' => 1, 'secret' => 'first'],
        ], $paginatedBody['data']);
        $this->assertSame(2, $paginatedBody['meta']['current_page']);
        $this->assertSame('/items', $paginatedBody['meta']['path']);
        $this->assertSame([
            ['id' => 2, 'secret' => 'second'],
        ], $cursorBody['data']);
        $this->assertSame('/cursor-items', $cursorBody['meta']['path']);
        $this->assertSame(15, $cursorBody['meta']['per_page']);
        $this->assertSame(
            $paginated->items()->getCollection()[0],
            $paginatedResponse->getOriginalContent()[0],
        );
        $this->assertSame(
            $cursorPaginated->items()->getCollection()[0],
            $cursorResponse->getOriginalContent()[0],
        );
    }

    public function testCollectionJsonOptionsUseTheDeclaredItemClassWithoutInstantiation(): void
    {
        $collection = new DataCollection(AbstractJsonOptionsData::class, []);
        $resource = new DataCollectionResource(
            $collection,
            new Collection,
            [],
            null,
        );
        $dtoCollection = new DataCollection(ConstructorRequiredDto::class, []);
        $dtoResource = new DataCollectionResource(
            $dtoCollection,
            new Collection,
            [],
            null,
        );

        $this->assertSame(JSON_UNESCAPED_SLASHES, $resource->jsonOptions());
        $this->assertSame(0, $dtoResource->jsonOptions());
    }

    public function testDataResourceResolveBypassesTheGenericConditionalFilter(): void
    {
        $resource = new FilterSpyDataResource(
            new ResponseData(1, 'Taylor'),
            ['id' => 1, 'name' => 'Taylor'],
            null,
        );

        $this->assertSame(
            ['id' => 1, 'name' => 'Taylor'],
            $resource->resolve(),
        );
        $this->assertFalse(FilterSpyDataResource::$filterCalled);
    }

    public function testConcurrentResponsesKeepWrappingAndAdditionalDataIsolated(): void
    {
        [$first, $second] = parallel([
            function (): array {
                $data = (new ResponseData(1, 'Taylor'))
                    ->wrap('first')
                    ->additional(['source' => 'first']);

                usleep(5000);

                return $data->toResponse(Request::create('/'))->getData(true);
            },
            function (): array {
                $data = (new ResponseData(2, 'Abigail'))
                    ->wrap('second')
                    ->additional(['source' => 'second']);

                usleep(1000);

                return $data->toResponse(Request::create('/'))->getData(true);
            },
        ]);

        $this->assertSame([
            'first' => ['id' => 1, 'name' => 'Taylor'],
            'source' => 'first',
        ], $first);
        $this->assertSame([
            'second' => ['id' => 2, 'name' => 'Abigail'],
            'source' => 'second',
        ], $second);
    }
}

class GlobalWrappingResourceResponseTest extends ResourceResponseTestCase
{
    /**
     * Define the test environment.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('data.wrap', 'global');
    }

    public function testGlobalWrappingCanBeOverriddenPerResponse(): void
    {
        $global = new ResponseData(1, 'Taylor');
        $explicit = (new ResponseData(2, 'Abigail'))->wrap('payload');
        $unwrapped = (new ResponseData(3, 'Jess'))->withoutWrapping();

        $this->assertSame([
            'global' => ['id' => 1, 'name' => 'Taylor'],
        ], $global->toResponse(Request::create('/'))->getData(true));
        $this->assertSame([
            'payload' => ['id' => 2, 'name' => 'Abigail'],
        ], $explicit->toResponse(Request::create('/'))->getData(true));
        $this->assertSame(
            ['id' => 3, 'name' => 'Jess'],
            $unwrapped->toResponse(Request::create('/'))->getData(true),
        );
    }
}

class ResponseData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }
}

class ResponseResource extends Resource
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }
}

class CollisionResponseData extends Data
{
    public function __construct(public array $data)
    {
    }

    /**
     * Get top-level response data.
     */
    public function with(): array
    {
        return ['with' => true];
    }
}

class HookedResponseData extends Data
{
    public function __construct(public string $url)
    {
    }

    /**
     * Get the JSON serialization options for the resource response.
     */
    public static function jsonOptions(): int
    {
        return JSON_UNESCAPED_SLASHES;
    }

    /**
     * Customize the outgoing resource response.
     */
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->headers->set('X-Data-Hook', 'applied');
    }
}

class LazyResponseData extends Data
{
    public function __construct(
        public int $id,
        public Lazy|string $secret,
    ) {
    }

    /**
     * Get the request properties that may be included.
     */
    public static function allowedRequestIncludes(): ?array
    {
        return ['secret'];
    }
}

class ResponseDto extends Dto
{
    public function __construct(
        public int $id,
        public string $secret,
    ) {
    }
}

class ConstructorRequiredDto extends Dto
{
    public function __construct(public string $value)
    {
    }
}

class ModularResponseData implements BaseDataContract, IncludeableDataContract, TransformableDataContract
{
    use BaseDataConcern;
    use IncludeableDataConcern;
    use TransformableDataConcern;

    public function __construct(
        public int $id,
        public Lazy|string $secret,
    ) {
    }
}

abstract class AbstractJsonOptionsData extends Data
{
    /**
     * Get the JSON serialization options for the resource response.
     */
    public static function jsonOptions(): int
    {
        return JSON_UNESCAPED_SLASHES;
    }
}

class FilterSpyDataResource extends DataResource
{
    public static bool $filterCalled = false;

    /**
     * Mark use of the generic resource filter.
     */
    protected function filter(array $data): array
    {
        self::$filterCalled = true;

        return parent::filter($data);
    }
}
