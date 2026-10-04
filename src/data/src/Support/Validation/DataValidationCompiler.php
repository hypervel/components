<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation;

use BackedEnum;
use DateTimeInterface;
use Hypervel\Contracts\Container\Container;
use Hypervel\Data\Attributes\Validation\ArrayType;
use Hypervel\Data\Attributes\Validation\BooleanType;
use Hypervel\Data\Attributes\Validation\Enum;
use Hypervel\Data\Attributes\Validation\IntegerType;
use Hypervel\Data\Attributes\Validation\Nullable;
use Hypervel\Data\Attributes\Validation\Numeric;
use Hypervel\Data\Attributes\Validation\Present;
use Hypervel\Data\Attributes\Validation\Required;
use Hypervel\Data\Attributes\Validation\Rule;
use Hypervel\Data\Attributes\Validation\Sometimes;
use Hypervel\Data\Attributes\Validation\StringType;
use Hypervel\Data\Attributes\Validation\ValidationAttribute;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Exceptions\CannotBuildValidationRule;
use Hypervel\Data\Lazy;
use Hypervel\Data\Optional;
use Hypervel\Data\RuleInferrers\RuleInferrer;
use Hypervel\Data\Support\Creation\ConstructionState;
use Hypervel\Data\Support\Creation\CreationMode;
use Hypervel\Data\Support\DataClass;
use Hypervel\Data\Support\DataClassRepository;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Types\NamedType;
use Hypervel\Validation\Rules\RequiredIf as NativeRequiredIf;
use Hypervel\Validation\Rules\RequiredUnless as NativeRequiredUnless;
use TypeError;
use UnitEnum;

class DataValidationCompiler
{
    /** @var list<string> */
    protected const array PRESENCE_RULES = [
        'present',
        'present_if',
        'present_unless',
        'present_with',
        'present_with_all',
        'required',
        'required_if',
        'required_if_accepted',
        'required_if_declined',
        'required_unless',
        'required_with',
        'required_with_all',
        'required_without',
        'required_without_all',
    ];

    /**
     * The attribute type of each inferred rule, which a declared attribute of that type replaces.
     *
     * @var array<string, class-string<ValidationRule>>
     */
    protected const array INFERRED_RULE_TYPES = [
        'sometimes' => Sometimes::class,
        'nullable' => Nullable::class,
        'present' => Present::class,
        'required' => Required::class,
        'array' => ArrayType::class,
        'boolean' => BooleanType::class,
        'numeric' => Numeric::class,
        'integer' => IntegerType::class,
        'string' => StringType::class,
    ];

    /**
     * Create a data validation compiler.
     */
    public function __construct(
        protected readonly DataClassRepository $dataClasses,
        protected readonly Container $container,
        protected readonly RuleDenormalizer $ruleDenormalizer,
        protected readonly RuleNormalizer $ruleNormalizer,
        protected readonly DataConfig $config,
    ) {
    }

    /**
     * Compile validation for one filled data graph.
     */
    public function compile(ConstructionState $state): CompiledValidation
    {
        $state->setRuleInferrers($this->resolveRuleInferrers());
        $accumulator = new ValidationAccumulator;
        $compileUnknownFields = $state->unknownInput() !== null;
        $lifecycleDeclarations = [
            'messages' => [],
            'attributes' => [],
        ];

        $this->compileNode(
            $state->nodeClass() ?? $state->context->dataClass,
            $state,
            ValidationPath::create(),
            ValidationPath::create(),
            $accumulator,
            $lifecycleDeclarations,
            $compileUnknownFields,
        );
        $this->appendStructuralMarkers($accumulator, $state->payload());

        return new CompiledValidation(
            rules: $accumulator->rules,
            messages: $accumulator->messages,
            attributes: $accumulator->attributes,
            preservedPaths: $this->preservedPaths($accumulator),
            additionalFields: $accumulator->additionalFields,
            allowedSubtrees: $accumulator->allowedSubtrees,
        );
    }

    /**
     * Compile validation for one filled root data collection.
     *
     * @param class-string<BaseData> $dataClass
     */
    public function compileCollection(
        ConstructionState $state,
        string $dataClass,
    ): CompiledValidation {
        $state->setRuleInferrers($this->resolveRuleInferrers());
        $accumulator = new ValidationAccumulator;
        $compileUnknownFields = $state->unknownInput() !== null;
        $lifecycleDeclarations = [
            'messages' => [],
            'attributes' => [],
        ];

        $this->compileDataIterableValues(
            $dataClass,
            $state->payload(),
            $state,
            ValidationPath::create(),
            ValidationPath::create(),
            $accumulator,
            $lifecycleDeclarations,
            $compileUnknownFields,
        );
        $this->appendStructuralMarkers($accumulator, $state->payload());

        return new CompiledValidation(
            rules: $accumulator->rules,
            messages: $accumulator->messages,
            attributes: $accumulator->attributes,
            preservedPaths: $this->preservedPaths($accumulator),
            additionalFields: $accumulator->additionalFields,
            allowedSubtrees: $accumulator->allowedSubtrees,
        );
    }

    /**
     * Get the paths restored after validation.
     *
     * A constructor input is restored only when no compiled rule applies to it or to anything beneath it;
     * otherwise the validated result owns it.
     *
     * @return list<ValidationPath>
     */
    protected function preservedPaths(ValidationAccumulator $accumulator): array
    {
        if ($accumulator->constructorInputPaths === []) {
            return $accumulator->preservedPaths;
        }

        $rules = [];

        foreach (array_keys($accumulator->rules) as $rule) {
            $rules[] = ValidationPath::create((string) $rule)->rawSegments();
        }

        $paths = $accumulator->preservedPaths;

        foreach ($accumulator->constructorInputPaths as $input) {
            $segments = $input->rawSegments();

            foreach ($rules as $rule) {
                if (count($rule) < count($segments)) {
                    continue;
                }

                // A null segment is a wildcard and matches any item on either side.
                foreach ($segments as $index => $segment) {
                    if ($segment !== null && $rule[$index] !== null && $segment !== $rule[$index]) {
                        continue 2;
                    }
                }

                continue 2;
            }

            $paths[] = $input;
        }

        return $paths;
    }

