<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Creation;

use BackedEnum;
use DateTimeInterface;
use Hypervel\Contracts\Container\Container;
use Hypervel\Data\Attributes\GetsCast;
use Hypervel\Data\Casts\BuiltinTypeCast;
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Casts\CastableCast;
use Hypervel\Data\Casts\DateTimeInterfaceCast;
use Hypervel\Data\Casts\EnumCast;
use Hypervel\Data\Casts\IterableItemCast;
use Hypervel\Data\Casts\Uncastable;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Enums\CustomCreationMethodType;
use Hypervel\Data\Exceptions\CannotCreateAbstractClass;
use Hypervel\Data\Exceptions\CannotCreateData;
use Hypervel\Data\Exceptions\CannotSetComputedValue;
use Hypervel\Data\Normalizers\Normalized\Normalized;
use Hypervel\Data\Normalizers\Normalized\UnknownProperty;
use Hypervel\Data\Normalizers\Normalizer;
use Hypervel\Data\Optional;
use Hypervel\Data\Support\DataClass;
use Hypervel\Data\Support\DataClassRepository;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\DataMethod;
use Hypervel\Data\Support\DataMethodMatch;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Types\NamedType;
use Hypervel\Data\Support\Types\Type;
use Hypervel\Data\Support\Validation\DataValidator;
use Hypervel\Http\Request;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;

use function data_set;

class DataCreator
{
    /**
     * Create a data creator.
     */
    public function __construct(
        protected readonly Container $container,
        protected readonly DataClassRepository $dataClasses,
        protected readonly DataConfig $config,
        protected readonly DataCollectableFactory $dataCollectables,
        protected readonly DataInstantiator $instantiator,
        protected readonly DataValidator $validator,
    ) {
    }

    /**
     * Create a data object through one fixed construction operation.
     *
     * @template TData of BaseData
     *
     * @param class-string<TData> $class
     * @return TData
     */
    public function create(
        string $class,
        CreationContext $context,
        mixed ...$payloads,
    ): BaseData {
        $data = $this->execute($class, $context, $payloads);

        /** @var BaseData $data */
        return $data;
    }

    /**
     * Validate a payload without casting or construction.
     *
     * @param class-string<BaseData> $class
     * @param array<array-key, mixed> $payloads
     * @return array<array-key, mixed>
     */
    public function validate(
        string $class,
        CreationContext $context,
        array $payloads,
    ): array {
        $validated = $this->execute($class, $context, $payloads);

        /** @var array<array-key, mixed> $validated */
        return $validated;
    }

    /**
     * Get validation rules for a payload without running the Validator.
     *
     * @param class-string<BaseData> $class
     * @param array<array-key, mixed> $payloads
     * @return array<string, list<array|object|string>>
     */
    public function getValidationRules(
        string $class,
        CreationContext $context,
        array $payloads,
    ): array {
        $rules = $this->execute($class, $context, $payloads);

        /** @var array<string, list<array|object|string>> $rules */
        return $rules;
    }

    /**
     * Run the fixed operation through its selected exit point.
     *
     * @param class-string<BaseData> $class
     * @param array<array-key, mixed> $payloads
     * @return BaseData|array<array-key, mixed>
     */
    protected function execute(
        string $class,
        CreationContext $context,
        array $payloads,
    ): BaseData|array {
        if ($context->mode === CreationMode::Create
            && count($payloads) === 1
            && $payloads[0] instanceof $class
        ) {
            return $payloads[0];
        }

        $shouldValidate = $context->mode !== CreationMode::Rules
            && $this->validator->shouldValidate($context, $payloads);
        $compilesRules = $shouldValidate || $context->mode === CreationMode::Rules;
        $request = $shouldValidate
            ? $this->validator->authorize($class, $payloads)
            : null;

        $state = ConstructionState::create($context, $class);
        $extensions = [];
        $direct = $this->fillNode(
            $class,
            $payloads,
            $state,
            $extensions,
            $shouldValidate,
            $compilesRules,
        );

        if ($direct !== null) {
            return $direct;
        }

        if ($compilesRules && $context->beforeValidationHooks !== []) {
            $this->applyPayloadHooks(
                $class,
                $context->beforeValidationHooks,
                $state,
                $extensions,
                $shouldValidate,
                $compilesRules,
            );
        }

        if ($context->mode === CreationMode::Rules) {
            return $this->validator->compile($state)->rules;
        }

        if ($shouldValidate) {
            $compiled = $this->validator->compile($state);
            $this->validator->validate($state, $compiled, $request);
        }

        if ($shouldValidate && $context->afterValidationHooks !== []) {
            $this->applyPayloadHooks(
                $class,
                $context->afterValidationHooks,
                $state,
                $extensions,
                false,
                false,
                reconcile: $context->mode === CreationMode::Create,
            );
        }

        if ($context->mode === CreationMode::Validate) {
            return $state->payload();
        }

        return $this->castAndInstantiateNode($state, $extensions);
    }

