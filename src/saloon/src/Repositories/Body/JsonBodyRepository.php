<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Repositories\Body;

use Hypervel\Saloon\Exceptions\BodyException;
use Hypervel\Saloon\Http\StructuredDataNormalizer;
use Hypervel\Saloon\Traits\Body\CreatesStreamFromString;
use JsonException;
use Stringable;

class JsonBodyRepository extends ArrayBodyRepository implements Stringable
{
    use CreatesStreamFromString;

    /**
     * The JSON encoding flags.
     *
     * Use a bitmask to combine flags, such as JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR.
     */
    protected int $jsonFlags = JSON_THROW_ON_ERROR;

    /**
     * Set the JSON encoding flags.
     *
     * The value must be a bitmask, such as JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR.
     *
     * @return $this
     */
    public function setJsonFlags(int $flags): static
    {
        $this->jsonFlags = $flags;

        return $this;
    }

    /**
     * Get the JSON encoding flags.
     */
    public function getJsonFlags(): int
    {
        return $this->jsonFlags;
    }

    /**
     * Convert the body repository into a string.
     */
    public function __toString(): string
    {
        try {
            $json = json_encode(
                StructuredDataNormalizer::forJson($this->all()),
                $this->getJsonFlags(),
            );
        } catch (JsonException $exception) {
            throw new BodyException('The request body could not be encoded as JSON.', previous: $exception);
        }

        if ($json === false) {
            throw new BodyException('The request body could not be encoded as JSON.');
        }

        return $json;
    }
}
