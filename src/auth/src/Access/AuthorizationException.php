<?php

declare(strict_types=1);

namespace Hypervel\Auth\Access;

use Exception;
use Throwable;

class AuthorizationException extends Exception
{
    /**
     * The response from the gate.
     */
    protected ?Response $response = null;

    /**
     * The HTTP response status code.
     */
    protected ?int $status = null;

    /**
     * Create a new authorization exception instance.
     */
    public function __construct(?string $message = null, null|int|string $code = null, ?Throwable $previous = null)
    {
        parent::__construct($message ?? 'This action is unauthorized.', 0, $previous);

        $this->code = $code ?: 0;
    }

    /**
     * Get the response from the gate.
     */
    public function response(): ?Response
    {
        return $this->response;
    }

    /**
     * Set the response from the gate.
     */
    public function setResponse(?Response $response): static
    {
        $this->response = $response;

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
     * Determine if the HTTP status code has been set.
     */
    public function hasStatus(): bool
    {
        return $this->status !== null;
    }

    /**
     * Get the HTTP status code.
     */
    public function status(): ?int
    {
        return $this->status;
    }

    /**
     * Create a deny response object from this exception.
     */
    public function toResponse(): Response
    {
        return Response::deny($this->message, $this->code)->withStatus($this->status);
    }
}