    /**
     * Normalize and fill one data node into the root construction state.
     *
     * @param class-string<BaseData> $class
     * @param array<array-key, mixed> $payloads
     * @param array<string, object> $extensions
     */
    protected function fillNode(
        string $class,
        array $payloads,
        ConstructionState $state,
        array &$extensions,
        bool $shouldValidate,
        bool $compilesRules,
    ): ?BaseData {
        $dataClass = $this->dataClasses->get($class);
        $match = $this->matchNamedFactory($dataClass, $state->context, $payloads);

        if ($match !== null) {
            $result = $this->invokeNamedFactory($dataClass, ...$match);

            if ($result instanceof $class) {
                return $result;
            }

            $payloads = [$result];
        }

        $normalizers = $this->resolveNormalizers($dataClass, $state->context, $extensions);
        $sources = [];
        $unknownInputSources = [];

        foreach ($payloads === [] ? [[]] : $payloads as $payload) {
            $source = SourceResolver::resolve($class, $payload, $normalizers);
            $sources[] = $source;
            $unknownInputSources[] = $payload instanceof Request
                ? ($payload->isJson() ? $payload->json()->all() : $payload->request->all())
                : $source;
        }

        $propertySources = $sources;
        $resolvedProperties = $this->resolveProperties($dataClass, $propertySources, $state->context);

        if ($state->context->prepareDataHooks !== []) {
            $input = $this->mergeSources($dataClass, $propertySources, $state->context);

            foreach ($state->context->prepareDataHooks as $hook) {
                $input = $hook($input);
            }

            $propertySources = [$input];
            $resolvedProperties = $this->resolveProperties($dataClass, $propertySources, $state->context);
        }

        $class = $this->resolveMorphClass($dataClass, $resolvedProperties);

        if ($class !== $dataClass->name) {
            $dataClass = $this->dataClasses->get($class);
            $resolvedProperties = $this->resolveProperties($dataClass, $propertySources, $state->context);
        }

        $state->setNodeClass($class);

        if ($shouldValidate && $dataClass->failOnUnknownFields) {
            $state->recordUnknownInput(
                $this->mergeSources($dataClass, $unknownInputSources, $state->context),
            );
        }

        $this->fillResolvedProperties(
            $dataClass,
            $resolvedProperties,
            $state,
            $extensions,
            $shouldValidate,
            $compilesRules,
            false,
        );

        return null;
    }

    /**
     * Fill one node introduced or changed by a validation payload hook.
     *
     * @param class-string<BaseData> $class
     * @param array<string, object> $extensions
     */
    protected function fillHookNode(
        string $class,
        mixed $payload,
        ConstructionState $state,
        array &$extensions,
        bool $shouldValidate,
        bool $compilesRules,
    ): ?BaseData {
        $dataClass = $this->dataClasses->get($class);
        $match = $this->matchNamedFactory($dataClass, $state->context, [$payload]);

        if ($match !== null) {
            $payload = $this->invokeNamedFactory($dataClass, ...$match);

            if ($payload instanceof $class) {
                return $payload;
            }
        }

        $source = SourceResolver::resolve($class, $payload, []);
        $resolvedProperties = $this->resolveProperties($dataClass, [$source], $state->context);
        $class = $this->resolveMorphClass($dataClass, $resolvedProperties);

        if ($class !== $dataClass->name) {
            $dataClass = $this->dataClasses->get($class);
            $resolvedProperties = $this->resolveProperties($dataClass, [$source], $state->context);
        }

        $state->setNodeClass($class);
        $this->fillResolvedProperties(
            $dataClass,
            $resolvedProperties,
            $state,
            $extensions,
            $shouldValidate,
            $compilesRules,
            true,
        );

        return null;
    }

