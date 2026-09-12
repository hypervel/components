<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\RequestProperties;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use InvalidArgumentException;

trait HasCookies
{
    /**
     * The request cookies and their attributes.
     *
     * @var list<array<string, mixed>>
     */
    protected array $cookies = [];

    /**
     * Specify a cookie and its attributes for the request.
     *
     * @return $this
     */
    public function withCookie(SetCookie $cookie): static
    {
        if ($cookie->getDomain() === null) {
            throw new InvalidArgumentException('An outgoing cookie must have a domain.');
        }

        if (($error = $cookie->validate()) !== true) {
            throw new InvalidArgumentException('Invalid cookie: ' . $error);
        }

        $this->cookies[] = $cookie->toArray();

        return $this;
    }

    /**
     * Specify cookies that should be included with the request.
     *
     * @param array<string, string> $cookies
     * @return $this
     */
    public function withCookies(array $cookies, string $domain): static
    {
        foreach (CookieJar::fromArray($cookies, $domain) as $cookie) {
            $this->withCookie($cookie);
        }

        return $this;
    }

    /**
     * Get the request cookies and their attributes.
     *
     * @return list<array<string, mixed>>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }
}