    /**
     * Compile one data node into the root rule graph.
     *
     * @param class-string<BaseData> $class
     * @param array{messages: array<class-string<BaseData>, array>, attributes: array<class-string<BaseData>, array>} $lifecycleDeclarations
     */
    protected function compileNode(
        string $class,
        ConstructionState $state,
        ValidationPath $path,
        ValidationPath $structuralPath,
        ValidationAccumulator $accumulator,
        array &$lifecycleDeclarations,
        bool $compileUnknownFields,
        bool $observed = true,
    ): void {
        $dataClass = $this->dataClasses->get($class);
        $contextualProperties = $dataClass->contextualParameters;
        // Class rules and configured inferrers see the node's actual input and path, while rules compile at $path.
        // An unobserved node has no input, as Fill never entered it.
        $context = $dataClass->hasLifecycleMethod('rules') || $this->config->ruleInferrers !== []
            ? new ValidationContext(
                payload: $observed ? $state->currentPayload() : [],
                fullPayload: $state->payload(),
                path: $observed ? new ValidationPath($state->path()) : $path,
            )
            : null;

        foreach ($dataClass->constructorInputs as $input) {
            $inputPath = $path->property($input);
            $accumulator->constructorInputPaths[] = $inputPath;

            if ($compileUnknownFields) {
                $accumulator->allowedSubtrees[] = $inputPath->get();
            }
        }

        foreach ($dataClass->properties as $property) {
            if ($property->computed) {
                continue;
            }

            $wireKey = $this->wireKey($property, $state, $observed);
            $inputPath = $property->inputPath($wireKey);
            $propertyPath = $path->property($wireKey);
            $structuralPropertyPath = $structuralPath->property($wireKey);

            if (isset($contextualProperties[$property->name])) {
                if ($compileUnknownFields) {
                    $this->recordAuxiliaryPath(
                        $property,
                        $propertyPath,
                        $accumulator,
                    );
                }

                continue;
            }

            $hasValue = $observed && $state->hasValue($inputPath);
            $value = $hasValue ? $state->getValue($inputPath) : null;

            if (! $property->validate) {
                $accumulator->preservedPaths[] = $propertyPath;

                if ($compileUnknownFields) {
                    $this->recordAuxiliaryPath(
                        $property,
                        $propertyPath,
                        $accumulator,
                    );
                }

                continue;
            }

            if ($property->isFinishedValue($value)) {
                $accumulator->preservedPaths[] = $propertyPath;
                $accumulator->finishedStructuralPaths[$structuralPropertyPath->get()] = true;

                if ($compileUnknownFields) {
                    $accumulator->allowedSubtrees[] = $propertyPath->get();
                }

                continue;
            }

            $dataType = $this->receivingDataType($property, $state, $hasValue, $value);
            $nestedDataClass = $dataType !== null && $dataType->kind->isDataObject() ? $dataType->dataClass : null;
            $dataIterableClass = $dataType !== null && $dataType->kind->isDataCollectable() ? $dataType->dataClass : null;
            $inferredRequired = false;
            $propertyRulePath = $propertyPath->get();
            $accumulator->rules[$propertyRulePath] = $this->propertyRules(
                $property,
                $path,
                $context,
                $propertyPath,
                $hasValue,
                $value,
                $state,
                $this->inferredTypeRule($property, $hasValue, $value, $dataType),
                $dataIterableClass !== null,
                $inferredRequired,
            );

            // An abstract node remains only when Fill could not resolve its morph, so its discriminator is invalid.
            if ($property->morphable && $dataClass->isAbstract) {
                $accumulator->rules[$propertyRulePath][] = new EnsurePropertyMorphable;
            }

            if (! $propertyPath->equals($structuralPropertyPath)) {
                $accumulator->addMarkerCandidate(
                    $structuralPropertyPath,
                    $propertyPath,
                );
            }

            if ($inferredRequired) {
                $accumulator->inferredRequiredPaths[$propertyRulePath] = true;
            }

            if ($compileUnknownFields && $this->hasUnstructuredDescendants($property)) {
                $accumulator->allowedSubtrees[] = $propertyPath->get();
            }

            if (! $hasValue || $value === null || $value instanceof Optional) {
                // A missing or null object that does not accept null still reports its own required fields,
                // as flat Laravel rules would. An omitted Optional or defaulted object is not required.
                if ($nestedDataClass !== null
                    && $value === null
                    && ! $property->type->isNullable
                    && ($hasValue || (! $property->type->isOptional && ! $property->hasDefaultValue))
                ) {
                    $this->compileNode(
                        $nestedDataClass,
                        $state,
                        $propertyPath,
                        $structuralPropertyPath,
                        $accumulator,
                        $lifecycleDeclarations,
                        $compileUnknownFields,
                        observed: false,
                    );
                }

                continue;
            }

            if ($nestedDataClass !== null && is_array($value)) {
                $state->enterProperty($property->name, $inputPath);

                try {
                    $this->compileNode(
                        $state->nodeClass() ?? $nestedDataClass,
                        $state,
                        $propertyPath,
                        $structuralPropertyPath,
                        $accumulator,
                        $lifecycleDeclarations,
                        $compileUnknownFields,
                    );
                } finally {
                    $state->leave();
                }

                continue;
            }

            if ($dataIterableClass !== null && is_array($value)) {
                $this->compileDataIterable(
                    $property,
                    $dataIterableClass,
                    $value,
                    $state,
                    $propertyPath,
                    $structuralPropertyPath,
                    $accumulator,
                    $lifecycleDeclarations,
                    $compileUnknownFields,
                );

                continue;
            }

            if ($nestedDataClass !== null || $dataIterableClass !== null) {
                // Input Fill could not read must fail here, and a blank string skips every non-implicit rule.
                $accumulator->rules[$propertyRulePath] = $this->mergeRules(
                    ['required'],
                    $accumulator->rules[$propertyRulePath],
                );
            }
        }

        $this->applyClassRules(
            $dataClass,
            $state,
            $path,
            $context,
            $structuralPath,
            $accumulator,
            $observed,
        );

        if ($state->context->mode !== CreationMode::Rules) {
            $this->applyClassMessagesAndAttributes(
                $dataClass,
                $state,
                $path,
                $structuralPath,
                $accumulator,
                $lifecycleDeclarations,
                $observed,
            );
        }
    }