    /**
     * Write one resolved data node and recursively fill its declared children.
     *
     * @param array<string, array{array-key, mixed}> $resolvedProperties
     * @param array<string, object> $extensions
     */
    protected function fillResolvedProperties(
        DataClass $dataClass,
        array $resolvedProperties,
        ConstructionState $state,
        array &$extensions,
        bool $shouldValidate,
        bool $compilesRules,
        bool $fromValidationHook,
    ): void {
        $contextualParameters = $this->contextualParameterNames($dataClass);

        foreach ($dataClass->properties as $property) {
            [$wireKey, $value] = $resolvedProperties[$property->name];
            $state->recordMapping($property->name, $wireKey);

            if (isset($contextualParameters[$property->name])) {
                continue;
            }

            if ($value instanceof UnknownProperty) {
                continue;
            }

            if ($property->computed) {
                throw CannotSetComputedValue::create($property);
            }

            $dataIterable = $this->dataIterableType($property);

            if ($property->isFinishedValue($value)) {
                $state->writeFinishedPropertyValue($wireKey, $value);

                continue;
            }

            if ($dataIterable !== null && $value instanceof LazyCollection) {
                if (! $compilesRules) {
                    $state->writePropertyValue($wireKey, $value);

                    continue;
                }

                $value = $value->all();
            }

            $iterableValues = $dataIterable === null ? null : $this->iterableValues($value);

            if ($dataIterable !== null && $iterableValues !== null) {
                /** @var class-string<BaseData> $itemDataClass */
                $itemDataClass = $dataIterable->dataClass;
                $state->writePropertyValue($wireKey, []);
                $state->enterProperty($property->name, $wireKey);

                try {
                    foreach ($iterableValues as $key => $item) {
                        if ($item instanceof $itemDataClass) {
                            $state->writeFinishedItemValue($key, $item);

                            continue;
                        }

                        $state->writeItemValue($key, []);
                        $state->enterItem($key);

                        try {
                            $direct = $fromValidationHook
                                ? $this->fillHookNode(
                                    $itemDataClass,
                                    $item,
                                    $state,
                                    $extensions,
                                    $shouldValidate,
                                    $compilesRules,
                                )
                                : $this->fillNode(
                                    $itemDataClass,
                                    [$item],
                                    $state,
                                    $extensions,
                                    $shouldValidate,
                                    $compilesRules,
                                );
                        } finally {
                            $state->leave();
                        }

                        if ($direct !== null) {
                            $state->writeFinishedItemValue($key, $direct);
                        }
                    }
                } finally {
                    $state->leave();
                }

                continue;
            }

            $nestedDataClass = $this->nestedDataClass($property);

            if ($nestedDataClass !== null
                && $value !== null
                && ! $value instanceof Optional
                && ! $property->type->acceptsValue($value)
            ) {
                $state->writePropertyValue($wireKey, []);
                $state->enterProperty($property->name, $wireKey);

                try {
                    $direct = $fromValidationHook
                        ? $this->fillHookNode(
                            $nestedDataClass,
                            $value,
                            $state,
                            $extensions,
                            $shouldValidate,
                            $compilesRules,
                        )
                        : $this->fillNode(
                            $nestedDataClass,
                            [$value],
                            $state,
                            $extensions,
                            $shouldValidate,
                            $compilesRules,
                        );
                } finally {
                    $state->leave();
                }

                if ($direct !== null) {
                    $state->writeFinishedPropertyValue($wireKey, $direct);
                }

                continue;
            }

            $state->writePropertyValue($wireKey, $value);
        }
    }

    /**
     * Apply one root payload-hook stage and reconcile changed selections.
     *
     * @param class-string<BaseData> $class
     * @param list<callable> $hooks
     * @param array<string, object> $extensions
     */
    protected function applyPayloadHooks(
        string $class,
        array $hooks,
        ConstructionState $state,
        array &$extensions,
        bool $shouldValidate,
        bool $compilesRules,
        bool $reconcile = true,
    ): void {
        $previousPayload = $state->payload();
        $payload = $previousPayload;

        foreach ($hooks as $hook) {
            $payload = $hook($payload);
        }

        if ($payload === $previousPayload) {
            return;
        }

        $state->replacePayload($payload);

        if ($reconcile) {
            $this->reconcileNode(
                $class,
                $previousPayload,
                $payload,
                $state,
                $extensions,
                $shouldValidate,
                $compilesRules,
            );
        }
    }

    /**
     * Reconcile one changed assembled data node without replaying prepare hooks.
     *
     * @param class-string<BaseData> $declaredClass
     * @param array<array-key, mixed> $previousPayload
     * @param array<array-key, mixed> $payload
     * @param array<string, object> $extensions
     */
    protected function reconcileNode(
        string $declaredClass,
        array $previousPayload,
        array $payload,
        ConstructionState $state,
        array &$extensions,
        bool $shouldValidate,
        bool $compilesRules,
    ): void {
        $declaredDataClass = $this->dataClasses->get($declaredClass);
        $declaredProperties = $this->resolveProperties(
            $declaredDataClass,
            [$payload],
            $state->context,
        );
        $class = $this->resolveMorphClass($declaredDataClass, $declaredProperties);
        $previousClass = $state->nodeClass() ?? $declaredClass;

        if ($class !== $previousClass) {
            $dataClass = $this->dataClasses->get($class);
            $resolvedProperties = $class === $declaredClass
                ? $declaredProperties
                : $this->resolveProperties($dataClass, [$payload], $state->context);
            $state->resetNodeStructure();
            $state->setNodeClass($class);
            $this->fillResolvedProperties(
                $dataClass,
                $resolvedProperties,
                $state,
                $extensions,
                $shouldValidate,
                $compilesRules,
                true,
            );

            return;
        }

        $dataClass = $this->dataClasses->get($class);
        $resolvedProperties = $class === $declaredClass
            ? $declaredProperties
            : $this->resolveProperties($dataClass, [$payload], $state->context);
        $contextualParameters = $this->contextualParameterNames($dataClass);

        foreach ($dataClass->properties as $property) {
            $previousWireKey = $state->originalKey($property->name);
            $previousValue = SourceReader::read($previousPayload, $previousWireKey, $property);
            [$wireKey, $value] = $resolvedProperties[$property->name];

            if ($wireKey !== $previousWireKey) {
                $state->replaceMapping($property->name, $wireKey);
            }

            if ($value === $previousValue || isset($contextualParameters[$property->name])) {
                continue;
            }

            if (! $value instanceof UnknownProperty && $property->computed) {
                throw CannotSetComputedValue::create($property);
            }

            $this->reconcileProperty(
                $property,
                $previousValue,
                $value,
                $wireKey,
                $state,
                $extensions,
                $shouldValidate,
                $compilesRules,
            );
        }
    }

