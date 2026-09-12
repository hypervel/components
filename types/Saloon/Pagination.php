<?php

declare(strict_types=1);

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Pagination\Contracts\HasPagination;
use Hypervel\Saloon\Pagination\Contracts\HasRequestPagination;
use Hypervel\Saloon\Pagination\Contracts\MapPaginatedResponseItems;
use Hypervel\Saloon\Pagination\Contracts\Paginatable;
use Hypervel\Saloon\Pagination\CursorPaginator;
use Hypervel\Saloon\Pagination\LinkHeaderPaginator;
use Hypervel\Saloon\Pagination\OffsetPaginator;
use Hypervel\Saloon\Pagination\PagedPaginator;
use Hypervel\Saloon\Pagination\Paginator;

use function PHPStan\Testing\assertType;

class SaloonPaginationTypeItem
{
}

/**
 * @extends Request<mixed>
 * @implements MapPaginatedResponseItems<SaloonPaginationTypeItem>
 * @implements HasRequestPagination<SaloonPaginationTypeItem>
 */
class SaloonPaginationTypeRequest extends Request implements Paginatable, MapPaginatedResponseItems, HasRequestPagination
{
    protected Method $method = Method::GET;

    /**
     * Resolve the item-list endpoint.
     */
    public function resolveEndpoint(): string
    {
        return '/items';
    }

    /**
     * Map a page to its declared item type.
     *
     * @param Response<mixed> $response
     * @return list<SaloonPaginationTypeItem>
     */
    public function mapPaginatedResponseItems(Response $response): array
    {
        return [new SaloonPaginationTypeItem];
    }

    /**
     * Create the request's typed paginator.
     *
     * @param Connector<mixed> $connector
     * @return Paginator<SaloonPaginationTypeItem>
     */
    public function paginate(Connector $connector): Paginator
    {
        return new SaloonPaginationTypePaged($connector, $this);
    }
}

/**
 * @extends Connector<mixed>
 * @implements HasPagination<SaloonPaginationTypeItem>
 */
class SaloonPaginationTypeConnector extends Connector implements HasPagination
{
    /**
     * Resolve the API URL.
     */
    public function resolveBaseUrl(): string
    {
        return 'https://example.com';
    }

    /**
     * Create the connector's typed paginator.
     *
     * @param Request<mixed> $request
     * @return Paginator<SaloonPaginationTypeItem>
     */
    public function paginate(Request $request): Paginator
    {
        return new SaloonPaginationTypePaged($this, $request);
    }
}

/** @extends PagedPaginator<SaloonPaginationTypeItem> */
class SaloonPaginationTypePaged extends PagedPaginator
{
    /**
     * Stop after the fixture page.
     *
     * @param Response<mixed> $response
     */
    protected function isLastPage(Response $response): bool
    {
        return true;
    }

    /**
     * Return the declared item type.
     *
     * @param Response<mixed> $response
     * @param Request<mixed> $request
     * @return list<SaloonPaginationTypeItem>
     */
    protected function getPageItems(Response $response, Request $request): array
    {
        return [new SaloonPaginationTypeItem];
    }
}

/** @extends OffsetPaginator<SaloonPaginationTypeItem> */
abstract class SaloonPaginationTypeOffset extends OffsetPaginator
{
}

/** @extends CursorPaginator<SaloonPaginationTypeItem> */
abstract class SaloonPaginationTypeCursor extends CursorPaginator
{
}

/** @extends LinkHeaderPaginator<SaloonPaginationTypeItem> */
abstract class SaloonPaginationTypeLink extends LinkHeaderPaginator
{
}

/**
 * Verify pagination item types across subclasses and public contracts.
 *
 * @param HasPagination<SaloonPaginationTypeItem> $connector
 * @param HasRequestPagination<SaloonPaginationTypeItem> $request
 */
function SaloonPaginationTypeAssertions(
    SaloonPaginationTypePaged $paged,
    SaloonPaginationTypeOffset $offset,
    SaloonPaginationTypeCursor $cursor,
    SaloonPaginationTypeLink $link,
    HasPagination $connector,
    HasRequestPagination $request,
    bool $throughItems,
): void {
    assertType('iterable<int, SaloonPaginationTypeItem>', $paged->items());
    assertType('Hypervel\Support\LazyCollection<int, SaloonPaginationTypeItem>', $paged->collect());
    assertType('Hypervel\Support\LazyCollection<int, Hypervel\Saloon\Http\Response<mixed>>', $paged->collect(false));
    assertType('Hypervel\Support\LazyCollection<int, Hypervel\Saloon\Http\Response<mixed>>|Hypervel\Support\LazyCollection<int, SaloonPaginationTypeItem>', $paged->collect($throughItems));
    assertType('Hypervel\Saloon\Http\Response<mixed>', $paged->current());
    assertType('array<int, Hypervel\Saloon\Http\Response<mixed>>', $paged->pool());
    assertType('iterable<int, SaloonPaginationTypeItem>', $offset->items());
    assertType('iterable<int, SaloonPaginationTypeItem>', $cursor->items());
    assertType('iterable<int, SaloonPaginationTypeItem>', $link->items());
    assertType('Hypervel\Saloon\Http\Response<mixed>', $link->current());
    assertType('Hypervel\Saloon\Pagination\Paginator<SaloonPaginationTypeItem>', $connector->paginate(new SaloonPaginationTypeRequest));
    assertType('Hypervel\Saloon\Pagination\Paginator<SaloonPaginationTypeItem>', $request->paginate(new SaloonPaginationTypeConnector));

    foreach ($paged as $key => $response) {
        assertType('int', $key);
        assertType('Hypervel\Saloon\Http\Response<mixed>', $response);
    }

    foreach ($paged->items() as $key => $item) {
        assertType('int', $key);
        assertType(SaloonPaginationTypeItem::class, $item);
    }
}