    /**
     * Compile nested rules for one data iterable.
     *
     * @param class-string<BaseData> $dataClass
     * @param array<array-key, mixed> $values
     * @param array{messages: array<class-string<BaseData>, array>, attributes: array<class-string<BaseData>, array>} $lifecycleDeclarations
     */
    protected function compileDataIterable(
        DataProperty $property,
        string $dataClass,
        array $values,
        ConstructionState $state,
        ValidationPath $path,
        ValidationPath $structuralPath,
        ValidationAccumulator $accumulator,
        array &$lifecycleDeclarations,
        bool $compileUnknownFields,
    ): void {
        $wireKey = $state->originalKey($property->name);
        $state->enterProperty($property->name, $property->inputPath($wireKey));

        try {
            $this->compileDataIterableValues(
                $dataClass,
                $values,
                $state,
                $path,
                $structuralPath,
                $accumulator,
                $lifecycleDeclarations,
                $compileUnknownFields,
            );
        } finally {
            $state->leave();
        }
    }

    /**
     * Compile one data iterable from its current structure path.
     *
     * @param class-string<BaseData> $dataClass
     * @param array<array-key, mixed> $values
     * @param array{messages: array<class-string<BaseData>, array>, attributes: array<class-string<BaseData>, array>} $lifecycleDeclarations
     */
    protected function compileDataIterableValues(
        string $dataClass,
        array $values,
        ConstructionState $state,
        ValidationPath $path,
        ValidationPath $structuralPath,
        ValidationAccumulator $accumulator,
        array &$lifecycleDeclarations,
        bool $compileUnknownFields,
    ): void {
        // A finished object, or input Fill could not read, is checked at its own item path.
        $hasNonArrayValues = false;

        foreach ($values as $value) {
            if (! is_array($value)) {
                $hasNonArrayValues = true;

                break;
            }
        }

        if ($values === []) {
            $this->compileNode(
                $dataClass,
                $state,
                $path->wildcard(),
                $structuralPath->wildcard(),
                $accumulator,
                $lifecycleDeclarations,
                $compileUnknownFields,
                observed: false,
            );

            return;
        }

        if ($state->isCurrentCollectionUniform()
            && ! $hasNonArrayValues
        ) {
            $firstKey = array_key_first($values);
            $state->enterItem($firstKey);

            try {
                $selectedClass = $state->nodeClass() ?? $dataClass;

                if (! $this->usesDynamicRules($selectedClass, $state)) {
                    $this->compileNode(
                        $selectedClass,
                        $state,
                        $path->wildcard(),
                        $structuralPath->wildcard(),
                        $accumulator,
                        $lifecycleDeclarations,
                        $compileUnknownFields,
                    );

                    return;
                }
            } finally {
                $state->leave();
            }

            $speculative = $this->compileUniformDynamicIterable(
                $dataClass,
                $values,
                $state,
                $path,
                $structuralPath,
                $lifecycleDeclarations,
                $compileUnknownFields,
            );

            if ($speculative !== null) {
                $accumulator->merge($speculative);

                return;
            }
        }

        foreach ($values as $key => $value) {
            $itemPath = $path->item($key);

            if ($value instanceof $dataClass) {
                $accumulator->preservedPaths[] = $itemPath;
                $accumulator->finishedStructuralPaths[$structuralPath->wildcard()->get()] = true;

                if ($compileUnknownFields) {
                    $accumulator->allowedSubtrees[] = $itemPath->get();
                }

                continue;
            }

            if (! is_array($value)) {
                // A blank string skips every non-implicit rule, so `required` rejects it.
                $accumulator->rules[$itemPath->get()] = ['required', 'array'];

                continue;
            }

            $state->enterItem($key);
            $itemAccumulator = new ValidationAccumulator;

            try {
                $this->compileNode(
                    $state->nodeClass() ?? $dataClass,
                    $state,
                    $itemPath,
                    $structuralPath->wildcard(),
                    $itemAccumulator,
                    $lifecycleDeclarations,
                    $compileUnknownFields,
                );
            } finally {
                $state->leave();
            }

            $accumulator->merge($itemAccumulator);
        }
    }

    /**
     * Compile dynamic items at one wildcard path when their complete output matches.
     *
     * @param class-string<BaseData> $dataClass
     * @param array<array-key, mixed> $values
     * @param array{messages: array<class-string<BaseData>, array>, attributes: array<class-string<BaseData>, array>} $lifecycleDeclarations
     */
    protected function compileUniformDynamicIterable(
        string $dataClass,
        array $values,
        ConstructionState $state,
        ValidationPath $path,
        ValidationPath $structuralPath,
        array &$lifecycleDeclarations,
        bool $compileUnknownFields,
    ): ?ValidationAccumulator {
        $compiled = null;

        foreach (array_keys($values) as $key) {
            $state->enterItem($key);
            $itemAccumulator = new ValidationAccumulator;

            try {
                $this->compileNode(
                    $state->nodeClass() ?? $dataClass,
                    $state,
                    $path->wildcard(),
                    $structuralPath->wildcard(),
                    $itemAccumulator,
                    $lifecycleDeclarations,
                    $compileUnknownFields,
                );
            } finally {
                $state->leave();
            }

            if ($compiled === null) {
                $compiled = $itemAccumulator;

                continue;
            }

            if (! $compiled->equals($itemAccumulator)) {
                return null;
            }
        }

        return $compiled;
    }

