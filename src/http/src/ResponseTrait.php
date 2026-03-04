<?php

declare(strict_types=1);

namespace Hypervel\Http;

use Hypervel\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\HeaderBag;
use Throwable;

trait ResponseTrait
{
    /**
     * The original content of the response.
     */
    public mixed $original = null;

    /**
     * The exception that triggered the error response (if applicable).
     */
    public ?Throwable $exception = null;

    /**
     * Get the status code for the response.
     */
    public function status(): int
    {
        return $this->getStatusCode();
    }

    /**
     * Get the status text for the response.
     */
    public function statusText(): string
    {
        return $this->statusText;
    }

    /**
     * Get the content of the response.
     */
    public function content(): string
    {
        return $this->getContent();
    }

    /**
     * Get the original response content.
     */
    public function getOriginalContent(): mixed
    {
        $original = $this->original;

        return $original instanceof self ? $original->{__FUNCTION__}() : $original;
    }

    /**
     * Set a header on the Response.
     *
     * @return $this
     */
    public function header(string $key, array|string $values, bool $replace = true): static
    {
        $this->headers->set($key, $values, $replace);

        return $this;
    }

    /**
     * Add an array of headers to the response.
     *
     * @return $this
     */
    public function withHeaders(HeaderBag|array $headers): static
    {
        if ($headers instanceof HeaderBag) {
            $headers = $headers->all();
        }

        foreach ($headers as $key => $value) {
            $this->headers->set($key, $value);
        }

        return $this;
    }

    /**
     * Remove a header(s) from the response.
     *
     * @return $this
     */
    public function withoutHeader(array|string $key): static
    {
        foreach ((array) $key as $header) {
            $this->headers->remove($header);
        }

        return $this;
    }

    /**
     * Add a cookie to the response.
     *
     * @return $this
     */
    public function cookie(mixed $cookie): static
    {
        return $this->withCookie(...func_get_args());
    }

    /**
     * Add a cookie to the response.
     *
     * @return $this
     */
    public function withCookie(mixed $cookie): static
    {
        if (is_string($cookie) && function_exists('cookie')) {
            $cookie = cookie(...func_get_args());
        }

        $this->headers->setCookie($cookie);

        return $this;
    }

    /**
     * Expire a cookie when sending the response.
     *
     * @return $this
     */
    public function withoutCookie(mixed $cookie, ?string $path = null, ?string $domain = null): static
    {
        if (is_string($cookie) && function_exists('cookie')) {
            $cookie = cookie($cookie, null, -2628000, $path, $domain);
        }

        $this->headers->setCookie($cookie);

        return $this;
    }

    /**
     * Get the callback of the response.
     */
    public function getCallback(): ?string
    {
        return $this->callback ?? null;
    }

    /**
     * Set the exception to attach to the response.
     *
     * @return $this
     */
    public function withException(Throwable $e): static
    {
        $this->exception = $e;

        return $this;
    }

    /**
     * Throw the response in a HttpResponseException instance.
     *
     * @throws HttpResponseException
     */
    public function throwResponse(): never
    {
        throw new HttpResponseException($this);
    }
}
