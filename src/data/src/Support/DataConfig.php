<?php

declare(strict_types=1);

namespace Hypervel\Data\Support;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Mappers\NameMapper;
use Hypervel\Data\Normalizers\Normalizer;
use Hypervel\Data\Support\Creation\ValidationStrategy;
use Hypervel\Data\Transformers\Transformer;
use InvalidArgumentException;

class DataConfig
{
    /**
     * The accepted date formats in declaration order.
     *
     * @var non-empty-list<string>
     */
    public readonly array $dateFormats;

    /**
     * The timezone applied while casting and transforming dates.
     */
    public readonly ?string $dateTimezone;

    /**
     * The default validation strategy.
     */
    public readonly ValidationStrategy $validationStrategy;

    /**
     * The default input name mapper.
     *
     * @var null|class-string<NameMapper>
     */
    public readonly ?string $inputNameMapper;

    /**
     * The default output name mapper.
     *
     * @var null|class-string<NameMapper>
     */
    public readonly ?string $outputNameMapper;

    /**
     * The configured cast overrides.
     *
     * @var array<string, class-string<Cast>>
     */
    public readonly array $casts;

    /**
     * The configured transformer overrides.
     *
     * @var array<string, class-string<Transformer>>
     */
    public readonly array $transformers;

    /**
     * The configured global normalizers.
     *
     * @var list<class-string<Normalizer>>
     */
    public readonly array $normalizers;

    /**
     * The default resource wrapper.
     */
    public readonly ?string $wrap;

    /**
     * The maximum nested transformation depth.
     */
    public readonly ?int $maxTransformationDepth;

    /** @var array<string, class-string<BaseData>> */
    protected array $morphMap = [];

    /** @var array<class-string<BaseData>, string> */
    protected array $reversedMorphMap = [];

    /**
     * Create a new data configuration.
     */
    public function __construct(Repository $config)
    {
        $this->dateFormats = self::normalizeDateFormats($config->get('data.date_format'));
        $this->dateTimezone = self::nullableString($config, 'data.date_timezone');
        $this->validationStrategy = ValidationStrategy::from($config->string('data.validation_strategy'));
        $this->inputNameMapper = self::nameMapper($config, 'data.name_mapping_strategy.input');
        $this->outputNameMapper = self::nameMapper($config, 'data.name_mapping_strategy.output');
        $this->casts = self::extensionMap($config->array('data.casts'), Cast::class, 'data.casts');
        $this->transformers = self::extensionMap(
            $config->array('data.transformers'),
            Transformer::class,
            'data.transformers',
        );
        $this->normalizers = self::extensionList(
            $config->array('data.normalizers'),
            Normalizer::class,
            'data.normalizers',
        );
        $this->wrap = self::nullableString($config, 'data.wrap');
        $this->maxTransformationDepth = self::nullablePositiveInteger(
            $config,
            'data.max_transformation_depth',
        );
    }

    /**
     * Register the enforced data morph map.
     *
     * Boot-only. The aliases persist on the worker-lifetime configuration and
     * affect every subsequent data cast in the worker.
     *
     * @param array<string, class-string<BaseData>> $map
     */
    public function enforceMorphMap(array $map): void
    {
        $morphMap = $this->morphMap;
        $reversedMorphMap = $this->reversedMorphMap;

        foreach ($map as $alias => $class) {
            if (! is_string($alias) || $alias === '') {
                throw new InvalidArgumentException('Data morph aliases must be non-empty strings.');
            }

            if (! is_string($class) || ! is_a($class, BaseData::class, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Data morph class [%s] must implement [%s].',
                    is_string($class) ? $class : get_debug_type($class),
                    BaseData::class,
                ));
            }

            if (isset($morphMap[$alias]) && $morphMap[$alias] !== $class) {
                throw new InvalidArgumentException(sprintf(
                    'Data morph alias [%s] is already mapped to [%s].',
                    $alias,
                    $morphMap[$alias],
                ));
            }

            if (isset($reversedMorphMap[$class]) && $reversedMorphMap[$class] !== $alias) {
                throw new InvalidArgumentException(sprintf(
                    'Data morph class [%s] is already mapped to alias [%s].',
                    $class,
                    $reversedMorphMap[$class],
                ));
            }

            $morphMap[$alias] = $class;
            $reversedMorphMap[$class] = $alias;
        }