    /**
     * Build the rules for one property: inferred presence and type rules, then declared attributes.
     *
     * @param null|ValidationContext $context the node's context, present when class rules or configured inferrers use it
     * @param null|'array'|'boolean'|'integer'|'numeric'|'string'|Enum $typeRule
     * @return list<array|object|string>
     */
    protected function propertyRules(
        DataProperty $property,
        ValidationPath $nodePath,
        ?ValidationContext $context,
        ValidationPath $propertyPath,
        bool $hasValue,
        mixed $value,
        ConstructionState $state,
        string|Enum|null $typeRule,
        bool $receivesDataCollection,
        bool &$inferredRequired,
    ): array {
        $attributes = [];

        foreach ($property->attributes->all(ValidationRule::class) as $recipe) {
            $attribute = $recipe->newInstance();

            // A Rule attribute's rules become typed attributes where possible, so they replace inferred rules of their type.
            if ($attribute instanceof Rule) {
                array_push($attributes, ...$this->ruleNormalizer->execute($attribute));
            } else {
                $attributes[] = $attribute;
            }
        }

        $generatedRules = null;

        foreach ($state->context->beforeRulesHooks as $hook) {
            $generatedRules = $hook($property, $propertyPath, $value);

            if ($generatedRules !== null) {
                break;
            }
        }

        if ($generatedRules === null && $this->config->ruleInferrers !== []) {
            $evaluatedRules = [];
            $hasPresenceRule = false;

            foreach ($attributes as $attribute) {
                if ($attribute instanceof RequiringRule) {
                    $hasPresenceRule = true;
                } elseif ($attribute instanceof ValidationAttribute) {
                    // A keyword shows presence without resolving parameters an inferrer may remove.
                    $hasPresenceRule = $hasPresenceRule
                        || in_array($attribute::keyword(), self::PRESENCE_RULES, true);
                } else {
                    $evaluatedRules[spl_object_id($attribute)] = $denormalizedRules = $this->ruleDenormalizer->execute($attribute, $nodePath);
                    $hasPresenceRule = $hasPresenceRule || $this->hasPresenceRule($denormalizedRules);
                }
            }

            $inferredRules = $this->inferRules($property, $typeRule, $hasPresenceRule, $receivesDataCollection, $hasValue);
            $propertyRules = $this->applyRuleInferrers($property, $attributes, $inferredRules, $context, $state);
            // An inferrer may remove the inferred requirement; class rules must not treat it as present.
            $inferredRequired = in_array('required', $inferredRules, true)
                && $propertyRules->hasType(Required::class);
            $rules = [];

            // Surviving rules reuse any evaluation needed for presence; removed keyword attributes never resolve.
            foreach ($propertyRules->all() as $rule) {
                array_push(
                    $rules,
                    ...($evaluatedRules[spl_object_id($rule)] ?? $this->ruleDenormalizer->execute($rule, $nodePath)),
                );
            }
        } else {
            $attributeRules = [];
            $hasPresenceRule = false;
            $hasEnumAttribute = false;

            foreach ($attributes as $attribute) {
                $denormalizedRules = $this->ruleDenormalizer->execute($attribute, $nodePath);
                $hasPresenceRule = $hasPresenceRule
                    || $attribute instanceof RequiringRule
                    || $this->hasPresenceRule($denormalizedRules);
                $hasEnumAttribute = $hasEnumAttribute || $attribute instanceof Enum;
                array_push($attributeRules, ...$denormalizedRules);
            }

            if ($generatedRules !== null) {
                // Hook rules replace inference, so they come before the declared attributes as inferred rules do.
                $rules = $this->mergeRules(
                    $this->ruleDenormalizer->execute($generatedRules, $nodePath),
                    $attributeRules,
                );
            } else {
                $generatedRules = $this->inferRules(
                    $property,
                    match (true) {
                        ! $typeRule instanceof Enum => $typeRule,
                        // A declared enum rule, with its own case restrictions, replaces the inferred one.
                        $hasEnumAttribute => null,
                        default => $typeRule->getRule($nodePath),
                    },
                    $hasPresenceRule,
                    $receivesDataCollection,
                    $hasValue,
                );

                // A declared attribute replaces the inferred rule of its type, as PropertyRules does for inferrers.
                foreach ($generatedRules as $index => $generatedRule) {
                    if (! is_string($generatedRule)) {
                        continue;
                    }

                    foreach ($attributes as $attribute) {
                        if ($attribute instanceof (self::INFERRED_RULE_TYPES[$generatedRule])) {
                            unset($generatedRules[$index]);

                            break;
                        }
                    }
                }

                $inferredRequired = in_array('required', $generatedRules, true);
                $rules = [...$generatedRules, ...$attributeRules];
            }
        }

        foreach ($state->context->afterRulesHooks as $hook) {
            $rules = $this->ruleDenormalizer->execute(
                $hook($rules, $property, $propertyPath, $value),
                $nodePath,
            );
        }

        return $rules;
    }