    /**
     * Reconcile one changed property selection.
     *
     * @param array<string, object> $extensions
     */
    protected function reconcileProperty(
        DataProperty $property,
        mixed $previousValue,
        mixed $value,
        string|int $wireKey,
        ConstructionState $state,
        array &$extensions,
        bool $shouldValidate,
        bool $compilesRules,
    ): void {
        $dataIterable = $this->dataIterableType($property);

        if ($dataIterable !== null) {
            $this->reconcileDataIterable(
                $property,
                $dataIterable,
                $previousValue,
                $value,
                $wireKey,
                $state,
                $extensions,
                $shouldValidate,
                $compilesRules,
            );

            return;
        }

        $nestedDataClass = $this->nestedDataClass($property);

        if ($nestedDataClass === null) {
            return;
        }

        if ($value instanceof UnknownProperty
            || $value === null
            || $value instanceof Optional
            || $property->type->acceptsValue($value)
        ) {
            $state->clearChildStructure($property->name);

            if ($value instanceof BaseData) {
                $state->writeFinishedPropertyValue($wireKey, $value);
            }

            return;
        }

        if (is_array($previousValue) && is_array($value)) {
            $state->enterProperty($property->name, $wireKey);

            try {
                if ($state->nodeClass() !== null) {
                    $this->reconcileNode(
                        $nestedDataClass,
                        $previousValue,
                        $value,
                        $state,
                        $extensions,
                        $shouldValidate,
                        $compilesRules,
                    );

                    return;
                }
            } finally {
                $state->leave();
            }
        }

        $state->clearChildStructure($property->name);
        $state->writePropertyValue($wireKey, []);
        $state->enterProperty($property->name, $wireKey);

        try {
            $state->resetNodeStructure();
            $direct = $this->fillHookNode(
                $nestedDataClass,
                $value,
                $state,
                $extensions,
                $shouldValidate,
                $compilesRules,
            );
        } finally {
            $state->leave();
        }

        if ($direct !== null) {
            $state->writeFinishedPropertyValue($wireKey, $direct);
        }
    }

    /**
     * Reconcile one changed typed data iterable.
     *
     * @param array<string, object> $extensions
     */
    protected function reconcileDataIterable(
        DataProperty $property,
        NamedType $type,
        mixed $previousValue,
        mixed $value,
        string|int $wireKey,
        ConstructionState $state,
        array &$extensions,
        bool $shouldValidate,
        bool $compilesRules,
    ): void {
        if ($property->isFinishedValue($value)) {
            $state->clearChildStructure($property->name);
            $state->writeFinishedPropertyValue($wireKey, $value);

            return;
        }

        if ($value instanceof LazyCollection) {
            if (! $compilesRules) {
                $state->clearChildStructure($property->name);

                return;
            }

            $value = $value->all();
            $state->writePropertyValue($wireKey, $value);
        }

        $values = $this->iterableValues($value);

        if ($values === null) {
            $state->clearChildStructure($property->name);

            return;
        }

        $previousValues = $this->iterableValues($previousValue) ?? [];
        /** @var class-string<BaseData> $itemDataClass */
        $itemDataClass = $type->dataClass;
        $state->enterProperty($property->name, $wireKey);

        try {
            foreach ($values as $key => $item) {
                $previousItem = $previousValues[$key] ?? UnknownProperty::create();

                if ($item === $previousItem) {
                    continue;
                }

                if ($item instanceof $itemDataClass) {
                    $state->enterItem($key);

                    try {
                        $state->resetNodeStructure();
                    } finally {
                        $state->leave();
                    }

                    $state->writeFinishedItemValue($key, $item);

                    continue;
                }

                if (is_array($previousItem) && is_array($item)) {
                    $state->enterItem($key);

                    try {
                        if ($state->nodeClass() !== null) {
                            $this->reconcileNode(
                                $itemDataClass,
                                $previousItem,
                                $item,
                                $state,
                                $extensions,
                                $shouldValidate,
                                $compilesRules,
                            );

                            continue;
                        }
                    } finally {
                        $state->leave();
                    }
                }

                $state->writeItemValue($key, []);
                $state->enterItem($key);

                try {
                    $state->resetNodeStructure();
                    $direct = $this->fillHookNode(
                        $itemDataClass,
                        $item,
                        $state,
                        $extensions,
                        $shouldValidate,
                        $compilesRules,
                    );
                } finally {
                    $state->leave();
                }

                if ($direct !== null) {
                    $state->writeFinishedItemValue($key, $direct);
                }
            }
        } finally {
            $state->leave();
        }
    }

