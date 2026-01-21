<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use Hyperf\Contract\CastsAttributes;
use Hypervel\Support\DataObject;
use InvalidArgumentException;

class AsDataObject implements CastsAttributes
{
    public function __construct(
        protected string $argument
    ) {
        if (! is_subclass_of($this->argument, DataObject::class)) {
            throw new InvalidArgumentException(sprintf(
                'The given class %s is not a subclass of %s.',
                $this->argument,
                DataObject::class
            ));
        }
    }

    /**
     * Cast the given value.
     *
     * @param array<string, mixed> $attributes
     */
    public function get(
        mixed $model,
        string $key,
        mixed $value,
        array $attributes,
    ): ?DataObject {
        if (! $data = json_decode((string) $value, true)) {
            return null;
        }

        return call_user_func_array(
            [$this->argument, 'make'],
            [$data, true]
        );
    }

    /**
     * Prepare the given value for storage.
     *
     * @param array<string, mixed> $attributes
     */
    public function set(
        mixed $model,
        string $key,
        mixed $value,
        array $attributes,
    ): string {
        return json_encode($value);
    }

    /**
     * Specify a custom caster class for the data object.
     */
    public static function castUsing(string $class): string
    {
        return sprintf('%s:%s', static::class, $class);
    }
}
