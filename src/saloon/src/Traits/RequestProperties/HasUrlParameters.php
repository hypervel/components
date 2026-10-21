<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\RequestProperties;

trait HasUrlParameters
{
    /**
     * The parameters substituted into URL templates.
     *
     * @var array<array-key, mixed>
     */
    protected array $urlParameters = [];

    /**
     * Get the parameters substituted into URL templates.
     *
     * @return array<array-key, mixed>
     */
    public function urlParameters(): array
    {
        return $this->urlParameters;
    }

    /**
     * Specify the URL parameters that can be substituted into the request URL.
     *
     * @param array<array-key, mixed> $parameters
     * @return $this
     */
    public function withUrlParameters(array $parameters = []): static
    {
        // Replace by key so numeric template names such as {1} are not renumbered.
        $this->urlParameters = array_replace($this->urlParameters, $parameters);

        return $this;
    }
}