        $this->morphMap = $morphMap;
        $this->reversedMorphMap = $reversedMorphMap;
    }

    /**
     * Get the data class registered for a morph alias.
     *
     * @return null|class-string<BaseData>
     */
    public function getMorphedDataClass(string $alias): ?string
    {
        return $this->morphMap[$alias] ?? null;
    }

    /**
     * Get the morph alias registered for a data class.
     *
     * @param class-string<BaseData> $class
     */
    public function getDataClassAlias(string $class): ?string
    {
        return $this->reversedMorphMap[$class] ?? null;
    }

    /**
     * Normalize configured date formats.
     *
     * @return non-empty-list<string>
     */
    private static function normalizeDateFormats(mixed $formats): array
    {
        if (is_string($formats)) {
            return [$formats];
        }

        if (! is_array($formats) || $formats === []) {
            throw new InvalidArgumentException(
                'Configuration [data.date_format] must be a string or a non-empty array of strings.',
            );
        }

        foreach ($formats as $format) {
            if (! is_string($format)) {
                throw new InvalidArgumentException(
                    'Configuration [data.date_format] must be a string or a non-empty array of strings.',
                );
            }
        }

        return array_values($formats);
    }

    /**
     * Get a nullable string configuration value.
     */
    private static function nullableString(Repository $config, string $key): ?string
    {
        if (! $config->has($key)) {
            throw new InvalidArgumentException("Configuration [{$key}] is required.");
        }

        $value = $config->get($key);

        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'Configuration [%s] must be a string or null.',
                $key,
            ));
        }

        return $value;
    }

    /**
     * Get a nullable positive integer configuration value.
     */
    private static function nullablePositiveInteger(Repository $config, string $key): ?int
    {
        if (! $config->has($key)) {
            throw new InvalidArgumentException("Configuration [{$key}] is required.");
        }

        $value = $config->get($key);

        if ($value !== null && (! is_int($value) || $value < 1)) {
            throw new InvalidArgumentException(sprintf(
                'Configuration [%s] must be a positive integer or null.',
                $key,
            ));
        }

        return $value;
    }

    /**
     * Get a configured name mapper.
     *
     * @return null|class-string<NameMapper>
     */
    private static function nameMapper(Repository $config, string $key): ?string
    {
        $mapper = self::nullableString($config, $key);

        if ($mapper !== null) {
            self::ensureExtension($mapper, NameMapper::class, $key);
        }

        return $mapper;
    }

    /**
     * Validate a configured extension map.
     *
     * @template TExtension of object
     *
     * @param array<array-key, mixed> $extensions
     * @param class-string<TExtension> $contract
     * @return array<string, class-string<TExtension>>
     */
    private static function extensionMap(array $extensions, string $contract, string $key): array
    {
        $validated = [];

        foreach ($extensions as $type => $extension) {
            if (! is_string($type) || $type === '') {
                throw new InvalidArgumentException(sprintf(
                    'Configuration [%s] keys must be non-empty type strings.',
                    $key,
                ));
            }

            $validated[$type] = self::ensureExtension($extension, $contract, $key);
        }

        return $validated;
    }

    /**
     * Validate a configured extension list.
     *
     * @template TExtension of object
     *
     * @param array<array-key, mixed> $extensions
     * @param class-string<TExtension> $contract
     * @return list<class-string<TExtension>>
     */
    private static function extensionList(array $extensions, string $contract, string $key): array
    {
        $validated = [];

        foreach ($extensions as $extension) {
            $validated[] = self::ensureExtension($extension, $contract, $key);
        }

        return $validated;
    }

    /**
     * Validate one configured extension class.
     *
     * @template TExtension of object
     *
     * @param class-string<TExtension> $contract
     * @return class-string<TExtension>
     */
    private static function ensureExtension(mixed $extension, string $contract, string $key): string
    {
        if (! is_string($extension) || ! is_a($extension, $contract, true)) {
            throw new InvalidArgumentException(sprintf(
                'Configuration [%s] extension [%s] must implement [%s].',
                $key,
                is_string($extension) ? $extension : get_debug_type($extension),
                $contract,
            ));
        }

        return $extension;
    }
}
