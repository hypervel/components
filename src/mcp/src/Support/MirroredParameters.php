<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Support;

use Countable;
use Hypervel\Mcp\Exceptions\MirroredParameterException;
use Hypervel\Mcp\Transport\HeaderValue;
use Hypervel\Support\Collection;

class MirroredParameters implements Countable
{
    public const string ANNOTATION = 'x-mcp-header';

    public const string PREFIX = 'Mcp-Param-';

    protected const string TOKEN = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D';

    /**
     * Create a set of mirrored parameters.
     *
     * @param Collection<int, MirroredParameter> $parameters
     */
    protected function __construct(protected Collection $parameters)
    {
    }

    /**
     * Create an empty set of mirrored parameters.
     */
    public static function none(): self
    {
        return new self(collect());
    }

    /**
     * Parse and validate mirrored parameters in a tool schema.
     *
     * @param array<string, mixed> $inputSchema
     *
     * @throws MirroredParameterException
     */
    public static function fromSchema(array $inputSchema): self
    {
        $parameters = collect(self::parse($inputSchema, SchemaPath::root()));

        $duplicates = $parameters->duplicates(
            fn (MirroredParameter $parameter): string => strtolower($parameter->name),
        );

        if ($duplicates->isNotEmpty()) {
            throw new MirroredParameterException(
                'the [' . self::ANNOTATION . '] value [' . $duplicates->implode(', ') . '] is used more than once',
            );
        }

        return new self($parameters);
    }

    /**
     * Build the mirrored headers for the tool arguments.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, string>
     *
     * @throws MirroredParameterException
     */
    public function headers(array $arguments): array
    {
        $headers = [];

        foreach ($this->parameters as $parameter) {
            $value = $parameter->value($arguments);

            if ($value instanceof HeaderValue) {
                $headers[$parameter->header()] = (string) $value;
            }
        }

        return $headers;
    }

    /**
     * Get all mirrored parameters.
     *
     * @return Collection<int, MirroredParameter>
     */
    public function all(): Collection
    {
        return $this->parameters;
    }

    /**
     * Count the mirrored parameters.
     */
    public function count(): int
    {
        return count($this->parameters->all());
    }

    /**
     * Parse annotations on statically reachable schema properties.
     *
     * @param array<string, mixed> $schema
     * @return list<MirroredParameter>
     *
     * @throws MirroredParameterException
     */
    protected static function parse(array $schema, SchemaPath $path): array
    {
        $parameters = [];

        foreach ($schema as $key => $value) {
            if ($key === self::ANNOTATION) {
                throw new MirroredParameterException(
                    'an [' . self::ANNOTATION . '] annotation sits outside the statically reachable properties',
                );
            }

            if (! is_array($value)) {
                continue;
            }

            if ($key !== 'properties') {
                if (self::containsAnnotation($value)) {
                    throw new MirroredParameterException(
                        'an [' . self::ANNOTATION . '] annotation sits outside the statically reachable properties',
                    );
                }

                continue;
            }

            foreach ($value as $property => $child) {
                if (! is_array($child)) {
                    continue;
                }

                $childPath = $path->child((string) $property);

                if (array_key_exists(self::ANNOTATION, $child)) {
                    $parameters[] = self::parameter($child, $childPath);

                    unset($child[self::ANNOTATION]);
                }

                $parameters = [...$parameters, ...self::parse($child, $childPath)];
            }
        }

        return $parameters;
    }

    /**
     * Validate an annotated property and create its header mapping.
     *
     * @param array<string, mixed> $schema
     *
     * @throws MirroredParameterException
     */
    protected static function parameter(array $schema, SchemaPath $path): MirroredParameter
    {
        $name = $schema[self::ANNOTATION];

        if (! is_string($name) || preg_match(self::TOKEN, $name) !== 1) {
            throw new MirroredParameterException(
                'the [' . self::ANNOTATION . "] value on [{$path}] is not a valid header name token",
            );
        }

        $type = MirroredParameterType::fromSchema($schema['type'] ?? null);

        if (! $type instanceof MirroredParameterType) {
            throw new MirroredParameterException(
                'the [' . self::ANNOTATION . "] annotation on [{$path}] must sit on a string, integer, or boolean",
            );
        }

        return new MirroredParameter($path, $name, $type);
    }

    /**
     * Determine whether a schema branch contains a header annotation.
     *
     * @param array<array-key, mixed> $schema
     */
    protected static function containsAnnotation(array $schema): bool
    {
        foreach ($schema as $key => $value) {
            if ($key === self::ANNOTATION) {
                return true;
            }

            if (is_array($value) && self::containsAnnotation($value)) {
                return true;
            }
        }

        return false;
    }
}