    /**
     * Cast and instantiate the node at the current construction path.
     *
     * @param array<string, object> $extensions
     */
    protected function castAndInstantiateNode(
        ConstructionState $state,
        array &$extensions,
    ): BaseData {
        $class = $state->nodeClass() ?? $state->context->dataClass;
        $dataClass = $this->dataClasses->get($class);
        $properties = [];
        $contextualParameters = $this->contextualParameterNames($dataClass);

        foreach ($dataClass->properties as $property) {
            if (isset($contextualParameters[$property->name])) {
                continue;
            }

            $wireKey = $state->originalKey($property->name);

            if (! $state->hasValue($wireKey)) {
                if ($property->hasDefaultValue) {
                    continue;
                }

                if ($property->type->isOptional) {
                    $properties[$property->name] = Optional::create();
                } elseif ($property->type->isNullable) {
                    $properties[$property->name] = null;
                }

                continue;
            }

            $properties[$property->name] = $this->castProperty(
                $property,
                $state->getValue($wireKey),
                $state,
                $extensions,
            );
        }

        foreach ($state->context->beforeCreationHooks as $hook) {
            $properties = $hook($properties);
        }

        $data = $this->instantiator->instantiate($dataClass, $properties);

        foreach ($state->context->afterCreationHooks as $hook) {
            $data = $hook($data);

            if (! $data instanceof $class) {
                throw CannotCreateData::invalidAfterCreationResult($dataClass, $data);
            }
        }

        return $data;
    }

    /**
     * Cast one supplied property value.
     *
     * @param array<string, object> $extensions
     */
    protected function castProperty(
        DataProperty $property,
        mixed $value,
        ConstructionState $state,
        array &$extensions,
    ): mixed {
        if ($value === null || $value instanceof Optional) {
            return $value;
        }

        if ($property->isFinishedValue($value)) {
            return $value;
        }

        $dataIterable = $this->dataIterableType($property);
        $shouldCast = ! is_object($value)
            || $dataIterable !== null
            || ! $property->type->acceptsValue($value);
        $casts = $shouldCast
            ? $this->propertyCasts($property, $state->context, $extensions)
            : [];

        foreach ($casts as $cast) {
            $casted = $cast->cast($property, $value, $state, $state->context);

            if (! $casted instanceof Uncastable) {
                return $casted;
            }
        }

        if ($dataIterable !== null) {
            return $this->castDataIterable($property, $dataIterable, $value, $state, $extensions);
        }

        $iterable = $this->typedIterableType($property);

        if ($iterable !== null) {
            return $this->castTypedIterable($property, $iterable, $value, $state, $extensions, $casts);
        }

        if ($property->type->acceptsValue($value)) {
            return $value;
        }

        $state->enterProperty($property->name, $state->originalKey($property->name));

        try {
            if ($state->nodeClass() !== null) {
                return $this->castAndInstantiateNode($state, $extensions);
            }
        } finally {
            $state->leave();
        }

        $dataObjectTypes = $property->type->getDataObjectTypes();

        if (count($dataObjectTypes) > 1) {
            $candidates = [];

            foreach ($dataObjectTypes as $dataObjectType) {
                /** @var class-string<BaseData> $candidate */
                $candidate = $dataObjectType->dataClass;
                $candidates[] = $candidate;
            }

            throw CannotCreateData::ambiguousDataObjectUnion($property, $candidates);
        }

        foreach ($property->type->getNamedTypes() as $type) {
            if (! $type->isCastable) {
                continue;
            }

            $key = 'castable:' . $type->name;
            $cast = $extensions[$key] ??= new CastableCast($type->name);
            $casted = $cast->cast($property, $value, $state, $state->context);

            if (! $casted instanceof Uncastable) {
                return $casted;
            }
        }

        $dateType = $property->type->findAcceptedTypeForBaseType(DateTimeInterface::class);

        if ($dateType !== null) {
            $key = 'date:' . $dateType;
            $cast = $extensions[$key] ??= new DateTimeInterfaceCast(type: $dateType);

            return $cast->cast(
                $property,
                $value,
                $state,
                $state->context,
            );
        }

        $enumType = $property->type->findAcceptedTypeForBaseType(BackedEnum::class);

        if ($enumType !== null) {
            $key = 'enum:' . $enumType;
            $cast = $extensions[$key] ??= new EnumCast($enumType);

            return $cast->cast(
                $property,
                $value,
                $state,
                $state->context,
            );
        }

        $builtin = $this->singleBuiltinType($property);

        if ($builtin !== null) {
            $key = 'builtin:' . $builtin;
            $cast = $extensions[$key] ??= new BuiltinTypeCast($builtin);

            return $cast->cast(
                $property,
                $value,
                $state,
                $state->context,
            );
        }

        return $value;
    }

    /**
     * Cast every item in one declared data iterable.
     *
     * @param array<string, object> $extensions
     */
    protected function castDataIterable(
        DataProperty $property,
        NamedType $type,
        mixed $value,
        ConstructionState $state,
        array &$extensions,
    ): mixed {
        $dataClass = $type->dataClass;

        if ($value instanceof LazyCollection) {
            return $value->map(
                fn (mixed $item): BaseData => $item instanceof $dataClass
                    ? $item
                    : $this->create($dataClass, $state->context, $item),
            );
        }

        $values = $this->iterableValues($value);

        if ($values === null) {
            return $value;
        }

        $items = [];
        $state->enterProperty($property->name, $state->originalKey($property->name));

        try {
            foreach ($values as $key => $item) {
                if ($item instanceof $dataClass) {
                    $items[$key] = $item;

                    continue;
                }

                $state->enterItem($key);

                try {
                    $items[$key] = $state->nodeClass() === null
                        ? $this->create($dataClass, $state->context, $item)
                        : $this->castAndInstantiateNode($state, $extensions);
                } finally {
                    $state->leave();
                }
            }
        } finally {
            $state->leave();
        }

        return $this->dataCollectables->forProperty(
            $type,
            $dataClass,
            $items,
            $state,
        );
    }

