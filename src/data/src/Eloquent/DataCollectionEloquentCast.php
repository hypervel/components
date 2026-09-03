<?php

declare(strict_types=1);

namespace Hypervel\Data\Eloquent;

use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Contracts\TransformableData;
use Hypervel\Data\DataCollection;
use Hypervel\Data\Exceptions\CannotCastData;
use Hypervel\Database\Eloquent\Casts\Json;
use Hypervel\Database\Eloquent\JsonEncodingException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Facades\Crypt;

/**
 * @template TData of (BaseData&TransformableData)
 * @template TDataCollection of DataCollection<array-key, TData>
 *
 * @extends AbstractDataEloquentCast<TData>
 * @implements CastsAttributes<null|TDataCollection,null|array<array-key, array<array-key, mixed>|TData>|TDataCollection>
 */
class DataCollectionEloquentCast extends AbstractDataEloquentCast implements CastsAttributes
{
    protected const string DEFAULT_STORED_VALUE = '[]';

    /**
     * Create a data collection Eloquent cast.
     *
     * @param class-string<TData> $dataClass
     * @param class-string<TDataCollection> $dataCollectionClass
     * @param list<string> $arguments
     */
    public function __construct(
        string $dataClass,
        protected readonly string $dataCollectionClass = DataCollection::class,
        array $arguments = [],
    ) {
        parent::__construct($dataClass, $arguments);
    }

    /**
     * Transform the stored attribute into a data collection.
     *
     * @param array<string, mixed> $attributes
     * @return null|TDataCollection
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?DataCollection
    {
        $payload = $this->decode($model, $key, $value);

        if ($payload === null) {
            return null;
        }

        $isAbstractClassCast = $this->isAbstractClassCast();

        if (! $isAbstractClassCast) {
            foreach ($payload as $itemKey => $item) {
                if (! is_array($item)) {
                    throw CannotCastData::invalidStoredCollectionItem($model::class, $key, $itemKey);
                }
            }

            /** @var TDataCollection */
            return new ($this->dataCollectionClass)($this->dataClass, $payload);
        }

        $items = [];

        foreach ($payload as $itemKey => $item) {
            if (! is_array($item)) {
                throw CannotCastData::invalidStoredCollectionItem($model::class, $key, $itemKey);
            }

            $items[$itemKey] = $this->resolveMorphedData($model, $key, $item);
        }

        /** @var TDataCollection */
        return new ($this->dataCollectionClass)($this->dataClass, $items);
    }

    /**
     * Transform a data collection into its stored representation.
     *
     * @param null|array<array-key, array<array-key, mixed>|TData>|TDataCollection $value
     * @param array<string, mixed> $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DataCollection) {
            $value = $value->items();
        }

        if (! is_array($value)) {
            throw CannotCastData::shouldBeArray($model::class, $key);
        }

        $payload = [];
        $isAbstractClassCast = $this->isAbstractClassCast();
        $context = $this->dataTransformer->persistenceContext();

        foreach ($value as $itemKey => $item) {
            if (is_array($item) && ! $isAbstractClassCast) {
                $item = ($this->dataClass)::from($item);
            }

            if (! $item instanceof BaseData) {
                throw CannotCastData::shouldBeData($model::class, $key);
            }

            if (! $item instanceof TransformableData) {
                throw CannotCastData::shouldBeTransformableData($model::class, $key);
            }

            if (! $item instanceof $this->dataClass) {
                throw CannotCastData::shouldBeDataClass($model::class, $key, $this->dataClass);
            }

            $itemPayload = $item->transform($context);
            $payload[$itemKey] = $isAbstractClassCast
                ? $this->createMorphEnvelope($item, $itemPayload)
                : $itemPayload;
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
