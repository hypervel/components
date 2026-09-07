<?php

declare(strict_types=1);

use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Hypervel\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Hypervel\Contracts\Pagination\Paginator as PaginatorContract;
use Hypervel\Data\Contracts\BaseData as BaseDataContract;
use Hypervel\Data\Contracts\ResponsableData;
use Hypervel\Data\Contracts\TransformableData;
use Hypervel\Data\Contracts\ValidateableData;
use Hypervel\Data\CursorPaginatedDataCollection;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\Dto;
use Hypervel\Data\PaginatedDataCollection;
use Hypervel\Data\Resource;
use Hypervel\Data\WithData;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Pagination\AbstractCursorPaginator;
use Hypervel\Pagination\AbstractPaginator;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use Hypervel\Support\LazyCollection;

use function PHPStan\Testing\assertType;

class DataTypeUserData extends Data
{
    public function __construct(public int $id)
    {
    }
}

class DataTypeUserDto extends Dto
{
    public function __construct(public int $id)
    {
    }
}

class DataTypeUserResource extends Resource
{
    public function __construct(public int $id)
    {
    }
}

class DataTypeUserModel extends Model
{
    /** @use WithData<DataTypeUserData> */
    use WithData;

    protected string $dataClass = DataTypeUserData::class;
}

/** @return array<string, array{id: int}> */
function dataTypeStringKeyedRows(): array
{
    return ['first' => ['id' => 1]];
}

/** @return class-string */
function dataTypeDynamicCollectionTarget(): string
{
    return Collection::class;
}

/** @return PaginatorContract<int, array{id: int}> */
function dataTypePaginatorContract(): PaginatorContract
{
    return new Paginator([['id' => 1]], 10, 1);
}

/** @return CursorPaginatorContract<int, array{id: int}> */
function dataTypeCursorPaginatorContract(): CursorPaginatorContract
{
    return new CursorPaginator([['id' => 1]], 10);
}

/** @return LengthAwarePaginatorContract<int, array{id: int}> */
function dataTypeLengthAwarePaginatorContract(): LengthAwarePaginatorContract
{
    return new LengthAwarePaginator([['id' => 1]], 1, 10);
}

assertType(DataTypeUserData::class, DataTypeUserData::from(['id' => 1]));
assertType(DataTypeUserData::class . '|null', DataTypeUserData::optional(null));
assertType('Hypervel\Data\Support\Creation\CreationContextFactory<DataTypeUserData>', DataTypeUserData::factory());
assertType(DataTypeUserData::class, (new DataTypeUserModel)->getData());

$array = DataTypeUserData::collect(dataTypeStringKeyedRows());
$collection = DataTypeUserData::collect(new Collection(dataTypeStringKeyedRows()));
$lazyCollection = DataTypeUserData::collect(new LazyCollection(dataTypeStringKeyedRows()));
$eloquentCollection = DataTypeUserData::collect(new EloquentCollection([new DataTypeUserModel]));
$dataCollection = DataTypeUserData::collect(dataTypeStringKeyedRows(), DataCollection::class);
$directDataCollection = new DataCollection(DataTypeUserData::class, dataTypeStringKeyedRows());
$sourceDataCollection = DataTypeUserData::collect($directDataCollection);
$factoryDataCollection = DataTypeUserData::factory()->collect(dataTypeStringKeyedRows(), DataCollection::class);

/** @var class-string<BaseDataContract> $baseDataClass */
$baseDataClass = DataTypeUserData::class;
$baseDataCollection = $baseDataClass::collect(dataTypeStringKeyedRows(), DataCollection::class);

$explicitArray = DataTypeUserData::collect(dataTypeStringKeyedRows(), 'array');
$explicitEnumerable = DataTypeUserData::collect(dataTypeStringKeyedRows(), Enumerable::class);
$explicitEloquentCollection = DataTypeUserData::collect(dataTypeStringKeyedRows(), EloquentCollection::class);
$explicitCollection = DataTypeUserData::collect(dataTypeStringKeyedRows(), Collection::class);
$explicitLazyCollection = DataTypeUserData::collect(dataTypeStringKeyedRows(), LazyCollection::class);
$explicitDataCollection = DataTypeUserData::collect(dataTypeStringKeyedRows(), DataCollection::class);

