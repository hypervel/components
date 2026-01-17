<?php

declare(strict_types=1);

namespace Hypervel\ApiClient;

trait HasContext
{
    protected array $context = [];

    /**
     * Set the API request/response context.
     */
    public function withContext(string|array $key, mixed $value = null): static
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
    public function context(?string $key = null): mixed
    {
        if ($key !== null) {
            return $this->context[$key] ?? null;
        }

        return $this->context;
    }
}
