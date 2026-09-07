<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\RequestProperties;

use Hypervel\Saloon\Repositories\ArrayRepository;
use Psr\Http\Message\StreamInterface;
use UnitEnum;

trait HasOptions
{
    /**
     * The request options.
     */
    protected ?ArrayRepository $optionRepository = null;

    /**
     * Get the request options.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->optionRepository()->all();
    }

    /**
     * Add options to the request.
     *
     * @param array<string, mixed> $options
     * @return $this
     */
    public function withOptions(array $options): static
    {
        $this->optionRepository()->set(array_replace_recursive($this->options(), $options));

        return $this;
    }

    /**
     * Specify the maximum number of redirects to allow.
     *
     * @return $this
     */
    public function maxRedirects(int $max): static
    {
        $options = $this->options();
        $options['allow_redirects'] = [
            ...(is_array($options['allow_redirects'] ?? null) ? $options['allow_redirects'] : []),
            'max' => $max,
        ];

        $this->optionRepository()->set($options);

        return $this;
    }

    /**
     * Indicate that redirects should not be followed.
     *
     * @return $this
     */
    public function withoutRedirecting(): static
    {
        return $this->withOptions(['allow_redirects' => false]);
    }

    /**
     * Indicate that TLS certificates should not be verified.
     *
     * @return $this
     */
    public function withoutVerifying(): static
    {
        return $this->withOptions(['verify' => false]);
    }

    /**
     * Specify where the response body should be stored.
     *
     * @param resource|StreamInterface|string $to
     * @return $this
     */
    public function sink($to): static
    {
        return $this->withOptions(['sink' => $to]);
    }

    /**
     * Specify the request timeout in seconds.
     *
     * @return $this
     */
    public function timeout(float|int $seconds): static
    {
        return $this->withOptions(['timeout' => $seconds]);
    }

    /**
     * Specify the connection timeout in seconds.
     *
     * @return $this
     */
    public function connectTimeout(float|int $seconds): static
    {
        return $this->withOptions(['connect_timeout' => $seconds]);
    }

    /**
     * Add Telescope tags to the request.
     *
     * @param array<int, string|UnitEnum> $tags
     * @return $this
     */
    public function withTelescopeTags(array $tags): static
    {
        return $this->withOptions(['telescope_tags' => $tags]);
    }

    /**
     * Disable Telescope recording for the request.
     *
     * @return $this
     */
    public function withoutTelescope(): static
    {
        return $this->withOptions(['telescope_enabled' => false]);
    }

    /**
     * Resolve the default request options.
     *
     * @return array<string, mixed>
     */
    protected function defaultOptions(): array
    {
        return [];
    }

    /**
     * Get the request option repository.
     */
    protected function optionRepository(): ArrayRepository
    {
        return $this->optionRepository ??= new ArrayRepository($this->defaultOptions());
    }
}
