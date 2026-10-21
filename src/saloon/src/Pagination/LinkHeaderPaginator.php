<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Pagination;

use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Pagination\Exceptions\PaginationException;
use Psr\Http\Message\UriInterface;

/**
 * @template TItem
 * @extends LinkPaginator<TItem>
 */
abstract class LinkHeaderPaginator extends LinkPaginator
{
    /**
     * The relation identifying the next page.
     */
    protected string $nextRelation = 'next';

    /**
     * The relation identifying the last page.
     */
    protected string $lastRelation = 'last';

    /**
     * Get the next and last page links from the response headers.
     *
     * @param Response<mixed> $response
     * @return array{next?: UriInterface, last?: UriInterface}
     */
    protected function getLinks(Response $response, UriInterface $currentUri): array
    {
        return $this->parseLinks($response->toPsrResponse()->getHeader('Link'), $currentUri);
    }

    /**
     * Parse pagination relations without splitting quoted values or URI commas.
     *
     * @param list<string> $headers
     * @return array{next?: UriInterface, last?: UriInterface}
     */
    protected function parseLinks(array $headers, UriInterface $currentUri): array
    {
        $links = [];
        $nextRelation = strtolower($this->nextRelation);
        $lastRelation = strtolower($this->lastRelation);

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
                    $key = match ($relation) {
                        $nextRelation => 'next',
                        $lastRelation => 'last',
                        default => null,
                    };

                    if ($key === null) {
                        continue;
                    }

                    $uri = $this->resolveLink($target, $currentUri);

                    if (isset($links[$key]) && (string) $links[$key] !== (string) $uri) {
                        throw new PaginationException("Conflicting [{$relation}] pagination Links were returned.");
                    }

                    $links[$key] = $uri;
                }
            }
        }

        return $links;
    }
}