/** @var ArrayIterator<string, array{id: int}> $iterator */
$iterator = new ArrayIterator(dataTypeStringKeyedRows());
$iteratorCollection = DataTypeUserData::collect($iterator, Collection::class);
$dynamicTarget = DataTypeUserData::collect(dataTypeStringKeyedRows(), dataTypeDynamicCollectionTarget());

assertType('array<string, DataTypeUserData>', $array);
assertType('Hypervel\Support\Collection<string, DataTypeUserData>', $collection);
assertType('Hypervel\Support\LazyCollection<string, DataTypeUserData>', $lazyCollection);
assertType('Hypervel\Support\Collection<int, DataTypeUserData>', $eloquentCollection);
assertType('Hypervel\Data\DataCollection<string, DataTypeUserData>', $dataCollection);
assertType('array<string, DataTypeUserData>', $dataCollection->items());
assertType(DataTypeUserData::class, $dataCollection['first']);
assertType('Hypervel\Data\DataCollection<string, DataTypeUserData>', $directDataCollection);
assertType('Hypervel\Data\DataCollection<string, DataTypeUserData>', $sourceDataCollection);
assertType('Hypervel\Data\DataCollection<string, DataTypeUserData>', $factoryDataCollection);
assertType('Hypervel\Data\DataCollection<string, Hypervel\Data\Contracts\BaseData>', $baseDataCollection);
assertType('array<string, DataTypeUserData>', $explicitArray);
assertType('Hypervel\Support\Collection<string, DataTypeUserData>', $explicitEnumerable);
assertType('Hypervel\Support\Collection<string, DataTypeUserData>', $explicitEloquentCollection);
assertType('Hypervel\Support\Collection<string, DataTypeUserData>', $explicitCollection);
assertType('Hypervel\Support\LazyCollection<string, DataTypeUserData>', $explicitLazyCollection);
assertType('Hypervel\Data\DataCollection<string, DataTypeUserData>', $explicitDataCollection);
assertType('Hypervel\Support\Collection<string, DataTypeUserData>', $iteratorCollection);
assertType('array<string, DataTypeUserData>|Hypervel\Contracts\Pagination\CursorPaginator<string, DataTypeUserData>|Hypervel\Contracts\Pagination\Paginator<string, DataTypeUserData>|Hypervel\Data\CursorPaginatedDataCollection<string, DataTypeUserData>|Hypervel\Data\DataCollection<string, DataTypeUserData>|Hypervel\Data\PaginatedDataCollection<string, DataTypeUserData>|Hypervel\Pagination\AbstractCursorPaginator<string, DataTypeUserData>|Hypervel\Pagination\AbstractPaginator<string, DataTypeUserData>|Hypervel\Support\Enumerable<string, DataTypeUserData>', $dynamicTarget);

/** @var Paginator<int, array{id: int}> $paginator */
$paginator = new Paginator([['id' => 1]], 10, 1);
/** @var CursorPaginator<int, array{id: int}> $cursorPaginator */
$cursorPaginator = new CursorPaginator([['id' => 1]], 10);
/** @var LengthAwarePaginator<int, array{id: int}> $lengthAwarePaginator */
$lengthAwarePaginator = new LengthAwarePaginator([['id' => 1]], 1, 10);
/** @var AbstractPaginator<int, array{id: int}> $abstractPaginator */
$abstractPaginator = $paginator;
/** @var AbstractCursorPaginator<int, array{id: int}> $abstractCursorPaginator */
$abstractCursorPaginator = $cursorPaginator;