    /**
     * Cast every item in one declared non-data iterable.
     *
     * @param array<string, object> $extensions
     * @param list<Cast> $casts
     */
    protected function castTypedIterable(
        DataProperty $property,
        NamedType $type,
        mixed $value,
        ConstructionState $state,
        array &$extensions,
        array $casts,
    ): mixed {
        if ($value instanceof LazyCollection) {
            return $value->map(function (mixed $item) use ($property, $type, $state, &$extensions, $casts): mixed {
                return $this->castIterableItem(
                    $property,
                    $type->iterableItemType,
                    $item,
                    $state,
                    $extensions,
                    $casts,
                );
            });
        }

        $values = $this->iterableValues($value);

        if ($values === null) {
            return $value;
        }

        $items = [];

        foreach ($values as $key => $item) {
            $items[$key] = $this->castIterableItem(
                $property,
                $type->iterableItemType,
                $item,
                $state,
                $extensions,
                $casts,
            );
        }

        return $this->rebuildIterable($type, $items);
    }

    /**
     * Cast one declared iterable item.
     *
     * @param array<string, object> $extensions
     * @param list<Cast> $casts
     */
    protected function castIterableItem(
        DataProperty $property,
        Type $type,
        mixed $value,
        ConstructionState $state,
        array &$extensions,
        array $casts,
    ): mixed {
        if ($value === null) {
            return $value;
        }

        foreach ($casts as $cast) {
            if (! $cast instanceof IterableItemCast) {
                continue;
            }

            $casted = $cast->castIterableItem($property, $value, $state, $state->context);

            if (! $casted instanceof Uncastable) {
                return $casted;
            }
        }

        if ($type->acceptsValue($value)) {
            return $value;
        }

        foreach ($type->getNamedTypes() as $namedType) {
            if (! $namedType->isCastable) {
                continue;
            }

            $key = 'iterable-castable:' . $namedType->name;
            $cast = $extensions[$key] ??= new CastableCast($namedType->name);
            $casted = $cast->cast($property, $value, $state, $state->context);

            if (! $casted instanceof Uncastable) {
                return $casted;
            }
        }

        $dateType = $type->findAcceptedTypeForBaseType(DateTimeInterface::class);

        if ($dateType !== null) {
            $key = 'date:' . $dateType;
            $cast = $extensions[$key] ??= new DateTimeInterfaceCast(type: $dateType);

            return $cast->castIterableItem(
                $property,
                $value,
                $state,
                $state->context,
            );
        }

        $enumType = $type->findAcceptedTypeForBaseType(BackedEnum::class);

        if ($enumType !== null) {
            $key = 'enum:' . $enumType;
            $cast = $extensions[$key] ??= new EnumCast($enumType);

            return $cast->castIterableItem(
                $property,
                $value,
                $state,
                $state->context,
            );
        }

        $builtin = $this->singleBuiltinFromType($type);

        if ($builtin !== null) {
            $key = 'builtin:' . $builtin;
            $cast = $extensions[$key] ??= new BuiltinTypeCast($builtin);

            return $cast->castIterableItem(
                $property,
                $value,
                $state,
                $state->context,
            );
        }

        return $value;
    }

    /**
     * Rebuild an eager iterable in its declared container.
     *
     * @param array<array-key, mixed> $items
     */
    protected function rebuildIterable(NamedType $type, array $items): mixed
    {
        $iterableClass = $type->iterableClass;

        if ($iterableClass !== null && is_a($iterableClass, Collection::class, true)) {
            return new $iterableClass($items);
        }

        if ($iterableClass !== null && is_a($iterableClass, LazyCollection::class, true)) {
            return new $iterableClass($items);
        }

        return $items;
    }

    /**
     * Get the ordered custom casts applicable to a property.
     *
     * @param array<string, object> $extensions
     * @return list<Cast>
     */
    protected function propertyCasts(
        DataProperty $property,
        CreationContext $context,
        array &$extensions,
    ): array {
        $casts = [];

        if ($property->cast !== null) {
            $key = 'attribute-cast:' . spl_object_id($property->cast);

            if (! isset($extensions[$key])) {
                /** @var GetsCast $attribute */
                $attribute = $property->cast->newInstance();
                $extensions[$key] = $attribute->get();
            }

            $casts[] = $extensions[$key];
        }

        foreach ($context->casts as $baseType => $cast) {
            if ($property->type->findAcceptedTypeForBaseType($baseType) !== null) {
                $casts[] = $this->resolveCast($cast, $extensions);
            }
        }

        foreach ($property->configuredCasts as $cast) {
            $casts[] = $this->resolveCast($cast, $extensions);
        }

        return $casts;
    }

