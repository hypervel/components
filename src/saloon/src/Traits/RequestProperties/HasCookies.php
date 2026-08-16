<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\RequestProperties;

trait HasCookies
{
    /**
     * The request cookie groups.
     *
     * @var list<array{cookies: array<string, string>, domain: string}>
     */
    protected array $cookieGroups = [];

    /**
     * Specify cookies that should be included with the request.
     *
     * @param array<string, string> $cookies
     * @return $this
     */
    public function withCookies(array $cookies, string $domain): static
    {
        $this->cookieGroups[] = ['cookies' => $cookies, 'domain' => $domain];

        return $this;
    }

    /**
     * Get the request cookie groups.
     *
     * @return list<array{cookies: array<string, string>, domain: string}>
     */
    public function cookies(): array
    {
        return $this->cookieGroups;
    }
}
