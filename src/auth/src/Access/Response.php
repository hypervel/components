<?php

declare(strict_types=1);

namespace Hypervel\Auth\Access;

use Hyperf\Contract\Arrayable;
use Stringable;

class Response implements Arrayable, Stringable
{
    /**
     * The HTTP response status code.
     */
    protected ?int $status = null;

    /**
     * Create a new response.
     *
     * @param bool $allowed Indicates whether the response was allowed
     * @param null|string $message The response message
     * @param null|int|string $code The response code
     */
    public function __construct(
        protected bool $allowed,
        protected ?string $message = '',
        protected int|string|null $code = null
    ) {
        $this->code = $code;
        $this->allowed = $allowed;
        $this->message = $message;
    }

    /**
     * Create a new "allow" Response.
     */
    public static function allow(?string $message = null, int|string|null $code = null): static
    {
        return new static(true, $message, $code);
    }

    /**
     * Create a new "deny" Response.
     */
    public static function deny(?string $message = null, int|string|null $code = null): static
    {
        return new static(false, $message, $code);
    }

    /**
     * Create a new "deny" Response with a HTTP status code.
     */
    public static function denyWithStatus(int $status, ?string $message = null, int|string|null $code = null): static
    {
        return static::deny($message, $code)->withStatus($status);
    }

    /**
     * Create a new "deny" Response with a 404 HTTP status code.
     */
    public static function denyAsNotFound(?string $message = null, int|string|null $code = null): static
    {
        return static::denyWithStatus(404, $message, $code);
    }

    /**
     * Determine if the response was allowed.
     */
    public function allowed(): bool
    {
        return $this->allowed;
    }

    /**
     * Determine if the response was denied.
     */
    public function denied(): bool
    {
        return ! $this->allowed();
    }

    /**
     * Get the response message.
     */
    public function message(): ?string
    {
        return $this->message;
    }

    /**
     * Get the response code / reason.
     */
    public function code(): int|string|null
    {
        return $this->code;
    }

    /**
     * Throw authorization exception if response was denied.
     *
     * @throws AuthorizationException
     */
    public function authorize(): static
    {
        if ($this->denied()) {
            throw (new AuthorizationException($this->message(), $this->code()))
                ->setResponse($this)
                ->withStatus($this->status);
        }

        return $this;
    }

    /**
     * Set the HTTP response status code.
     */
    public function withStatus(?int $status): static
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Set the HTTP response status code to 404.
     */
    public function asNotFound(): static
    {
        return $this->withStatus(404);
    }

    /**
     * Get the HTTP status code.
     */
    public function status(): ?int
    {
        return $this->status;
    }

    /**
     * Convert the response to an array.
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed(),
            'message' => $this->message(),
            'code' => $this->code(),
        ];
    }

    /**
     * Get the string representation of the message.
     */
    public function __toString(): string
    {
        return (string) $this->message();
    }
}