    /**
     * Resolve one cast once for the current root operation.
     *
     * @param Cast|class-string<Cast> $cast
     * @param array<string, object> $extensions
     */
    protected function resolveCast(Cast|string $cast, array &$extensions): Cast
    {
        if ($cast instanceof Cast) {
            return $cast;
        }

        $key = 'cast:' . $cast;

        /** @var Cast */
        return $extensions[$key] ??= $this->container->make($cast);
    }

    /**
     * Resolve the custom normalizers for one data class.
     *
     * @param array<string, object> $extensions
     * @return list<Normalizer>
     */
    protected function resolveNormalizers(
        DataClass $dataClass,
        CreationContext $context,
        array &$extensions,
    ): array {
        $normalizers = [];

        if ($dataClass->hasLifecycleMethod('normalizers')) {
            $class = $dataClass->name;
            $normalizers = $this->container->call($class::normalizers(...));
        }

        array_push($normalizers, ...$context->normalizers, ...$this->config->normalizers);

        foreach ($normalizers as $index => $normalizer) {
            if ($normalizer instanceof Normalizer) {
                continue;
            }

            $key = 'normalizer:' . $normalizer;

            /** @var Normalizer */
            $normalizers[$index] = $extensions[$key] ??= $this->container->make($normalizer);
        }

        return array_values($normalizers);
    }

    /**
     * Resolve every declared property against the normalized sources.
     *
     * @param list<array|Normalized> $sources
     * @return array<string, array{array-key, mixed}>
     */
    protected function resolveProperties(
        DataClass $dataClass,
        array $sources,
        CreationContext $context,
    ): array {
        $properties = [];

        foreach ($dataClass->properties as $property) {
            $properties[$property->name] = $this->resolveProperty($sources, $property, $context);
        }

        return $properties;
    }

    /**
     * Resolve one property with mapped-key precedence inside each source.
     *
     * @param list<array|Normalized> $sources
     * @return array{array-key, mixed}
     */
    protected function resolveProperty(
        array $sources,
        DataProperty $property,
        CreationContext $context,
    ): array {
        $mappedKey = $context->mapPropertyNames
            ? ($property->inputMappedName ?? $property->name)
            : $property->name;

        foreach ($sources as $source) {
            $value = SourceReader::read($source, $mappedKey, $property);

            if (! $value instanceof UnknownProperty) {
                return [$mappedKey, $value];
            }

            if ($mappedKey === $property->name) {
                continue;
            }

            $value = SourceReader::read($source, $property->name, $property);

            if (! $value instanceof UnknownProperty) {
                return [$property->name, $value];
            }
        }

        return [$mappedKey, UnknownProperty::create()];
    }

    /**
     * Merge normalized sources for a prepare-data hook.
     *
     * @param list<array|Normalized> $sources
     * @return array<array-key, mixed>
     */
    protected function mergeSources(
        DataClass $dataClass,
        array $sources,
        CreationContext $context,
    ): array {
        $merged = [];

        foreach ($sources as $source) {
            $values = is_array($source)
                ? $source
                : $this->projectNormalizedSource($dataClass, $source, $context);
            $merged = $this->mergeMissingValues($merged, $values);
        }

        return $merged;
    }

    /**
     * Project a non-enumerable normalized source into declared wire keys.
     *
     * @return array<array-key, mixed>
     */
    protected function projectNormalizedSource(
        DataClass $dataClass,
        Normalized $source,
        CreationContext $context,
    ): array {
        $values = [];

        foreach ($dataClass->properties as $property) {
            [$key, $value] = $this->resolveProperty([$source], $property, $context);

            if ($value instanceof UnknownProperty) {
                continue;
            }

            if (is_int($key)) {
                $values[$key] = $value;
            } else {
                data_set($values, $key, $value);
            }
        }

        return $values;
    }

    /**
     * Recursively fill only values absent from the earlier source.
     *
     * @param array<array-key, mixed> $target
     * @param array<array-key, mixed> $source
     * @return array<array-key, mixed>
     */
    protected function mergeMissingValues(array $target, array $source): array
    {
        foreach ($source as $key => $value) {
            if (! array_key_exists($key, $target)) {
                $target[$key] = $value;

                continue;
            }

            if (is_array($target[$key]) && is_array($value)) {
                $target[$key] = $this->mergeMissingValues($target[$key], $value);
            }
        }

        return $target;
    }

    /**
     * Find the first compatible named object factory.
     *
     * @param array<array-key, mixed> $payloads
     * @return null|array{DataMethod, DataMethodMatch}
     */
    protected function matchNamedFactory(
        DataClass $dataClass,
        CreationContext $context,
        array $payloads,
    ): ?array {
        if ($context->disableMagicalCreation) {
            return null;
        }

        foreach ($dataClass->methods as $method) {
            if ($method->customCreationMethodType !== CustomCreationMethodType::Object
                || in_array($method->name, $context->ignoredMagicalMethods, true)
            ) {
                continue;
            }

            $match = $method->matchPayloads($context, ...$payloads);

            if ($match !== null) {
                return [$method, $match];
            }
        }

        return null;
    }