    /**
     * Infer fixed presence and type rules for one property.
     *
     * @param null|'array'|'boolean'|'integer'|'numeric'|'string'|object $typeRule a rule name, or a rule object used as given
     * @return list<'array'|'boolean'|'integer'|'nullable'|'numeric'|'present'|'required'|'sometimes'|'string'|object>
     */
    protected function inferRules(
        DataProperty $property,
        string|object|null $typeRule,
        bool $hasPresenceRule,
        bool $receivesDataCollection,
        bool $hasValue,
    ): array {
        $rules = [];

        if ($property->type->isNullable) {
            $rules[] = 'nullable';
        }

        // A declared presence rule must still apply when the key is missing.
        if ($property->type->isOptional && ! $hasPresenceRule) {
            $rules[] = 'sometimes';
        }

        if (! $property->type->isNullable
            && ! $property->type->isOptional
            && ! $hasPresenceRule
            // A default fills an omitted value, but supplied input must still be present.
            && (! $property->hasDefaultValue || $hasValue)
        ) {
            // A list of data objects may be empty, which `required` would reject.
            $rules[] = $receivesDataCollection ? 'present' : 'required';
        }

        if ($typeRule !== null) {
            $rules[] = $typeRule;
        }

        if ($rules === [] && ! $hasPresenceRule) {
            $rules[] = 'sometimes';
        }

        return $rules;
    }

    /**
     * Run the configured rule inferrers over one property's inferred and attribute rules.
     *
     * Declared attributes follow the inferred rules and replace inferred rules of the same type.
     *
     * @param list<ValidationRule> $attributes
     * @param list<'array'|'boolean'|'integer'|'nullable'|'numeric'|'present'|'required'|'sometimes'|'string'|Enum> $inferredRules
     */
    protected function applyRuleInferrers(
        DataProperty $property,
        array $attributes,
        array $inferredRules,
        ValidationContext $context,
        ConstructionState $state,
    ): PropertyRules {
        $rules = new PropertyRules;

        foreach ($inferredRules as $inferredRule) {
            $rules->add(is_string($inferredRule) ? new (self::INFERRED_RULE_TYPES[$inferredRule]) : $inferredRule);
        }

        if ($attributes !== []) {
            // Added together, so several declared requiring rules all remain.
            $rules->add(...$attributes);
        }

        foreach ($state->ruleInferrers() as $inferrer) {
            $rules = $inferrer->handle($property, $rules, $context);
        }

        return $rules;
    }

    /**
     * Resolve the configured rule inferrers for one compilation.
     *
     * They are resolved per compilation, so scoped inferrers follow the current coroutine.
     *
     * @return list<RuleInferrer>
     */
    protected function resolveRuleInferrers(): array
    {
        if ($this->config->ruleInferrers === []) {
            return [];
        }

        return array_map(
            fn (string $inferrer): RuleInferrer => $this->container->make($inferrer),
            $this->config->ruleInferrers,
        );
    }

    /**
     * Apply class-owned rule replacements in PHP property-name space.
     */
    protected function applyClassRules(
        DataClass $dataClass,
        ConstructionState $state,
        ValidationPath $path,
        ?ValidationContext $context,
        ValidationPath $structuralPath,
        ValidationAccumulator $accumulator,
        bool $observed,
    ): void {
        if (! $dataClass->hasLifecycleMethod('rules')) {
            return;
        }

        $classRules = $this->callArrayLifecycleMethod(
            $dataClass,
            'rules',
            ['context' => $context],
        );
        $classOwnedRules = [];

        foreach ($classRules as $key => $declaration) {
            $rulePaths = $this->collapseTranslatedRulePaths(
                $this->translateRulePaths(
                    (string) $key,
                    $dataClass,
                    $state,
                    $path,
                    $structuralPath,
                    $observed,
                ),
                $accumulator,
            );
            $classRule = $this->ruleDenormalizer->execute($declaration, $path);

            foreach ($rulePaths as $translatedPath) {
                $rulePath = $translatedPath->path->get();
                $fannedOut = ! $translatedPath->path->equals(
                    $translatedPath->structuralPath,
                );

                if ($fannedOut && isset($classOwnedRules[$rulePath])) {
                    $classOwnedRules[$rulePath] = $this->mergeRules(
                        $classOwnedRules[$rulePath],
                        $classRule,
                    );
                } else {
                    unset($classOwnedRules[$rulePath]);
                    $classOwnedRules[$rulePath] = $classRule;
                }

                if ($fannedOut) {
                    $accumulator->addMarkerCandidate(
                        $translatedPath->structuralPath,
                        $translatedPath->path,
                    );
                }
            }
        }

        foreach ($classOwnedRules as $rulePath => $rules) {
            if ($dataClass->mergeValidationRules) {
                $existingRules = $accumulator->rules[$rulePath] ?? [];

                if (isset($accumulator->inferredRequiredPaths[$rulePath])
                    && $this->hasPresenceRule($rules)
                ) {
                    $existingRules = array_values(array_filter(
                        $existingRules,
                        static fn (array|object|string $rule): bool => $rule !== 'required',
                    ));
                    unset($accumulator->inferredRequiredPaths[$rulePath]);
                }

                $rules = $this->mergeRules($existingRules, $rules);
            } else {
                unset($accumulator->inferredRequiredPaths[$rulePath]);
            }

            // Class rules follow their declaration order, which decides how overlapping exact and wildcard keys merge.
            unset($accumulator->rules[$rulePath]);
            $accumulator->rules[$rulePath] = $rules;
        }
    }

    /**
     * Collapse concrete translations onto an existing authoritative wildcard rule.
     *
     * @param list<TranslatedValidationPath> $paths
     * @return list<TranslatedValidationPath>
     */
    protected function collapseTranslatedRulePaths(
        array $paths,
        ValidationAccumulator $accumulator,
    ): array {
        if ($paths === []) {
            return [];
        }

        $structuralPath = $paths[0]->structuralPath;

        foreach ($paths as $path) {
            if (! $path->structuralPath->equals($structuralPath)) {
                return $paths;
            }
        }

        if (! array_key_exists($structuralPath->get(), $accumulator->rules)) {
            return $paths;
        }

        return [new TranslatedValidationPath($structuralPath, $structuralPath)];
    }

