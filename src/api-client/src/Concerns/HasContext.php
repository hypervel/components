<?php

declare(strict_types=1);

namespace Hypervel\ApiClient\Concerns;

trait HasContext
{
    /**
     * @var array<string, mixed>
     */
    protected array $context = [];

    /**
     * Set the API request/response context.
     *
     * @param array<string, mixed>|string $key
     */
    public function withContext(array|string $key, mixed $value = null): static
    {
        if (is_array($key)) {
            $this->context = array_merge($this->context, $key);

            return $this;
        }

        $this->context[$key] = $value;

        return $this;
    }

    /**
     * Get the API request/response context.
     */
    public function context(?string $key = null, mixed $default = null): mixed
    {
        if ($key !== null) {
            return $this->context[$key] ?? $default;
        }

        return $this->context;
    }
}
