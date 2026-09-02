<?php

declare(strict_types=1);

namespace Hypervel\Data\Eloquent;

use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Contracts\TransformableData;
use Hypervel\Data\Exceptions\CannotCastData;
use Hypervel\Data\Support\Transformation\TransformationContextFactory;
use Hypervel\Database\Eloquent\Casts\Json;
use Hypervel\Database\Eloquent\JsonEncodingException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Facades\Crypt;

/**
 * @template TData of (BaseData&TransformableData)
 *
 * @extends AbstractDataEloquentCast<TData>
 * @implements CastsAttributes<null|TData,null|array|TData>
 */
class DataEloquentCast extends AbstractDataEloquentCast implements CastsAttributes
{
    /**
     * Transform the stored attribute into data.
     *
     * @param array<string, mixed> $attributes
     * @return null|TData
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?BaseData
    {
        $payload = $this->decode($model, $key, $value);

        if ($payload === null) {
            return null;
        }

        if ($this->isAbstractClassCast()) {
            return $this->resolveMorphedData($model, $key, $payload);
        }

        /** @var TData */
        return ($this->dataClass)::from($payload);
    }

    /**
     * Transform data into its stored representation.
     *
     * @param null|array<array-key, mixed>|TData $value
     * @param array<string, mixed> $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $isAbstractClassCast = $this->isAbstractClassCast();

        if (is_array($value) && ! $isAbstractClassCast) {
            $value = ($this->dataClass)::from($value);
        }

        if (! $value instanceof BaseData) {
            throw CannotCastData::shouldBeData($model::class, $key);
        }

        if (! $value instanceof TransformableData) {
            throw CannotCastData::shouldBeTransformableData($model::class, $key);
        }

        if (! $value instanceof $this->dataClass) {
            throw CannotCastData::shouldBeDataClass($model::class, $key, $this->dataClass);
        }

        $payload = $value->transform(TransformationContextFactory::forPersistence());

        if ($isAbstractClassCast) {
            $payload = $this->createMorphEnvelope($value, $payload);
        }

        $encoded = Json::encode($payload);

        if ($encoded === false) {
            throw JsonEncodingException::forAttribute($model, $key, json_last_error_msg());
        }

        if ($this->isEncrypted()) {
            /** @var string $encoded */
            return Crypt::encryptString($encoded);
        }

        /** @var string */
        return $encoded;
    }
}