    /**
     * Apply class-owned messages and attribute labels in PHP property-name space.
     *
     * @param array{messages: array<class-string<BaseData>, array>, attributes: array<class-string<BaseData>, array>} $lifecycleDeclarations
     */
    protected function applyClassMessagesAndAttributes(
        DataClass $dataClass,
        ConstructionState $state,
        ValidationPath $path,
        ValidationPath $structuralPath,
        ValidationAccumulator $accumulator,
        array &$lifecycleDeclarations,
        bool $observed,
    ): void {
        $class = $dataClass->name;

        if ($dataClass->hasLifecycleMethod('messages')) {
            if (! array_key_exists($class, $lifecycleDeclarations['messages'])) {
                $lifecycleDeclarations['messages'][$class] = $this->callArrayLifecycleMethod(
                    $dataClass,
                    'messages',
                );
            }

            /** @var array<string, array<string, string>|string> $declarations */
            $declarations = $lifecycleDeclarations['messages'][$class];

            foreach ($declarations as $key => $message) {
                if (is_string($message) && ! str_contains($key, '.')) {
                    $messagePath = $path->wildcard()->property($key);
                    $paths = [new TranslatedValidationPath($messagePath, $messagePath)];
                } else {
                    $paths = $this->translateRulePaths(
                        $key,
                        $dataClass,
                        $state,
                        $path,
                        $structuralPath,
                        $observed,
                    );
                }

                foreach ($paths as $messagePath) {
                    $key = $messagePath->path->get();

                    if (! array_key_exists($key, $accumulator->messages)) {
                        $accumulator->messages[$key] = $message;
                    }
                }
            }
        }

        if (! $dataClass->hasLifecycleMethod('attributes')) {
            return;
        }

        if (! array_key_exists($class, $lifecycleDeclarations['attributes'])) {
            $lifecycleDeclarations['attributes'][$class] = $this->callArrayLifecycleMethod(
                $dataClass,
                'attributes',
            );
        }

        /** @var array<string, string> $declarations */
        $declarations = $lifecycleDeclarations['attributes'][$class];

        foreach ($declarations as $key => $attribute) {
            foreach ($this->translateRulePaths(
                $key,
                $dataClass,
                $state,
                $path,
                $structuralPath,
                $observed,
            ) as $attributePath) {
                $key = $attributePath->path->get();

                if (! array_key_exists($key, $accumulator->attributes)) {
                    $accumulator->attributes[$key] = $attribute;
                }
            }
        }
    }

    /**
     * Invoke one array-returning validation lifecycle method.
     */
    protected function callArrayLifecycleMethod(
        DataClass $dataClass,
        string $method,
        array $parameters = [],
    ): array {
        $result = $this->container->call(
            [$dataClass->name, $method],
            $parameters,
        );

        if (! is_array($result)) {
            throw new TypeError(sprintf(
                '%s::%s() must return an array, %s returned.',
                $dataClass->name,
                $method,
                get_debug_type($result),
            ));
        }

        return $result;
    }

    /**
     * Translate a class rule key to its observed wire paths.
     *
     * @return list<TranslatedValidationPath>
     */
    protected function translateRulePaths(
        string $key,
        DataClass $dataClass,
        ConstructionState $state,
        ValidationPath $path,
        ValidationPath $structuralPath,
        bool $observed,
    ): array {
        return $this->translateRuleSegments(
            ValidationPath::create($key)->rawSegments(),
            0,
            $dataClass,
            $state,
            $path,
            $structuralPath,
            $observed,
        );
    }

