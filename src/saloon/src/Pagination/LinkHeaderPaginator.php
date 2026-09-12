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
abstract class LinkHeaderPaginator extends PagedPaginator
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
        $links = $this->parseLinks($response->toPsrResponse()->getHeader('Link'), $uri);
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
     * Parse pagination relations without splitting quoted values or URI commas.
     *
     * @param list<string> $headers
     * @return array<string, UriInterface>
     */
    protected function parseLinks(array $headers, UriInterface $currentUri): array
    {
        $links = [];

        foreach ($headers as $header) {
            $position = 0;
            $length = strlen($header);

            while ($position < $length) {
                $position += strspn($header, " \t,", $position);

                if ($position === $length) {
                    break;
                }

                if ($header[$position] !== '<' || ($end = strpos($header, '>', $position + 1)) === false) {
                    throw new PaginationException('A Link target must be enclosed in angle brackets.');
                }

                $target = substr($header, $position + 1, $end - $position - 1);
                $position = $end + 1;
                $relations = null;
                $anchored = false;

                while ($position < $length) {
                    $position += strspn($header, " \t", $position);

                    if (($header[$position] ?? null) !== ';') {
                        break;
                    }

                    ++$position;
                    $position += strspn($header, " \t", $position);
                    $size = strcspn($header, " \t=;,", $position);
                    $name = strtolower(substr($header, $position, $size));
                    $position += $size;
                    $position += strspn($header, " \t", $position);
                    $value = '';

                    if (($header[$position] ?? null) === '=') {
                        ++$position;
                        $position += strspn($header, " \t", $position);

                        if (($header[$position] ?? null) === '"') {
                            ++$position;

                            while ($position < $length && $header[$position] !== '"') {
                                if ($header[$position] === '\\') {
                                    ++$position;
                                }

                                if ($position < $length) {
                                    $value .= $header[$position++];
                                }
                            }

                            if ($position === $length) {
                                throw new PaginationException('A quoted Link parameter is unterminated.');
                            }

                            ++$position;
                        } else {
                            $size = strcspn($header, " \t;,", $position);
                            $value = substr($header, $position, $size);
                            $position += $size;
                        }
                    }

                    if ($name === 'rel' && $relations === null) {
                        $relations = $value;
                    } elseif ($name === 'anchor') {
                        $anchored = true;
                    }
                }

                if ($position < $length && $header[$position] !== ',') {
                    throw new PaginationException('Link values must be separated by commas.');
                }

                // RFC 8288 section 3.2 permits ignoring an anchored link, not its context alone.
                if ($anchored || $relations === null || trim($relations) === '') {
                    continue;
                }

                foreach (preg_split('/[ \t]+/', strtolower(trim($relations))) as $relation) {
                    if ($relation !== 'next' && $relation !== 'last') {
                        continue;
                    }

                    $uri = UriResolver::resolve($currentUri, new Uri($target));

                    if ($uri->getScheme() !== $currentUri->getScheme()
                        || $uri->getHost() !== $currentUri->getHost()
                        || $uri->getPort() !== $currentUri->getPort()
                        || $uri->getPath() !== $currentUri->getPath()) {
                        throw new PaginationException('Pagination Links must target the same scheme, host, port, and path as the request.');
                    }

                    if (isset($links[$relation]) && (string) $links[$relation] !== (string) $uri) {
                        throw new PaginationException("Conflicting [{$relation}] pagination Links were returned.");
                    }

                    $links[$relation] = $uri;
                }
            }
        }

        return $links;
    }
}
