<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Contracts;

use Closure;
use Hypervel\Saloon\Contracts\Body\BodyRepository;
use Hypervel\Saloon\Http\Faking\Fixture;
use Hypervel\Saloon\Http\PendingRequest;
use Psr\Http\Message\ResponseInterface;
use Throwable;

interface FakeResponse
{
    /**
     * Get the response status.
     */
    public function status(): int;

    /**
     * Get the response headers.
     *
     * @return array<string, mixed>
     */
    public function headers(): array;

    /**
     * Get the response body repository.
     */
    public function body(): BodyRepository;

    /**
     * Configure an exception for the request.
     *
     * @return $this
     */
    public function throw(Closure|Throwable $value): static;

    /**
     * Resolve the configured exception.
     */
    public function getException(PendingRequest $pendingRequest): ?Throwable;

    /**
     * Create a fake response from a fixture.
     */
    public static function fixture(string $name): Fixture;

    /**
     * Create the PSR response.
     */
    public function createPsrResponse(): ResponseInterface;
}
