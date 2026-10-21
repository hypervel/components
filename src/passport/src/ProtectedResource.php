<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Hypervel\Contracts\Container\Transient;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Support\Uri;
use InvalidArgumentException;

/**
 * @implements Arrayable<array<string, string>>
 */
class ProtectedResource implements Arrayable, Jsonable, Transient
{
    /**
     * Create a new resource instance.
     */
    public function __construct(
        public string $id,
        public string $description,
    ) {
    }

    /**
     * Validate the identifiers in a resource catalog.
     *
     * @param array<string, string> $resources
     */
    public static function validateIdentifiers(array $resources): void
    {
        foreach ($resources as $identifier => $description) {
            $uri = Uri::of($identifier)->getUri();

            if ($uri->getScheme() === null || $uri->getFragment() !== null) {
                throw new InvalidArgumentException('Passport resources must be absolute URIs without fragments.');
            }
        }
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
        ];
    }

    /**
     * Convert the object to its JSON representation.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }
}
