<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Pagination;

use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Pagination\Exceptions\PaginationException;
use Psr\Http\Message\UriInterface;

/**
 * @template TItem
 * @extends PagedPaginator<TItem>
 */
abstract class LinkPaginator extends PagedPaginator
{
    /**
     * The next page's complete query string.
     */
    protected ?string $nextQuery = null;

    /**
     * The last independently addressable page for pooled requests.
     */
    protected ?int $lastPage = null;

    /**
     * Get the current response and resolve its pagination links.
     *
     * @return Response<mixed>
     */
    public function current(): Response
    {
        $response = parent::current();
        $uri = $response->toPsrRequest()->getUri();
        $links = $this->getLinks($response, $uri);
        $next = $links['next'] ?? null;
        $lastPage = $this->pooling && isset($links['last']) ? $this->pageFromUri($links['last']) : null;

        if ($lastPage !== null) {
            $currentPage = $this->pageFromUri($uri) ?? $this->pageNumber;
            if (($next !== null && $lastPage <= $currentPage)
                || ($next === null && $lastPage > $currentPage)) {
                throw new PaginationException('The last Link page contradicts the current page or next relation.');
            }
        }

        $this->nextQuery = $next?->getQuery();
        $this->lastPage = $lastPage;

        return $response;
    }

    /**
     * Apply numbered pagination or the provider's complete continuation query.
     *
     * @param Request<mixed> $request
     * @return Request<mixed>
     */
    protected function applyPagination(Request $request): Request
    {
        if ($this->currentResponse === null || $this->pooling) {
            return parent::applyPagination($request);
        }

        return $request->withQueryString(
            $this->nextQuery ?? throw new PaginationException('The response has no next Link.'),
        );
    }

    /**
     * Determine whether the response has no continuation link.
     *
     * @param Response<mixed> $response
     */
    protected function isLastPage(Response $response): bool
    {
        return $this->nextQuery === null;
    }

    /**
     * Resolve the last independently addressable page.
     *
     * @param Response<mixed> $response
     */
    protected function getTotalPages(Response $response): int
    {
        return $this->nextQuery === null
            ? $this->startPage
            : ($this->lastPage ?? throw new PaginationException('Pooled Link pagination requires a numbered last Link.'));
    }

    /**
     * Clear continuation state when iteration restarts.
     */
    protected function onRewind(): void
    {
        $this->nextQuery = null;
        $this->lastPage = null;
    }

    /**
     * Read an optional page number without flattening repeated names.
     */
    protected function pageFromUri(UriInterface $uri): ?int
    {
        $query = Query::parse($uri->getQuery());

        if (! array_key_exists($this->pageName, $query)) {
            return null;
        }

        $value = $query[$this->pageName];
        $page = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

        if ($page === false) {
            throw new PaginationException("The Link [{$this->pageName}] parameter must be an integer.");
        }

        return $page;
    }

    /**
     * Resolve a continuation target against the current request.
     */
    protected function resolveLink(string $target, UriInterface $currentUri): UriInterface
    {
        $uri = UriResolver::resolve($currentUri, new Uri($target));

        if ($uri->getScheme() !== $currentUri->getScheme()
            || $uri->getHost() !== $currentUri->getHost()
            || $uri->getPort() !== $currentUri->getPort()
            || $uri->getPath() !== $currentUri->getPath()) {
            throw new PaginationException('Pagination Links must target the same scheme, host, port, and path as the request.');
        }

        return $uri;
    }

    /**
     * Get pagination links resolved through resolveLink against the current URI.
     *
     * @param Response<mixed> $response
     * @return array{next?: UriInterface, last?: UriInterface}
     */
    abstract protected function getLinks(Response $response, UriInterface $currentUri): array;
}