    /**
     * Invoke one matched named factory without method-binding interception.
     */
    protected function invokeNamedFactory(
        DataClass $dataClass,
        DataMethod $method,
        DataMethodMatch $match,
    ): mixed {
        $class = $dataClass->name;
        $methodName = $method->name;

        return $match->requiresContainerCall
            ? $this->container->call($class::$methodName(...), $match->arguments)
            : $class::$methodName(...$match->arguments);
    }

    /**
     * Resolve the concrete class selected by an abstract property's discriminator.
     *
     * @param array<string, array{array-key, mixed}> $resolvedProperties
     * @return class-string<BaseData>
     */
    protected function resolveMorphClass(DataClass $dataClass, array $resolvedProperties): string
    {
        if (! $dataClass->isAbstract) {
            return $dataClass->name;
        }

        if (! $dataClass->propertyMorphable) {
            throw CannotCreateAbstractClass::morphClassWasNotResolved($dataClass->name);
        }

        $properties = [];

        foreach ($dataClass->properties as $property) {
            if (! $property->morphable) {
                continue;
            }

            $value = $resolvedProperties[$property->name][1];

            if ($value instanceof UnknownProperty) {
                $value = $this->propertyDefaultValue($dataClass, $property);
            }

            if ($value instanceof UnknownProperty) {
                throw CannotCreateAbstractClass::morphClassWasNotResolved($dataClass->name);
            }

            $enum = $property->type->findAcceptedTypeForBaseType(BackedEnum::class);

            if ($enum !== null && (is_int($value) || is_string($value))) {
                $value = $enum::tryFrom($value) ?? $value;
            }

            $properties[$property->name] = $value;
        }

        $class = $dataClass->name;
        $resolvedClass = $class::morph($properties);

        if ($resolvedClass === null) {
            throw CannotCreateAbstractClass::morphClassWasNotResolved($class);
        }

        if (! is_a($resolvedClass, $class, true)
            || ! is_a($resolvedClass, BaseData::class, true)
        ) {
            throw CannotCreateAbstractClass::invalidMorphClass($class, $resolvedClass);
        }

        if ($this->dataClasses->get($resolvedClass)->isAbstract) {
            throw CannotCreateAbstractClass::invalidMorphClass($class, $resolvedClass);
        }

        return $resolvedClass;
    }

    /**
     * Materialize a property default only when morph selection requires it.
     */
    protected function propertyDefaultValue(DataClass $dataClass, DataProperty $property): mixed
    {
        if (! $property->hasDefaultValue) {
            return UnknownProperty::create();
        }

        if (! $property->isConstructorParameter) {
            return $property->reflection->getDefaultValue();
        }

        foreach ($dataClass->constructorParameters as $parameter) {
            if ($parameter->name === $property->name) {
                return $parameter->reflection->getDefaultValue();
            }
        }

        return UnknownProperty::create();
    }

    /**
     * Get constructor parameter names resolved contextually by the container.
     *
     * @return array<string, true>
     */
    protected function contextualParameterNames(DataClass $dataClass): array
    {
        $names = [];

        foreach ($dataClass->constructorParameters as $parameter) {
            if ($parameter->contextualAttribute !== null) {
                $names[$parameter->name] = true;
            }
        }

        return $names;
    }

    /**
     * Get the one unambiguous nested data class declared by a property.
     *
     * @return null|class-string<BaseData>
     */
    protected function nestedDataClass(DataProperty $property): ?string
    {
        $types = $property->type->getDataObjectTypes();

        return count($types) === 1 ? $types[0]->dataClass : null;
    }

    /**
     * Get the one unambiguous data iterable declared by a property.
     */
    protected function dataIterableType(DataProperty $property): ?NamedType
    {
        $types = $property->type->getDataCollectableTypes();

        return count($types) === 1 ? $types[0] : null;
    }

    /**
     * Get the one unambiguous non-data iterable declared by a property.
     */
    protected function typedIterableType(DataProperty $property): ?NamedType
    {
        $types = array_values(array_filter(
            $property->type->getIterableTypes(),
            fn (NamedType $type): bool => ! $type->kind->isDataCollectable(),
        ));

        return count($types) === 1 ? $types[0] : null;
    }

    /**
     * Convert an eager iterable to its keyed values.
     *
     * @return null|array<array-key, mixed>
     */
    protected function iterableValues(mixed $value): ?array
    {
        return $this->dataCollectables->items($value);
    }

    /**
     * Get one unambiguous built-in scalar cast target.
     *
     * @return null|'array'|'bool'|'float'|'int'|'string'
     */
    protected function singleBuiltinType(DataProperty $property): ?string
    {
        return $this->singleBuiltinFromType($property->type->type);
    }

    /**
     * Get one unambiguous built-in scalar cast target from a type.
     *
     * @return null|'array'|'bool'|'float'|'int'|'string'
     */
    protected function singleBuiltinFromType(Type $type): ?string
    {
        $types = array_values(array_filter(
            $type->getNamedTypes(),
            fn (NamedType $type): bool => in_array(
                $type->name,
                ['array', 'bool', 'float', 'int', 'string'],
                true,
            ),
        ));

        return count($types) === 1 ? $types[0]->name : null;
    }
}