    /**
     * Recursively translate class-rule segments through Data metadata.
     *
     * @param list<null|array-key> $segments
     * @return list<TranslatedValidationPath>
     */
    protected function translateRuleSegments(
        array $segments,
        int $offset,
        DataClass $dataClass,
        ConstructionState $state,
        ValidationPath $path,
        ValidationPath $structuralPath,
        bool $observed,
    ): array {
        if (! array_key_exists($offset, $segments)) {
            return [new TranslatedValidationPath(
                $path,
                $structuralPath,
            )];
        }

        $segment = $segments[$offset];
        $property = is_string($segment)
            ? ($dataClass->properties[$segment] ?? null)
            : null;

        if ($property === null) {
            return [new TranslatedValidationPath(
                $this->appendUnmappedSegments($path, $segments, $offset),
                $this->appendUnmappedSegments($structuralPath, $segments, $offset),
            )];
        }

        if ($property->computed
            || ! $property->validate
            || isset($dataClass->contextualParameters[$property->name])
        ) {
            return [];
        }

        $wireKey = $this->wireKey($property, $state, $observed);
        $inputPath = $property->inputPath($wireKey);
        $path = $path->property($wireKey);
        $structuralPath = $structuralPath->property($wireKey);
        $hasValue = $observed && $state->hasValue($inputPath);
        $value = $hasValue ? $state->getValue($inputPath) : null;

        if ($property->isFinishedValue($value)) {
            return [];
        }

        if (! array_key_exists($offset + 1, $segments)) {
            return [new TranslatedValidationPath(
                $path,
                $structuralPath,
            )];
        }

        $dataType = $this->receivingDataType($property, $state, $hasValue, $value);

        if ($dataType === null) {
            return [new TranslatedValidationPath(
                $this->appendUnmappedSegments($path, $segments, $offset + 1),
                $this->appendUnmappedSegments($structuralPath, $segments, $offset + 1),
            )];
        }

        if ($dataType->kind->isDataObject()) {
            /** @var class-string<BaseData> $nestedDataClass */
            $nestedDataClass = $dataType->dataClass;
            $state->enterProperty($property->name, $inputPath);

            try {
                return $this->translateRuleSegments(
                    $segments,
                    $offset + 1,
                    $this->dataClasses->get($state->nodeClass() ?? $nestedDataClass),
                    $state,
                    $path,
                    $structuralPath,
                    $hasValue && is_array($value),
                );
            } finally {
                $state->leave();
            }
        }

        /** @var class-string<BaseData> $itemDataClass */
        $itemDataClass = $dataType->dataClass;
        $itemSegment = $segments[$offset + 1];
        $values = $hasValue && is_array($value) ? $value : [];
        $state->enterProperty($property->name, $inputPath);

        try {
            if ($itemSegment !== null) {
                $itemValue = $values[$itemSegment] ?? null;

                if ($itemValue instanceof $itemDataClass) {
                    return [];
                }

                $state->enterItem($itemSegment);

                try {
                    return $this->translateRuleSegments(
                        $segments,
                        $offset + 2,
                        $this->dataClasses->get($state->nodeClass() ?? $itemDataClass),
                        $state,
                        $path->item($itemSegment),
                        $structuralPath->item($itemSegment),
                        array_key_exists($itemSegment, $values) && is_array($itemValue),
                    );
                } finally {
                    $state->leave();
                }
            }

            if ($values === []) {
                return $this->translateRuleSegments(
                    $segments,
                    $offset + 2,
                    $this->dataClasses->get($itemDataClass),
                    $state,
                    $path->wildcard(),
                    $structuralPath->wildcard(),
                    false,
                );
            }

            // As in compilation, a finished object or input Fill could not read has its own item path.
            $hasNonArrayValues = false;

            foreach ($values as $itemValue) {
                if (! is_array($itemValue)) {
                    $hasNonArrayValues = true;

                    break;
                }
            }

            if ($state->isCurrentCollectionUniform() && ! $hasNonArrayValues) {
                $firstKey = array_key_first($values);
                $state->enterItem($firstKey);

                try {
                    $selectedClass = $state->nodeClass() ?? $itemDataClass;

                    if (! $this->usesDynamicRules($selectedClass, $state)) {
                        return $this->translateRuleSegments(
                            $segments,
                            $offset + 2,
                            $this->dataClasses->get($selectedClass),
                            $state,
                            $path->wildcard(),
                            $structuralPath->wildcard(),
                            is_array($values[$firstKey]),
                        );
                    }
                } finally {
                    $state->leave();
                }
            }

            $paths = [];

            foreach ($values as $itemKey => $itemValue) {
                if ($itemValue instanceof $itemDataClass) {
                    continue;
                }

                $state->enterItem($itemKey);

                try {
                    array_push($paths, ...$this->translateRuleSegments(
                        $segments,
                        $offset + 2,
                        $this->dataClasses->get($state->nodeClass() ?? $itemDataClass),
                        $state,
                        $path->item($itemKey),
                        $structuralPath->wildcard(),
                        is_array($itemValue),
                    ));
                } finally {
                    $state->leave();
                }
            }

            return $paths;
        } finally {
            $state->leave();
        }
    }

    /**
     * Append rule segments that no longer describe Data properties.
     *
     * @param list<null|array-key> $segments
     */
    protected function appendUnmappedSegments(
        ValidationPath $path,
        array $segments,
        int $offset,
    ): ValidationPath {
        for ($index = $offset; $index < count($segments); ++$index) {
            $path = $segments[$index] === null
                ? $path->wildcard()
                : $path->item($segments[$index]);
        }

        return $path;
    }

    /**
     * Append wildcard identity markers after every rule replacement is complete.
     */
    protected function appendStructuralMarkers(
        ValidationAccumulator $accumulator,
        array $payload,
    ): void {
        $markers = [];

        foreach ($accumulator->markerCandidates as $candidate => $rulePaths) {
            $crossesFinishedValue = false;

            foreach (array_keys($accumulator->finishedStructuralPaths) as $finishedPath) {
                if ($candidate === $finishedPath
                    || str_starts_with($candidate, $finishedPath . '.')
                ) {
                    $crossesFinishedValue = true;

                    break;
                }
            }

            if ($crossesFinishedValue) {
                foreach (array_keys($rulePaths) as $rulePath) {
                    $rules = $accumulator->rules[$rulePath] ?? [];

                    if ($rules !== [] && $this->hasDistinctRule($rules)) {
                        throw CannotBuildValidationRule::create(sprintf(
                            'Cannot build the distinct rule for [%s] because its collection mixes raw and finished Data values.',
                            $candidate,
                        ));
                    }
                }

                continue;
            }

            $path = ValidationPath::create($candidate);
            $concretePaths = $path->matchingWildcardPayloadValidationPaths($payload);

            if ($concretePaths === []) {
                continue;
            }

            $coveredPaths = [];

            foreach (array_keys($rulePaths) as $rulePath) {
                $rules = $accumulator->rules[$rulePath] ?? [];

                if ($rules === []) {
                    continue;
                }

                $contributor = ValidationPath::create($rulePath);

                if (! $contributor->containsWildcards()) {
                    $coveredPaths[$rulePath] = true;

                    continue;
                }

                foreach ($contributor->matchingWildcardPayloadValidationPaths($payload) as $coveredPath) {
                    $coveredPaths[$coveredPath->get()] = true;
                }
            }

            foreach ($concretePaths as $concretePath) {
                if (! isset($coveredPaths[$concretePath->get()])) {
                    continue 2;
                }
            }

            $markers[$candidate] = [];
        }

        $accumulator->rules = [...$markers, ...$accumulator->rules];
    }

