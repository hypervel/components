<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Database\Eloquent\JsonEncodingException;
use Hypervel\Database\Eloquent\Model;
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
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): ?DataObject {
        $data = Json::decode((string) $value);

        if (! is_array($data)) {
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
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): array {
        $encoded = Json::encode($value);

        if ($encoded === false) {
            throw JsonEncodingException::forAttribute($model, $key, json_last_error_msg());
        }

        return [$key => $encoded];
    }

    /**
     * Specify a custom caster class for the data object.
     */
    public static function castUsing(string $class): string
    {
        return sprintf('%s:%s', static::class, $class);
    }
}
