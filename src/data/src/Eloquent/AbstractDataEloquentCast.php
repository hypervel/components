<?php

declare(strict_types=1);

namespace Hypervel\Data\Eloquent;

use Hypervel\Container\Container;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Contracts\TransformableData;
use Hypervel\Data\Exceptions\CannotCastData;
use Hypervel\Data\Support\DataClassRepository;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\Transformation\DataTransformer;
use Hypervel\Database\Eloquent\Casts\Json;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Facades\Crypt;

/**
 * @template TData of (BaseData&TransformableData)
 */
abstract class AbstractDataEloquentCast
{
    protected const string DEFAULT_STORED_VALUE = '{}';

    protected readonly DataConfig $dataConfig;

    protected readonly DataClassRepository $dataClasses;

    protected readonly DataTransformer $dataTransformer;

    /**
     * Create a shared data Eloquent cast.
     *
     * @param class-string<TData> $dataClass
     * @param list<string> $arguments
     */
    public function __construct(
        protected readonly string $dataClass,
        protected readonly array $arguments = [],
    ) {
        $container = Container::getInstance();
        $this->dataConfig = $container->make(DataConfig::class);
        $this->dataClasses = $container->make(DataClassRepository::class);
        $this->dataTransformer = $container->make(DataTransformer::class);

        if (! $this->dataClasses->get($this->dataClass)->transformable) {
            throw CannotCastData::dataClassMustBeTransformable($this->dataClass);
        }
    }

    /**
     * Compare two stored data representations.
     */
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        if ($this->isEncrypted() && Crypt::getPreviousKeys() !== []) {
            return false;
        }

        $firstPayload = $this->decode($model, $key, $firstValue);
        $secondPayload = $this->decode($model, $key, $secondValue);

        if ($firstPayload === null || $secondPayload === null) {
            return $firstPayload === $secondPayload;
        }

        return $this->payloadsAreEquivalent($firstPayload, $secondPayload);
    }

    /**
     * Determine if the stored value uses an abstract-class envelope.
     */
    protected function isAbstractClassCast(): bool
    {
        $dataClass = $this->dataClasses->get($this->dataClass);

        return $dataClass->isAbstract && ! $dataClass->propertyMorphable;
    }

    /**
     * Resolve data from an abstract-class envelope.
     *
     * @param array<array-key, mixed> $payload
     * @return TData
     */
    protected function resolveMorphedData(Model $model, string $key, array $payload): BaseData
    {
        $type = $payload['type'] ?? null;
        $data = $payload['data'] ?? null;

        if (! is_string($type) || ! is_array($data)) {
            throw CannotCastData::invalidMorphEnvelope($model::class, $key);
        }

        // The stored type is an alias or a class name, which must stay within the declared type before its
        // metadata is built.
        $dataClass = $this->dataConfig->getMorphedDataClass($type) ?? $type;

        if (! is_a($dataClass, $this->dataClass, true)) {
            throw CannotCastData::invalidMorphClass($dataClass, $this->dataClass);
        }

        if ($this->dataClasses->get($dataClass)->isAbstract) {
            throw CannotCastData::invalidMorphClass($dataClass, $this->dataClass);
        }

        /** @var TData */
        return $dataClass::from($data);
    }

    /**
     * Wrap transformed data in its abstract-class envelope, under its alias or class name.
     *
     * @param TData $data
     * @param array<array-key, mixed> $payload
     * @return array{type: string, data: array<array-key, mixed>}
     */
    protected function createMorphEnvelope(BaseData&TransformableData $data, array $payload): array
    {
        return [
            'type' => $this->dataConfig->getDataClassAlias($data::class) ?? $data::class,
            'data' => $payload,
        ];
    }

    /**
     * Determine if the stored value is encrypted.
     */
    protected function isEncrypted(): bool
    {
        return in_array('encrypted', $this->arguments, true);
    }

    /**
     * Decode one stored representation through Eloquent's JSON codec.
     *
     * @return null|array<array-key, mixed>
     */
    protected function decode(Model $model, string $key, mixed $value): ?array
    {
        if (is_string($value) && $this->isEncrypted()) {
            $value = Crypt::decryptString($value);
        }

        if ($value === null && in_array('default', $this->arguments, true)) {
            $value = static::DEFAULT_STORED_VALUE;
        }

        if ($value === null) {
            return null;
        }

        $payload = Json::decode($value);

        if (! is_array($payload)) {
            throw CannotCastData::invalidStoredValue($model::class, $key);
        }

        return $payload;
    }

    /**
     * Determine if two decoded payloads contain the same values.
     */
    private function payloadsAreEquivalent(array $firstPayload, array $secondPayload): bool
    {
        if (count($firstPayload) !== count($secondPayload)) {
            return false;
        }

        // JSON object columns may normalize key order, so array identity is not meaningful here.
        foreach ($firstPayload as $key => $value) {
            if (! array_key_exists($key, $secondPayload)) {
                return false;
            }

            $other = $secondPayload[$key];

            if (is_array($value) && is_array($other)) {
                if (! $this->payloadsAreEquivalent($value, $other)) {
                    return false;
                }

                continue;
            }

            if ($value !== $other) {
                return false;
            }
        }

        return true;
    }
}