    /**
     * Determine if a rule list contains a distinct rule.
     */
    protected function hasDistinctRule(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (is_array($rule) && $this->hasDistinctRule($rule)) {
                return true;
            }

            if (is_string($rule)
                && strtolower(trim(explode(':', $rule, 2)[0])) === 'distinct'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merge rule lists without duplicating identical string rules.
     *
     * @param list<array|object|string> $rules
     * @param list<array|object|string> $additionalRules
     * @return list<array|object|string>
     */
    protected function mergeRules(array $rules, array $additionalRules): array
    {
        foreach ($additionalRules as $rule) {
            if (is_string($rule) && in_array($rule, $rules, true)) {
                continue;
            }

            $rules[] = $rule;
        }

        return $rules;
    }

    /**
     * Determine if a rule list explicitly controls field presence.
     *
     * @param list<array|object|string> $rules
     */
    protected function hasPresenceRule(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($rule instanceof NativeRequiredIf || $rule instanceof NativeRequiredUnless) {
                return true;
            }

            if (! is_string($rule)) {
                continue;
            }

            $name = strtolower(trim(explode(':', $rule, 2)[0]));

            if (in_array($name, self::PRESENCE_RULES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if item rules can differ across one collection.
     *
     * @param class-string<BaseData> $dataClass
     */
    protected function usesDynamicRules(
        string $dataClass,
        ConstructionState $state,
    ): bool {
        return $this->dataClasses->hasDynamicRuleGraph($dataClass)
            || $state->context->beforeRulesHooks !== []
            || $state->context->afterRulesHooks !== []
            || $this->config->ruleInferrers !== [];
    }

    /**
     * Get one unambiguous type validation rule.
     *
     * @return null|'array'|'boolean'|'integer'|'numeric'|'string'|Enum
     */
    protected function primitiveRule(DataProperty $property): string|Enum|null
    {
        $rules = [];

        foreach ($property->type->getNamedTypes() as $type) {
            $rule = $this->typeRuleFor($type);

            if ($rule !== null) {
                $rules[$rule instanceof Enum ? $type->name : $rule] = $rule;
            }
        }

        return count($rules) === 1 ? reset($rules) : null;
    }

    /**
     * Get the type rule for one declared named type.
     *
     * @return null|'array'|'boolean'|'integer'|'numeric'|'string'|Enum
     */
    protected function typeRuleFor(NamedType $type): string|Enum|null
    {
        return match ($type->name) {
            'array', 'iterable' => 'array',
            'bool', 'false', 'true' => 'boolean',
            'float' => 'numeric',
            'int' => 'integer',
            'string' => 'string',
            default => ! $type->builtIn && is_a($type->name, BackedEnum::class, true)
                ? new Enum($type->name)
                : null,
        };
    }

    /**
     * Get the inferred type rule for one property value.
     *
     * A union value uses the rule of the declared type that holds it, so numeric size rules keep
     * their numeric meaning. Other values use the declaration's rule.
     *
     * @return null|'array'|'boolean'|'integer'|'numeric'|'string'|Enum
     */
    protected function inferredTypeRule(
        DataProperty $property,
        bool $hasValue,
        mixed $value,
        ?NamedType $dataType,
    ): string|Enum|null {
        if ($hasValue && $value !== null && ! $value instanceof Optional && $property->type->isUnion) {
            // Fill replaced a data value with its normalized input, so the type it selected decides.
            if ($dataType !== null && $property->type->recordsSelectedType) {
                return 'array';
            }

            foreach ($property->type->getNamedTypes() as $type) {
                if (! $type->kind->isDataRelated() && $type->acceptsValue($value)) {
                    return $this->typeRuleFor($type);
                }
            }
        }

        return $property->type->getDataObjectClass() !== null || $property->type->getDataCollectableType() !== null
            ? 'array'
            : $this->primitiveRule($property);
    }

    /**
     * Get the wire key selected during Fill or its canonical fallback.
     */
    protected function wireKey(
        DataProperty $property,
        ConstructionState $state,
        bool $observed,
    ): string|int {
        if ($observed && $state->hasOriginalKey($property->name)) {
            return $state->originalKey($property->name);
        }

        return $state->context->mapPropertyNames
            ? ($property->inputMappedName ?? $property->name)
            : $property->name;
    }

    /**
     * Get the data object or data collection type that receives a property's value on the current node.
     *
     * A union value uses the type Fill selected from the raw value, because Fill replaced a data value
     * with its normalized input. Without a value, the declaration's one data type applies.
     */
    protected function receivingDataType(
        DataProperty $property,
        ConstructionState $state,
        bool $hasValue,
        mixed $value,
    ): ?NamedType {
        if ($property->type->recordsSelectedType && $hasValue && $value !== null && ! $value instanceof Optional) {
            $type = $state->selectedType($property->name);

            return $type !== null && $type->kind->isDataRelated() ? $type : null;
        }

        return $property->type->getDataObjectType() ?? $property->type->getDataCollectableType();
    }

    /**
     * Record an exact field or opaque subtree excluded from rule compilation.
     */
    protected function recordAuxiliaryPath(
        DataProperty $property,
        ValidationPath $path,
        ValidationAccumulator $accumulator,
    ): void {
        if ($this->canContainDescendants($property)) {
            $accumulator->allowedSubtrees[] = $path->get();
        } else {
            $accumulator->additionalFields[] = $path->get();
        }
    }

    /**
     * Determine if a skipped property can contain nested input.
     */
    protected function canContainDescendants(DataProperty $property): bool
    {
        if ($property->type->isMixed) {
            return true;
        }

        foreach ($property->type->getNamedTypes() as $type) {
            if ($type->kind->isDataRelated()
                || $type->kind->isNonDataIterable()
                || $this->isUnstructuredObject($type)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a validated property has no recursive schema.
     */
    protected function hasUnstructuredDescendants(DataProperty $property): bool
    {
        if ($property->type->isMixed) {
            return true;
        }

        foreach ($property->type->getNamedTypes() as $type) {
            if ($this->isUnstructuredObject($type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a named object type has no Data-owned child schema.
     */
    protected function isUnstructuredObject(NamedType $type): bool
    {
        if ($type->name === 'object') {
            return true;
        }

        if ($type->builtIn || $type->kind->isDataRelated()) {
            return false;
        }

        return ! is_a($type->name, DateTimeInterface::class, true)
            && ! is_a($type->name, UnitEnum::class, true)
            && ! is_a($type->name, Optional::class, true)
            && ! is_a($type->name, Lazy::class, true);
    }
}
