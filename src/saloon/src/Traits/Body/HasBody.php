<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\Body;

use Hypervel\Saloon\Contracts\Body\BodyRepository;
use Hypervel\Saloon\Data\MultipartValue;
use Hypervel\Saloon\Repositories\Body\ArrayBodyRepository;
use Hypervel\Saloon\Repositories\Body\FormBodyRepository;
use Hypervel\Saloon\Repositories\Body\JsonBodyRepository;
use Hypervel\Saloon\Repositories\Body\MultipartBodyRepository;
use Hypervel\Saloon\Repositories\Body\StreamBodyRepository;
use Hypervel\Saloon\Repositories\Body\StringBodyRepository;
use Psr\Http\Message\StreamInterface;
use Stringable;

trait HasBody
{
    /**
     * The request body repository.
     */
    protected ?BodyRepository $bodyRepository = null;

    /**
     * Set the raw request body.
     *
     * @param null|resource|StreamInterface|string|Stringable $content
     * @return $this
     */
    public function withBody(mixed $content, ?string $contentType = 'application/json'): static
    {
        $this->bodyRepository = $content instanceof StreamInterface || is_resource($content)
            ? new StreamBodyRepository($content)
            : new StringBodyRepository($content === null ? null : (string) $content);

        if ($contentType !== null) {
            $this->contentType($contentType);
        }

        return $this;
    }

    /**
     * Use a JSON request body.
     *
     * @param array<array-key, mixed> $data
     * @return $this
     */
    public function asJson(array $data = []): static
    {
        $this->bodyRepository = new JsonBodyRepository($data);

        return $this->contentType('application/json');
    }

    /**
     * Use a URL-encoded form request body.
     *
     * @param array<array-key, mixed> $data
     * @return $this
     */
    public function asForm(array $data = []): static
    {
        $this->bodyRepository = new FormBodyRepository($data);

        return $this->contentType('application/x-www-form-urlencoded');
    }

    /**
     * Use a multipart request body.
     *
     * @return $this
     */
    public function asMultipart(): static
    {
        $this->bodyRepository = new MultipartBodyRepository;

        return $this;
    }

    /**
     * Attach a multipart value to the request.
     *
     * @param float|int|resource|StreamInterface|string $contents
     * @param array<string, string> $headers
     * @return $this
     */
    public function attach(
        string $name,
        mixed $contents = '',
        ?string $filename = null,
        array $headers = [],
    ): static {
        $repository = $this->bodyRepository;

        if (! $repository instanceof MultipartBodyRepository) {
            $repository = $this->bodyRepository = new MultipartBodyRepository;
        }

        $repository->attach(new MultipartValue($name, $contents, $filename, $headers));

        return $this;
    }

    /**
     * Merge structured values into the request body.
     *
     * @param array<array-key, mixed> $data
     * @return $this
     */
    public function withData(array $data): static
    {
        $repository = $this->bodyRepository();

        if (! $repository instanceof ArrayBodyRepository) {
            $repository = $this->bodyRepository = new JsonBodyRepository;
            $this->contentType('application/json');
        }

        $repository->merge($data);

        return $this;
    }

    /**
     * Get the resolved request body value.
     */
    public function body(): mixed
    {
        return $this->bodyRepository()?->all();
    }

    /**
     * Determine if the request has a body repository.
     */
    public function hasBody(): bool
    {
        return $this->bodyRepository() !== null;
    }

    /**
     * Copy the body repository for a pending request.
     *
     * @internal
     */
    final public function copyBodyRepository(): ?BodyRepository
    {
        $repository = $this->bodyRepository();

        return $repository === null ? null : clone $repository;
    }

    /**
     * Resolve the default body repository.
     */
    protected function defaultBodyRepository(): ?BodyRepository
    {
        return null;
    }

    /**
     * Get the request body repository.
     */
    protected function bodyRepository(): ?BodyRepository
    {
        return $this->bodyRepository ??= $this->defaultBodyRepository();
    }
}