$paginated = DataTypeUserData::collect($paginator);
$paginatedData = DataTypeUserData::collect($paginator, PaginatedDataCollection::class);
$cursorPaginated = DataTypeUserData::collect($cursorPaginator);
$cursorPaginatedData = DataTypeUserData::collect($cursorPaginator, CursorPaginatedDataCollection::class);
$lengthAwarePaginated = DataTypeUserData::collect($lengthAwarePaginator);
$abstractPaginated = DataTypeUserData::collect($abstractPaginator);
$abstractCursorPaginated = DataTypeUserData::collect($abstractCursorPaginator);
$contractPaginated = DataTypeUserData::collect(dataTypePaginatorContract());
$contractCursorPaginated = DataTypeUserData::collect(dataTypeCursorPaginatorContract());
$contractLengthAwarePaginated = DataTypeUserData::collect(dataTypeLengthAwarePaginatorContract());
$explicitPaginator = DataTypeUserData::collect($paginator, Paginator::class);
$explicitCursorPaginator = DataTypeUserData::collect($cursorPaginator, CursorPaginator::class);
$explicitLengthAwarePaginator = DataTypeUserData::collect($lengthAwarePaginator, LengthAwarePaginator::class);
$explicitAbstractPaginator = DataTypeUserData::collect($paginator, AbstractPaginator::class);
$explicitAbstractCursorPaginator = DataTypeUserData::collect($cursorPaginator, AbstractCursorPaginator::class);
$explicitPaginatorContract = DataTypeUserData::collect($paginator, PaginatorContract::class);
$explicitCursorPaginatorContract = DataTypeUserData::collect($cursorPaginator, CursorPaginatorContract::class);
$explicitLengthAwareContract = DataTypeUserData::collect($lengthAwarePaginator, LengthAwarePaginatorContract::class);
$explicitPaginatedData = DataTypeUserData::collect($paginator, PaginatedDataCollection::class);
$explicitCursorPaginatedData = DataTypeUserData::collect($cursorPaginator, CursorPaginatedDataCollection::class);
$sourcePaginatedData = DataTypeUserData::collect($paginatedData);
$sourceCursorPaginatedData = DataTypeUserData::collect($cursorPaginatedData);

/** @var Paginator<string, array{id: int}> $stringKeyedPaginator */
$stringKeyedPaginator = new Paginator(dataTypeStringKeyedRows(), 10, 1);
/** @var CursorPaginator<string, array{id: int}> $stringKeyedCursorPaginator */
$stringKeyedCursorPaginator = new CursorPaginator(dataTypeStringKeyedRows(), 10);
$directPaginatedData = new PaginatedDataCollection(DataTypeUserData::class, $stringKeyedPaginator);
$directCursorPaginatedData = new CursorPaginatedDataCollection(DataTypeUserData::class, $stringKeyedCursorPaginator);

