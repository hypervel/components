<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Http;

use Psr\Http\Message\UriInterface;

interface UriPlusInterface extends UriInterface
{
    public function getScheme(): string;

    public function setScheme(string $scheme): static;

    public function withScheme(string $scheme): static;

    public function getAuthority(): string;

    public function getUserInfo(): string;

    public function setUserInfo(string $user, string $password = ''): static;

    public function withUserInfo(string $user, ?string $password = null): static;

    public function getHost(): string;

    public function setHost(string $host): static;

    public function withHost(string $host): static;

    public function getPort(): ?int;

    public function setPort(?int $port): static;

    public function withPort(?int $port): static;

    public function getPath(): string;

    public function setPath(string $path): static;

    public function withPath(string $path): static;

    public function getQuery(): string;

    public function setQuery(string $query): static;

    public function withQuery(string $query): static;

    /** @return array<string, string> */
    public function getQueryParams(): array;

    /** @param array<string, string> $queryParams */
    public function setQueryParams(array $queryParams): static;

    /** @param array<string, string> $queryParams */
    public function withQueryParams(array $queryParams): static;

    public function getFragment(): string;

    public function setFragment(string $fragment): static;

    public function withFragment(string $fragment): static;

    public function toString(): string;
}
