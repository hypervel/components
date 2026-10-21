<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Support;

use Hypervel\Mcp\Exceptions\MirroredParameterException;
use Hypervel\Mcp\Transport\HeaderValue;

class MirroredParameter
{
    /**
     * Create a parameter's header mapping.
     */
    public function __construct(
        public readonly SchemaPath $path,
        public readonly string $name,
        public readonly MirroredParameterType $type,
    ) {
    }

    /**
     * Get the HTTP header name.
     */
    public function header(): string
    {
        return MirroredParameters::PREFIX . $this->name;
    }

    /**
     * Get the unencoded argument value.
     *
     * @param array<string, mixed> $arguments
     */
    public function raw(array $arguments): mixed
    {
        return $this->path->valueIn($arguments);
    }

    /**
     * Convert the value according to its declared type.
     */
    public function stringify(mixed $value): ?string
    {
        return $this->type->stringify($value);
    }

    /**
     * Get the validated header value for the supplied arguments.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws MirroredParameterException
     */
    public function value(array $arguments): ?HeaderValue
    {
        $value = $this->raw($arguments);

        if ($value === null) {
            return null;
        }

        $stringified = $this->stringify($value);

        if ($stringified === null) {
            throw new MirroredParameterException(
                "The [{$this->path}] argument cannot be mirrored into the [{$this->header()}] header as a [{$this->type->value}].",
            );
        }

        return new HeaderValue($stringified);
    }
}