assertType('Hypervel\Pagination\Paginator<int, DataTypeUserData>', $paginated);
assertType('Hypervel\Data\PaginatedDataCollection<int, DataTypeUserData>', $paginatedData);
assertType('Hypervel\Pagination\CursorPaginator<int, DataTypeUserData>', $cursorPaginated);
assertType('Hypervel\Data\CursorPaginatedDataCollection<int, DataTypeUserData>', $cursorPaginatedData);
assertType('Hypervel\Pagination\LengthAwarePaginator<int, DataTypeUserData>', $lengthAwarePaginated);
assertType('Hypervel\Pagination\AbstractPaginator<int, DataTypeUserData>', $abstractPaginated);
assertType('Hypervel\Pagination\AbstractCursorPaginator<int, DataTypeUserData>', $abstractCursorPaginated);
assertType('Hypervel\Data\CursorPaginatedDataCollection<int, DataTypeUserData>|Hypervel\Data\DataCollection<int, DataTypeUserData>|Hypervel\Data\PaginatedDataCollection<int, DataTypeUserData>|Hypervel\Pagination\AbstractCursorPaginator<int, DataTypeUserData>|Hypervel\Pagination\AbstractPaginator<int, DataTypeUserData>|Hypervel\Support\Collection<int, DataTypeUserData>|Hypervel\Support\LazyCollection<int, DataTypeUserData>', $contractPaginated);
assertType('Hypervel\Data\CursorPaginatedDataCollection<int, DataTypeUserData>|Hypervel\Data\DataCollection<int, DataTypeUserData>|Hypervel\Data\PaginatedDataCollection<int, DataTypeUserData>|Hypervel\Pagination\AbstractCursorPaginator<int, DataTypeUserData>|Hypervel\Pagination\AbstractPaginator<int, DataTypeUserData>|Hypervel\Support\Collection<int, DataTypeUserData>|Hypervel\Support\LazyCollection<int, DataTypeUserData>', $contractCursorPaginated);
assertType('Hypervel\Data\CursorPaginatedDataCollection<int, DataTypeUserData>|Hypervel\Data\DataCollection<int, DataTypeUserData>|Hypervel\Data\PaginatedDataCollection<int, DataTypeUserData>|Hypervel\Pagination\AbstractCursorPaginator<int, DataTypeUserData>|Hypervel\Pagination\AbstractPaginator<int, DataTypeUserData>|Hypervel\Support\Collection<int, DataTypeUserData>|Hypervel\Support\LazyCollection<int, DataTypeUserData>', $contractLengthAwarePaginated);
assertType('Hypervel\Pagination\Paginator<int, DataTypeUserData>', $explicitPaginator);
assertType('Hypervel\Pagination\CursorPaginator<int, DataTypeUserData>', $explicitCursorPaginator);
assertType('Hypervel\Pagination\LengthAwarePaginator<int, DataTypeUserData>', $explicitLengthAwarePaginator);
assertType('Hypervel\Pagination\AbstractPaginator<int, DataTypeUserData>', $explicitAbstractPaginator);
assertType('Hypervel\Pagination\AbstractCursorPaginator<int, DataTypeUserData>', $explicitAbstractCursorPaginator);
assertType('Hypervel\Contracts\Pagination\Paginator<int, DataTypeUserData>', $explicitPaginatorContract);
assertType('Hypervel\Contracts\Pagination\CursorPaginator<int, DataTypeUserData>', $explicitCursorPaginatorContract);
assertType('Hypervel\Contracts\Pagination\LengthAwarePaginator<int, DataTypeUserData>', $explicitLengthAwareContract);
assertType('Hypervel\Data\PaginatedDataCollection<int, DataTypeUserData>', $explicitPaginatedData);
assertType('Hypervel\Data\CursorPaginatedDataCollection<int, DataTypeUserData>', $explicitCursorPaginatedData);
assertType('Hypervel\Data\PaginatedDataCollection<int, DataTypeUserData>', $sourcePaginatedData);
assertType('Hypervel\Data\CursorPaginatedDataCollection<int, DataTypeUserData>', $sourceCursorPaginatedData);
assertType('Hypervel\Data\PaginatedDataCollection<string, DataTypeUserData>', $directPaginatedData);
assertType('Hypervel\Data\CursorPaginatedDataCollection<string, DataTypeUserData>', $directCursorPaginatedData);

function dataTypeAcceptsTransformable(TransformableData $data): void
{
}

function dataTypeAcceptsValidateable(ValidateableData $data): void
{
}

function dataTypeAcceptsResponsable(ResponsableData $data): void
{
}

function dataTypeAcceptsCastable(Castable $data): void
{
}

dataTypeAcceptsTransformable(new DataTypeUserData(1));
dataTypeAcceptsTransformable(new DataTypeUserResource(1));
dataTypeAcceptsValidateable(new DataTypeUserData(1));
dataTypeAcceptsValidateable(new DataTypeUserDto(1));
dataTypeAcceptsResponsable(new DataTypeUserData(1));
dataTypeAcceptsResponsable(new DataTypeUserResource(1));
dataTypeAcceptsCastable(new DataTypeUserData(1));
dataTypeAcceptsCastable(new DataTypeUserResource(1));

/** @extends Request<DataTypeUserData> */
class DataTypeUserRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/user';
    }

    /** @param Response<DataTypeUserData> $response */
    public function createDtoFromResponse(Response $response): DataTypeUserData
    {
        return DataTypeUserData::from($response->json());
    }
}

/** @extends Connector<DataTypeUserData> */
class DataTypeConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://example.com';
    }
}

$response = (new DataTypeConnector)->send(new DataTypeUserRequest);

assertType('Hypervel\Saloon\Http\Response<DataTypeUserData>', $response);
assertType(DataTypeUserData::class, $response->dto());

/** @var ArrayIterator<string, array{id: int}> $unsupportedIterator */
$unsupportedIterator = new ArrayIterator(dataTypeStringKeyedRows());

assertType('never', DataTypeUserData::collect($unsupportedIterator));
