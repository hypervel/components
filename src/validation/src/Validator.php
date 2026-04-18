<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use BadMethodCallException;
use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Translation\Translator;
use Hypervel\Contracts\Validation\DataAwareRule;
use Hypervel\Contracts\Validation\ImplicitRule;
use Hypervel\Contracts\Validation\Rule;
use Hypervel\Contracts\Validation\Rule as RuleContract;
use Hypervel\Contracts\Validation\Validator as ValidatorContract;
use Hypervel\Contracts\Validation\ValidatorAwareRule;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Fluent;
use Hypervel\Support\MessageBag;
use Hypervel\Support\Str;
use Hypervel\Support\StrCache;
use Hypervel\Support\ValidatedInput;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Throwable;

class Validator implements ValidatorContract
{
    use Concerns\FormatsMessages;
    use Concerns\ValidatesAttributes;
    use PlanExecutor;

    /**
     * The container instance.
     */
    protected ?Container $container = null;

    /**
     * The Presence Verifier implementation.
     */
    protected ?PresenceVerifierInterface $presenceVerifier = null;

    /**
     * The failed validation rules.
     */
    protected array $failedRules = [];

    /**
     * Attributes that should be excluded from the validated data.
     */
    protected array $excludeAttributes = [];

    /**
     * The message bag instance.
     */
    protected ?MessageBag $messages = null;

    /**
     * The data under validation.
     */
    protected array $data = [];

    /**
     * The initial rules provided.
     */
    protected array $initialRules = [];

    /**
     * The rules to be applied to the data.
     */
    protected array $rules = [];

    /**
     * The current rule that is validating.
     */
    protected array|object|string|null $currentRule = null;

    /**
     * The array of wildcard attributes with their asterisks expanded.
     */
    protected array $implicitAttributes = [];

    /**
     * Reverse lookup from concrete expanded attribute to its wildcard pattern.
     *
     * Built lazily by getImplicitAttributeMap(). Invalidated when
     * addRules() or sometimes() modify $implicitAttributes.
     *
     * @var null|array<string, string>
     */
    private ?array $implicitAttributeMap = null;

    /**
     * The callback that should be used to format the attribute.
     */
    protected ?Closure $implicitAttributesFormatter = null;

    /**
     * The cached data for the "distinct" rule.
     */
    protected array $distinctValues = [];

    /**
     * The compiled execution plans for the current passes() invocation.
     *
     * @var array<string, AttributePlan>
     */
    protected array $compiledPlans = [];

    /**
     * The original presence verifier, saved during batched DB checks.
     *
     * Restored after passes() completes so subsequent validate() calls
     * on the same instance aren't polluted by the precomputed verifier.
     */
    protected ?PresenceVerifierInterface $originalPresenceVerifier = null;

    /**
     * Attributes pre-excluded by the exclude_unless/exclude_if pre-pass.
     *
     * Stored separately from the compiled plans so cached plans remain
     * immutable and shareable across requests without cloning.
     *
     * @var array<string, true>
     */
    protected array $preExcludedAttributes = [];

    /**
     * All of the registered "after" callbacks.
     */
    protected array $after = [];

    /**
     * The array of custom error messages.
     */
    public array $customMessages = [];

    /**
     * The array of fallback error messages.
     */
    public array $fallbackMessages = [];

    /**
     * The array of custom attribute names.
     */
    public array $customAttributes = [];

    /**
     * The array of custom displayable values.
     */
    public array $customValues = [];

    /**
     * Indicates if the validator should stop on the first rule failure.
     */
    protected bool $stopOnFirstFailure = false;

    /**
     * Indicates that unvalidated array keys should be excluded, even if the parent array was validated.
     */
    public bool $excludeUnvalidatedArrayKeys = false;

    /**
     * All of the custom validator extensions.
     */
    public array $extensions = [];

    /**
     * All of the custom replacer extensions.
     */
    public array $replacers = [];

    /**
     * The validation rules that may be applied to files.
     *
     * @var string[]
     */
    protected array $fileRules = [
        'Between',
        'Dimensions',
        'Encoding',
        'Extensions',
        'File',
        'Image',
        'Max',
        'Mimes',
        'Mimetypes',
        'Min',
        'Size',
    ];

    /**
     * The validation rules that imply the field is required.
     *
     * @var string[]
     */
    protected array $implicitRules = [
        'Accepted',
        'AcceptedIf',
        'Declined',
        'DeclinedIf',
        'Filled',
        'Missing',
        'MissingIf',
        'MissingUnless',
        'MissingWith',
        'MissingWithAll',
        'Present',
        'PresentIf',
        'PresentUnless',
        'PresentWith',
        'PresentWithAll',
        'Required',
        'RequiredIf',
        'RequiredIfAccepted',
        'RequiredIfDeclined',
        'RequiredUnless',
        'RequiredWith',
        'RequiredWithAll',
        'RequiredWithout',
        'RequiredWithoutAll',
    ];

    /**
     * The validation rules which depend on other fields as parameters.
     *
     * @var string[]
     */
    protected array $dependentRules = [
        'After',
        'AfterOrEqual',
        'Before',
        'BeforeOrEqual',
        'Confirmed',
        'Different',
        'ExcludeIf',
        'ExcludeUnless',
        'ExcludeWith',
        'ExcludeWithout',
        'Gt',
        'Gte',
        'Lt',
        'Lte',
        'AcceptedIf',
        'DeclinedIf',
        'RequiredIf',
        'RequiredIfAccepted',
        'RequiredIfDeclined',
        'RequiredUnless',
        'RequiredWith',
        'RequiredWithAll',
        'RequiredWithout',
        'RequiredWithoutAll',
        'PresentIf',
        'PresentUnless',
        'PresentWith',
        'PresentWithAll',
        'Prohibited',
        'ProhibitedIf',
        'ProhibitedIfAccepted',
        'ProhibitedIfDeclined',
        'ProhibitedUnless',
        'Prohibits',
        'MissingIf',
        'MissingUnless',
        'MissingWith',
        'MissingWithAll',
        'Same',
        'Unique',
    ];

    /**
     * The validation rules that can exclude an attribute.
     *
     * @var string[]
     */
    protected array $excludeRules = ['Exclude', 'ExcludeIf', 'ExcludeUnless', 'ExcludeWith', 'ExcludeWithout'];

    /**
     * The size related validation rules.
     *
     * @var string[]
     */
    protected array $sizeRules = ['Size', 'Between', 'Min', 'Max', 'Gt', 'Lt', 'Gte', 'Lte'];

    /**
     * The numeric related validation rules.
     *
     * @var string[]
     */
    protected array $numericRules = ['Numeric', 'Integer', 'Decimal'];

    /**
     * The default numeric related validation rules.
     *
     * @var string[]
     */
    protected array $defaultNumericRules = ['Numeric', 'Integer', 'Decimal'];

    /**
     * The current random hash for the validator.
     */
    protected static ?string $placeholderHash = null;

    /**
     * The exception to throw upon failure.
     *
     * @var class-string<ValidationException>
     */
    protected $exception = ValidationException::class;

    /**
     * The custom callback to determine if an exponent is within allowed range.
     */
    protected ?Closure $ensureExponentWithinAllowedRangeUsing = null;

    /**
     * Create a new Validator instance.
     *
     * @param Translator $translator the Translator implementation
     */
    public function __construct(
        protected Translator $translator,
        array $data,
        array $rules,
        array $messages = [],
        array $attributes = [],
    ) {
        if (! isset(static::$placeholderHash)) {
            static::$placeholderHash = Str::random();
        }

        $this->initialRules = $rules;
        $this->customMessages = $messages;
        $this->data = $this->parseData($data);
        $this->customAttributes = $attributes;

        $this->setRules($rules);
    }

    /**
     * Parse the data array, converting dots and asterisks.
     */
    public function parseData(array $data): array
    {
        $newData = [];

        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (is_array($value)) {
                $value = $this->parseData($value);
            }

            $key = str_replace(
                ['.', '*'],
                ['__dot__' . static::$placeholderHash, '__asterisk__' . static::$placeholderHash],
                $key
            );

            $newData[$key] = $value;
        }

        return $newData;
    }

    /**
     * Replace the placeholders used in data keys.
     */
    protected function replacePlaceholders(array $data): array
    {
        $originalData = [];

        foreach ($data as $key => $value) {
            $originalData[$this->replacePlaceholderInString((string) $key)] = is_array($value)
                ? $this->replacePlaceholders($value)
                : $value;
        }

        return $originalData;
    }

    /**
     * Replace the placeholders in the given string.
     */
    protected function replacePlaceholderInString(string $value): string
    {
        return str_replace(
            ['__dot__' . static::$placeholderHash, '__asterisk__' . static::$placeholderHash],
            ['.', '*'],
            $value
        );
    }

    /**
     * Replace each field parameter dot placeholder with dot.
     */
    protected function replaceDotPlaceholderInParameters(array $parameters): array
    {
        return array_map(function ($field) {
            return str_replace('__dot__' . static::$placeholderHash, '.', $field);
        }, $parameters);
    }

    /**
     * Add an after validation callback.
     */
    public function after(array|callable|string $callback): static
    {
        if (is_array($callback) && ! is_callable($callback)) {
            foreach ($callback as $rule) {
                /* @phpstan-ignore-next-line */
                $this->after(method_exists($rule, 'after') ? $rule->after(...) : $rule);
            }

            return $this;
        }

        $this->after[] = fn () => $callback($this);

        return $this;
    }

    /**
     * Determine if the data passes the validation rules.
     */
    public function passes(): bool
    {
        $this->messages = new MessageBag;
        [$this->distinctValues, $this->failedRules, $this->excludeAttributes] = [[], [], []];
        $this->originalPresenceVerifier = null;
        $this->preExcludedAttributes = [];

        $this->compiledPlans = $this->compileRules();

        // Exclude pre-evaluation reads $this->data before execution. Only safe
        // when no code path can mutate data during execution (no custom
        // extensions, no ValidatorAwareRule objects, no validator subclasses).
        $canPreEvaluateExcludes = static::class === self::class
            && $this->extensions === []
            && ! $this->compiledPlansContainValidatorAwareRules();

        if ($canPreEvaluateExcludes) {
            $this->preEvaluateExclusions();

            if ($this->preExcludedAttributes !== []) {
                $this->compiledPlans = array_filter(
                    $this->compiledPlans,
                    fn (string $attribute): bool => ! $this->isPreExcludedOrDescendant($attribute),
                    ARRAY_FILTER_USE_KEY,
                );
            }
        }

        // Batching is safe when: base validator class, no custom extensions,
        // and the active verifier is exactly the standard DatabasePresenceVerifier
        // (not a subclass that may override getCount/getMultiCount).
        // Exists/Unique objects are the rules BEING batched, not a mutation threat.
        $activeVerifier = $this->presenceVerifier;
        if (static::class === self::class
            && $this->extensions === []
            && $activeVerifier !== null
            && $activeVerifier::class === DatabasePresenceVerifier::class
        ) {
            $this->maybeBatchDatabaseChecks();
        }

        try {
            $this->executeCompiledPlans($this->compiledPlans);

            foreach ($this->rules as $attribute => $rules) {
                $attribute = (string) $attribute;
                if ($this->isPreExcludedOrDescendant($attribute) || $this->shouldBeExcluded($attribute)) {
                    $this->removeAttribute($attribute);
                }
            }

            foreach ($this->after as $after) {
                $after();
            }

            return $this->messages->isEmpty();
        } finally {
            if ($this->originalPresenceVerifier !== null) {
                $this->presenceVerifier = $this->originalPresenceVerifier;
                $this->originalPresenceVerifier = null;
            }
        }
    }

    /**
     * Compile all attribute rules into AttributePlans.
     *
     * For the base Validator class, rules are compiled with inlining and
     * cached worker-lifetime. Subclasses compile everything as DelegatedCheck
     * to preserve validate*() overrides.
     *
     * @return array<string, AttributePlan>
     */
    protected function compileRules(): array
    {
        $plans = [];
        $isBaseValidator = static::class === self::class;

        foreach ($this->rules as $attribute => $rules) {
            $attribute = (string) $attribute;

            if (! is_array($rules)) {
                $rules = [$rules];
            }

            if ($isBaseValidator) {
                $cached = RulePlanCache::get($rules);
                if ($cached !== null) {
                    $plans[$attribute] = $cached;
                    continue;
                }
            }

            $plan = $isBaseValidator
                ? RuleCompiler::compile($rules)
                : RuleCompiler::compileAllDelegated($rules);

            if ($isBaseValidator) {
                RulePlanCache::put($rules, $plan);
            }

            $plans[$attribute] = $plan;
        }

        return $plans;
    }

    /**
     * Determine if any compiled plan contains a ValidatorAwareRule.
     *
     * ValidatorAwareRule implementations receive the live validator via
     * setValidator($this) and can mutate $this->data via setValue()/setData().
     * This makes pre-evaluation of exclude conditions unsafe because the
     * pre-pass reads data before execution.
     */
    protected function compiledPlansContainValidatorAwareRules(): bool
    {
        foreach ($this->compiledPlans as $plan) {
            foreach ($plan->checks as $check) {
                if ($check instanceof DelegatedCheck && $check->ruleObject instanceof ValidatorAwareRule) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Pre-evaluate exclude_unless / exclude_if conditions before the main loop.
     *
     * Only called when $canPreOptimize is true (base Validator, no extensions,
     * no rule objects). Handles both string form ('exclude_unless:field,value')
     * and array-tuple form. Safety-skips rules requiring parseDependentRuleParameters
     * type conversions so they flow through the normal delegated path.
     */
    protected function preEvaluateExclusions(): void
    {
        /** @var array<string, false|float|int|string> $cache */
        $cache = [];

        foreach ($this->rules as $attribute => $attributeRules) {
            $attribute = (string) $attribute;

            if (! is_array($attributeRules)) {
                continue;
            }

            foreach ($attributeRules as $rule) {
                $parsed = $this->parseExcludeRule($rule);
                if ($parsed === null) {
                    continue;
                }

                [$action, $conditionField, $allowedValues] = $parsed;

                if (str_contains($conditionField, '*')) {
                    $conditionField = $this->resolveWildcardConditionField($attribute, $conditionField);
                    if (str_contains($conditionField, '*')) {
                        continue;
                    }
                }

                if (array_intersect(['true', 'false', 'null'], $allowedValues) !== []) {
                    continue;
                }

                $conditionRules = $this->rules[$conditionField] ?? [];
                if (is_array($conditionRules) && in_array('boolean', $conditionRules, true)) {
                    continue;
                }

                if (! array_key_exists($conditionField, $cache)) {
                    $raw = data_get($this->data, $conditionField);
                    if (is_bool($raw) || $raw === null) {
                        $cache[$conditionField] = false;
                    } elseif (is_string($raw) || is_int($raw) || is_float($raw)) {
                        $cache[$conditionField] = $raw;
                    } else {
                        $cache[$conditionField] = false;
                    }
                }

                $actual = $cache[$conditionField];
                if ($actual === false) {
                    continue;
                }

                $shouldExclude = ($action === 'exclude_unless' && ! in_array($actual, $allowedValues, false))
                    || ($action === 'exclude_if' && in_array($actual, $allowedValues, false));

                if ($shouldExclude) {
                    $this->preExcludedAttributes[$attribute] = true;
                    break;
                }
            }
        }
    }

    /**
     * Parse a single rule into [action, field, allowedValues] if it's an
     * exclude_unless / exclude_if rule.
     *
     * @return null|array{0: string, 1: string, 2: list<string>}
     */
    private function parseExcludeRule(mixed $rule): ?array
    {
        if (is_string($rule)) {
            foreach (['exclude_unless:', 'exclude_if:'] as $prefix) {
                if (str_starts_with($rule, $prefix)) {
                    $action = rtrim($prefix, ':');
                    $args = explode(',', substr($rule, strlen($prefix)));
                    if (count($args) < 2) {
                        return null;
                    }
                    return [$action, $args[0], array_slice($args, 1)];
                }
            }
            return null;
        }

        if (is_array($rule) && count($rule) >= 3 && is_string($rule[0]) && is_string($rule[1])) {
            $action = $rule[0];
            if ($action !== 'exclude_unless' && $action !== 'exclude_if') {
                return null;
            }
            return [$action, $rule[1], array_map(strval(...), array_slice($rule, 2))];
        }

        return null;
    }

    /**
     * Resolve * segments in a condition field reference by aligning with
     * concrete indices in the attribute path.
     *
     * Example: attribute "interactions.5.style.top", condition "interactions.*.type"
     * → "interactions.5.type"
     */
    private function resolveWildcardConditionField(string $attribute, string $conditionField): string
    {
        preg_match_all('/\.(\d+)(?:\.|$)/', $attribute, $matches);
        $indices = $matches[1];
        $i = 0;

        return (string) preg_replace_callback('/\*/', static function () use ($indices, &$i) {
            return $indices[$i++] ?? '*';
        }, $conditionField);
    }

    /**
     * Build a PrecomputedPresenceVerifier for wildcard-expanded exists/unique
     * rules by batching their lookups into single whereIn queries.
     *
     * Scans rules using the validator's own parsing methods (parseTable,
     * getQueryColumn, getExtraConditions, getUniqueIds) so all rule forms
     * (string, array, object) and table specifications (plain, model class,
     * connection-prefixed) are handled correctly with zero duplication.
     */
    protected function maybeBatchDatabaseChecks(): void
    {
        if ($this->implicitAttributes === []) {
            return;
        }

        $wildcardAttributes = array_merge(...array_values($this->implicitAttributes));
        if ($wildcardAttributes === []) {
            return;
        }

        $wildcardAttributeSet = array_flip($wildcardAttributes);

        // Collect batchable groups from wildcard-expanded exists/unique rules.
        // Groups are keyed by full query shape (not just table:column) so that
        // rules with different wheres, ignore values, or types on the same
        // table:column produce separate groups instead of merging silently.
        $groups = [];

        foreach ($this->rules as $attribute => $attributeRules) {
            if (! isset($wildcardAttributeSet[$attribute]) || ! is_array($attributeRules)) {
                continue;
            }

            foreach ($attributeRules as $rule) {
                $meta = $this->extractPresenceRuleMeta($rule, (string) $attribute);

                if ($meta === null) {
                    continue;
                }

                $groupKey = $this->buildPresenceGroupKey($meta);
                $groups[$groupKey] ??= ['meta' => $meta, 'values' => []];

                $value = $this->getValue((string) $attribute);

                if ($value !== null && $value !== '') {
                    $groups[$groupKey]['values'][] = $value;
                }
            }
        }

        if ($groups === []) {
            return;
        }

        // Build the set of table:column pairs represented in batchable groups,
        // for collision checking against non-batchable rules.
        $batchedTableColumns = [];
        foreach ($groups as $group) {
            $batchedTableColumns[$group['meta']['table'] . ':' . $group['meta']['column']] = true;
        }

        // Scan ALL rules for non-batchable exists/unique on the same table:column.
        // These would hit the global verifier at runtime, so precomputing for
        // that table:column would give them wrong results.
        $unsafeTableColumns = $this->collectUnsafeTableColumns($wildcardAttributeSet, $batchedTableColumns);

        $verifier = BatchDatabaseChecker::buildVerifier($groups, $this->presenceVerifier, $unsafeTableColumns);

        if ($verifier === null) {
            return;
        }

        $this->originalPresenceVerifier = $this->presenceVerifier;
        $this->setPresenceVerifier($verifier);
    }

    /**
     * Extract metadata from a presence rule for batching.
     *
     * For object-form rules implementing DatabasePresenceRule, reads metadata
     * directly via presenceMetadata() — no reflection, no stringification.
     * For string-form and array-form rules, uses the validator's own
     * parseTable/getQueryColumn for full parity with the normal validation path.
     *
     * Returns null if the rule is not exists/unique, not batchable (has
     * closure callbacks, field-reference ignore), or can't be parsed.
     *
     * @return null|array{connection: null|string, table: string, column: string, wheres: array<string, mixed>, ignore: mixed, idColumn: string, type: string}
     */
    private function extractPresenceRuleMeta(mixed $rule, string $attribute): ?array
    {
        // Object-form: use the DatabasePresenceRule interface for exact metadata
        if ($rule instanceof Contracts\DatabasePresenceRule) {
            return $this->extractObjectPresenceRuleMeta($rule);
        }

        // String-form or array-form: parse and extract using validator methods
        [$ruleName, $parameters] = ValidationRuleParser::parse($rule);

        if (! is_string($ruleName)) {
            return null;
        }

        $type = match ($ruleName) {
            'Exists' => 'exists',
            'Unique' => 'unique',
            default => null,
        };

        if ($type === null || ! isset($parameters[0])) {
            return null;
        }

        [$connection, $table, $modelIdColumn] = $this->parseTable($parameters[0]);
        $column = $this->getQueryColumn($parameters, $attribute);

        if ($column === '' || $column === false) {
            return null;
        }

        $ignore = null;
        $idColumn = $modelIdColumn ?? 'id';
        $wheres = [];

        if ($type === 'exists') {
            if (isset($parameters[2])) {
                $wheres = $this->getExtraConditions(array_values(array_slice($parameters, 2)));
            }
        } else {
            if (isset($parameters[2])) {
                $rawIgnore = (string) $parameters[2];

                // Field references require per-item runtime resolution — not batchable
                if (str_contains($rawIgnore, '[') || str_contains($rawIgnore, '*')) {
                    return null;
                }

                [$idColumn, $ignore] = $this->getUniqueIds($modelIdColumn, $parameters);
            }
            if (isset($parameters[4])) {
                $wheres = $this->getExtraConditions(array_slice($parameters, 4));
            }
        }

        return [
            'connection' => $connection,
            'table' => $table,
            'column' => (string) $column,
            'wheres' => $wheres,
            'ignore' => $ignore,
            'idColumn' => $idColumn,
            'type' => $type,
        ];
    }

    /**
     * Extract batch metadata from a DatabasePresenceRule object.
     *
     * Uses presenceMetadata() for exact property access (no reflection,
     * no __toString() truthy trap). Resolves the table via parseTable()
     * for model class and connection handling.
     *
     * @return null|array{connection: null|string, table: string, column: string, wheres: array<string, mixed>, ignore: mixed, idColumn: string, type: string}
     */
    private function extractObjectPresenceRuleMeta(Contracts\DatabasePresenceRule $rule): ?array
    {
        $meta = $rule->presenceMetadata();

        // Not batchable if has closure query callbacks
        if ($meta['using'] !== []) {
            return null;
        }

        // Column 'NULL' means infer at validation time — can't batch without knowing
        if ($meta['column'] === 'NULL') {
            return null;
        }

        [$connection, $table] = $this->parseTable($meta['table']);

        $type = $rule instanceof Rules\Unique ? 'unique' : 'exists';

        $ignore = $meta['ignore'] ?? null;
        $idColumn = $meta['idColumn'] ?? 'id';
        $wheres = $meta['wheres'];

        // Normalize wheres from object format to the key => value format
        // used by getExtraConditions / DatabasePresenceVerifier::addConditions
        $normalizedWheres = [];
        foreach ($wheres as $where) {
            if (is_array($where) && isset($where['column'], $where['value'])) { // @phpstan-ignore function.alreadyNarrowedType (runtime guard — native type is array, not typed array shape)
                $normalizedWheres[$where['column']] = $where['value'];
            }
        }

        return [
            'connection' => $connection,
            'table' => $table,
            'column' => $meta['column'],
            'wheres' => $normalizedWheres,
            'ignore' => $ignore,
            'idColumn' => $idColumn,
            'type' => $type,
        ];
    }

    /**
     * Collect table:column pairs that are unsafe for precomputed lookups.
     *
     * Scans non-wildcard rules (all hit the global verifier) and unbatchable
     * wildcard rules (object-form with closures, field-reference ignore, etc.)
     * for exists/unique references on the same table:column pairs as the
     * batchable groups.
     *
     * @param array<int|string, int> $wildcardAttributeSet
     * @param array<string, true> $batchedTableColumns table:column pairs that have batchable groups
     * @return array<string, true>
     */
    private function collectUnsafeTableColumns(array $wildcardAttributeSet, array $batchedTableColumns): array
    {
        $unsafe = [];

        foreach ($this->rules as $attribute => $attributeRules) {
            if (! is_array($attributeRules)) {
                continue;
            }

            $isWildcard = isset($wildcardAttributeSet[$attribute]);

            foreach ($attributeRules as $rule) {
                [$ruleName] = ValidationRuleParser::parse($rule);

                if (! is_string($ruleName) || ! in_array($ruleName, ['Exists', 'Unique'], true)) {
                    continue;
                }

                // For non-wildcard rules, ALL exists/unique hit the global verifier
                if (! $isWildcard) {
                    $tc = $this->extractTableColumnForUnsafeCheck($rule, (string) $attribute);
                    if ($tc !== null && isset($batchedTableColumns[$tc])) {
                        $unsafe[$tc] = true;
                    }
                    continue;
                }

                // For wildcard rules, only NON-batchable ones are unsafe
                $meta = $this->extractPresenceRuleMeta($rule, (string) $attribute);
                if ($meta === null) {
                    // Non-batchable — extract just table:column
                    $tc = $this->extractTableColumnForUnsafeCheck($rule, (string) $attribute);
                    if ($tc !== null && isset($batchedTableColumns[$tc])) {
                        $unsafe[$tc] = true;
                    }
                }
            }
        }

        return $unsafe;
    }

    /**
     * Extract just the table:column pair from a presence rule for collision checking.
     *
     * Simpler than extractPresenceRuleMeta — only needs table and column,
     * doesn't need wheres/ignore/idColumn. Works for all rule forms.
     */
    private function extractTableColumnForUnsafeCheck(mixed $rule, string $attribute): ?string
    {
        if ($rule instanceof Contracts\DatabasePresenceRule) {
            $meta = $rule->presenceMetadata();
            [, $table] = $this->parseTable($meta['table']);
            $column = $meta['column'] !== 'NULL' ? $meta['column'] : $this->guessColumnForQuery($attribute);

            return $table . ':' . $column;
        }

        [, $parameters] = ValidationRuleParser::parse($rule);

        if (! isset($parameters[0])) {
            return null;
        }

        [, $table] = $this->parseTable($parameters[0]);
        $column = $this->getQueryColumn($parameters, $attribute);

        if ($column === '' || $column === false) {
            return null;
        }

        return $table . ':' . $column;
    }

    /**
     * Build a deterministic group key from presence rule metadata.
     *
     * Encodes the full query shape (connection, table, column, type, wheres,
     * ignore, idColumn) so that rules with different query shapes on the same
     * table:column produce separate batch groups instead of merging silently.
     *
     * @param array{connection: null|string, table: string, column: string, wheres: array<string, mixed>, ignore: mixed, idColumn: string, type: string} $meta
     */
    private function buildPresenceGroupKey(array $meta): string
    {
        return $meta['type']
            . ':' . ($meta['connection'] ?? '')
            . ':' . $meta['table']
            . ':' . $meta['column']
            . ':' . $meta['idColumn']
            . ':' . (is_scalar($meta['ignore']) ? (string) $meta['ignore'] : '')
            . ':' . json_encode($meta['wheres'], JSON_THROW_ON_ERROR);
    }

    /**
     * Determine if the data fails the validation rules.
     */
    public function fails(): bool
    {
        return ! $this->passes();
    }

    /**
     * Execute the callback if the data passes the validation rules.
     */
    public function whenPasses(callable $callback, ?callable $default = null): mixed
    {
        if ($this->passes()) {
            return $callback($this) ?? $this;
        }
        if ($default) {
            return $default($this) ?? $this;
        }

        return $this;
    }

    /**
     * Execute the callback if the data fails the validation rules.
     */
    public function whenFails(callable $callback, ?callable $default = null): mixed
    {
        if ($this->fails()) {
            return $callback($this) ?? $this;
        }
        if ($default) {
            return $default($this) ?? $this;
        }

        return $this;
    }

    /**
     * Determine if the attribute should be excluded.
     */
    protected function shouldBeExcluded(string $attribute): bool
    {
        foreach ($this->excludeAttributes as $excludeAttribute) {
            if ($attribute === $excludeAttribute
                || Str::startsWith($attribute, $excludeAttribute . '.')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the attribute or any of its ancestors was pre-excluded.
     *
     * Walks up the dot-separated segments checking each prefix against
     * $preExcludedAttributes. O(depth) where depth is typically 2-4.
     */
    private function isPreExcludedOrDescendant(string $attribute): bool
    {
        if (isset($this->preExcludedAttributes[$attribute])) {
            return true;
        }

        $position = 0;

        while (($position = strpos($attribute, '.', $position)) !== false) {
            if (isset($this->preExcludedAttributes[substr($attribute, 0, $position)])) {
                return true;
            }
            ++$position;
        }

        return false;
    }

    /**
     * Remove the given attribute.
     */
    protected function removeAttribute(string $attribute): void
    {
        Arr::forget($this->data, $attribute);
        Arr::forget($this->rules, $attribute);
    }

    /**
     * Run the validator's rules against its data.
     *
     * @throws ValidationException
     */
    public function validate(): array
    {
        throw_if($this->fails(), $this->exception, $this);

        return $this->validated();
    }

    /**
     * Run the validator's rules against its data.
     *
     * @throws ValidationException
     */
    public function validateWithBag(string $errorBag): array
    {
        try {
            return $this->validate();
        } catch (ValidationException $e) {
            $e->errorBag = $errorBag;

            throw $e;
        }
    }

    /**
     * Get a validated input container for the validated input.
     */
    public function safe(?array $keys = null): array|ValidatedInput
    {
        return is_array($keys)
            ? (new ValidatedInput($this->validated()))->only($keys)
            : new ValidatedInput($this->validated());
    }

    /**
     * Get the attributes and values that were validated.
     *
     * @throws ValidationException
     */
    public function validated(): array
    {
        if (! $this->messages) {
            $this->passes();
        }

        throw_if($this->messages->isNotEmpty(), $this->exception, $this);

        $results = [];

        $missingValue = new stdClass;

        foreach ($this->getRules() as $key => $rules) {
            $value = data_get($this->getData(), $key, $missingValue);

            if (
                $this->excludeUnvalidatedArrayKeys
                && (in_array('array', $rules) || in_array('list', $rules))
                && $value !== null
                && ! empty(preg_grep('/^' . preg_quote($key, '/') . '\.+/', array_keys($this->getRules())))
            ) {
                continue;
            }

            if ($value !== $missingValue) {
                Arr::set($results, $key, $value);
            }
        }

        return $this->replacePlaceholders($results);
    }

    /**
     * Validate a given attribute against a rule.
     */
    protected function validateAttribute(string $attribute, array|object|string $rule): void
    {
        $this->currentRule = $rule;

        [$rule, $parameters] = ValidationRuleParser::parse($rule);

        if ($rule === '') {
            return;
        }

        // First we will get the correct keys for the given attribute in case the field is nested in
        // an array. Then we determine if the given rule accepts other field names as parameters.
        // If so, we will replace any asterisks found in the parameters with the correct keys.
        if ($this->dependsOnOtherFields($rule)) {
            $parameters = $this->replaceDotInParameters($parameters);

            if ($keys = $this->getExplicitKeys($attribute)) {
                $parameters = $this->replaceAsterisksInParameters($parameters, $keys);
            }
        }

        $value = $this->getValue($attribute);

        // If the attribute is a file, we will verify that the file upload was actually successful
        // and if it wasn't we will add a failure for the attribute. Files may not successfully
        // upload if they are too large based on PHP's settings so we will bail in this case.
        if ($value instanceof UploadedFile && ! $value->isValid()
            && $this->hasRule($attribute, array_merge($this->fileRules, $this->implicitRules))
        ) {
            $this->addFailure($attribute, 'uploaded', []);
            return;
        }

        // If we have made it this far we will make sure the attribute is validatable and if it is
        // we will call the validation method with the attribute. If a method returns false the
        // attribute is invalid and we will add a failure message for this failing attribute.
        $validatable = $this->isValidatable($rule, $attribute, $value);

        if ($rule instanceof RuleContract) {
            if ($validatable) {
                $this->validateUsingCustomRule($attribute, $value, $rule);
            }
            return;
        }

        $method = "validate{$rule}";

        $this->numericRules = $this->defaultNumericRules;

        if ($validatable && ! $this->{$method}($attribute, $value, $parameters, $this)) {
            $this->addFailure($attribute, $rule, $parameters);
        }
    }

    /**
     * Determine if the given rule depends on other fields.
     */
    protected function dependsOnOtherFields(object|string $rule): bool
    {
        return in_array($rule, $this->dependentRules);
    }

    /**
     * Get the explicit keys from an attribute flattened with dot notation.
     *
     * E.g. 'foo.1.bar.spark.baz' -> [1, 'spark'] for 'foo.*.bar.*.baz'
     */
    protected function getExplicitKeys(string $attribute): array
    {
        $pattern = str_replace('\*', '([^\.]+)', preg_quote($this->getPrimaryAttribute($attribute), '/'));

        if (preg_match('/^' . $pattern . '/', $attribute, $keys)) {
            array_shift($keys);

            return $keys;
        }

        return [];
    }

    /**
     * Get the primary attribute name.
     *
     * For example, if "name.0" is given, "name.*" will be returned.
     */
    protected function getPrimaryAttribute(string $attribute): string
    {
        return $this->getImplicitAttributeMap()[$attribute] ?? $attribute;
    }

    /**
     * Get the reverse lookup map from concrete attribute to wildcard pattern.
     *
     * @return array<string, string>
     */
    private function getImplicitAttributeMap(): array
    {
        if ($this->implicitAttributeMap === null) {
            $this->implicitAttributeMap = [];

            foreach ($this->implicitAttributes as $pattern => $concreteAttributes) {
                foreach ($concreteAttributes as $concrete) {
                    $this->implicitAttributeMap[$concrete] = $pattern;
                }
            }
        }

        return $this->implicitAttributeMap;
    }

    /**
     * Replace each field parameter which has an escaped dot with the dot placeholder.
     */
    protected function replaceDotInParameters(array $parameters): array
    {
        return array_map(function ($field) {
            return static::encodeAttributeWithPlaceholder((string) ($field ?? ''));
        }, $parameters);
    }

    /**
     * Replace each field parameter which has asterisks with the given keys.
     */
    protected function replaceAsterisksInParameters(array $parameters, array $keys): array
    {
        return array_map(function ($field) use ($keys) {
            return vsprintf(str_replace('*', '%s', $field), $keys);
        }, $parameters);
    }

    /**
     * Determine if the attribute is validatable.
     */
    protected function isValidatable(object|string $rule, string $attribute, mixed $value): bool
    {
        if (in_array($rule, $this->excludeRules)) {
            return true;
        }

        return $this->presentOrRuleIsImplicit($rule, $attribute, $value)
            && $this->passesOptionalCheck($attribute)
            && $this->isNotNullIfMarkedAsNullable($rule, $attribute)
            && $this->hasNotFailedPreviousRuleIfPresenceRule($rule, $attribute);
    }

    /**
     * Determine if the field is present, or the rule implies required.
     */
    protected function presentOrRuleIsImplicit(object|string $rule, string $attribute, mixed $value): bool
    {
        if (is_string($value) && trim($value) === '') {
            return $this->isImplicit($rule);
        }

        if ($value !== null) {
            return true;
        }

        return $this->validatePresent($attribute, $value)
            || $this->isImplicit($rule);
    }

    /**
     * Determine if a given rule implies the attribute is required.
     */
    protected function isImplicit(object|string $rule): bool
    {
        return $rule instanceof ImplicitRule
            || in_array($rule, $this->implicitRules);
    }

    /**
     * Determine if the attribute passes any optional check.
     */
    protected function passesOptionalCheck(string $attribute): bool
    {
        if (isset($this->compiledPlans[$attribute])) {
            return ! $this->compiledPlans[$attribute]->sometimes
                || Arr::has($this->data, $attribute);
        }

        if (! $this->hasRule($attribute, ['Sometimes'])) {
            return true;
        }

        $data = ValidationData::initializeAndGatherData($attribute, $this->data);

        return array_key_exists($attribute, $data)
            || array_key_exists($attribute, $this->data);
    }

    /**
     * Determine if the attribute fails the nullable check.
     */
    protected function isNotNullIfMarkedAsNullable(object|string $rule, string $attribute): bool
    {
        if ($this->isImplicit($rule)) {
            return true;
        }

        if (isset($this->compiledPlans[$attribute])) {
            return ! $this->compiledPlans[$attribute]->nullable
                || ! is_null(Arr::get($this->data, $attribute, 0));
        }

        if (! $this->hasRule($attribute, ['Nullable'])) {
            return true;
        }

        return ! is_null(Arr::get($this->data, $attribute, 0));
    }

    /**
     * Determine if it's a necessary presence validation.
     *
     * This is to avoid possible database type comparison errors.
     */
    protected function hasNotFailedPreviousRuleIfPresenceRule(object|string $rule, string $attribute): bool
    {
        return in_array($rule, ['Unique', 'Exists']) ? ! $this->messages->has($attribute) : true;
    }

    /**
     * Validate an attribute using a custom rule object.
     */
    protected function validateUsingCustomRule(string $attribute, mixed $value, Rule $rule): void
    {
        $originalAttribute = $this->replacePlaceholderInString($attribute);

        $attribute = match (true) {
            $rule instanceof Rules\Email => $attribute,
            $rule instanceof Rules\File => $attribute,
            $rule instanceof Rules\Password => $attribute,
            default => $originalAttribute,
        };

        $value = is_array($value) ? $this->replacePlaceholders($value) : $value;

        if ($rule instanceof ValidatorAwareRule) {
            if ($attribute !== $originalAttribute) {
                $this->addCustomAttributes([
                    $attribute => $this->customAttributes[$originalAttribute] ?? $originalAttribute,
                ]);
            }

            $rule->setValidator($this);
        }

        if ($rule instanceof DataAwareRule) {
            $rule->setData($this->data);
        }

        if (! $rule->passes($attribute, $value)) {
            $ruleClass = $rule instanceof InvokableValidationRule
                ? get_class($rule->invokable())
                : get_class($rule);

            $this->failedRules[$originalAttribute][$ruleClass] = [];

            $messages = $this->getFromLocalArray($originalAttribute, $ruleClass) ?? $rule->message();

            $messages = $messages ? (array) $messages : [$ruleClass];

            foreach ($messages as $key => $message) {
                $key = is_string($key) ? $key : $originalAttribute;

                $this->messages->add($key, $this->makeReplacements(
                    $message,
                    $key,
                    $ruleClass,
                    []
                ));
            }
        }
    }

    /**
     * Check if we should stop further validations on a given attribute.
     */
    protected function shouldStopValidating(string $attribute): bool
    {
        $cleanedAttribute = $this->replacePlaceholderInString($attribute);

        if ($this->hasRule($attribute, ['Bail'])) {
            return $this->messages->has($cleanedAttribute);
        }

        if (
            isset($this->failedRules[$cleanedAttribute])
            && array_key_exists('uploaded', $this->failedRules[$cleanedAttribute])
        ) {
            return true;
        }

        // In case the attribute has any rule that indicates that the field is required
        // and that rule already failed then we should stop validation at this point
        // as now there is no point in calling other rules with this field empty.
        return $this->hasRule($attribute, $this->implicitRules)
            && isset($this->failedRules[$cleanedAttribute])
            && array_intersect(array_keys($this->failedRules[$cleanedAttribute]), $this->implicitRules);
    }

    /**
     * Add a failed rule and error message to the collection.
     */
    public function addFailure(string $attribute, string $rule, array $parameters = []): void
    {
        if (! $this->messages) {
            $this->passes();
        }

        $attributeWithPlaceholders = $attribute;

        $attribute = $this->replacePlaceholderInString($attribute);

        if (in_array($rule, $this->excludeRules)) {
            $this->excludeAttribute($attribute);
            return;
        }

        if ($this->dependsOnOtherFields($rule)) {
            $parameters = $this->replaceDotPlaceholderInParameters($parameters);
        }

        $this->messages->add($attribute, $this->makeReplacements(
            $this->getMessage($attributeWithPlaceholders, $rule),
            $attribute,
            $rule,
            $parameters
        ));

        $this->failedRules[$attribute][$rule] = $parameters;
    }

    /**
     * Add the given attribute to the list of excluded attributes.
     */
    protected function excludeAttribute(string $attribute): void
    {
        $this->excludeAttributes[] = $attribute;

        $this->excludeAttributes = array_unique($this->excludeAttributes);
    }

    /**
     * Returns the data which was valid.
     */
    public function valid(): array
    {
        if (! $this->messages) {
            $this->passes();
        }

        return array_diff_key(
            $this->data,
            $this->attributesThatHaveMessages()
        );
    }

    /**
     * Returns the data which was invalid.
     */
    public function invalid(): array
    {
        if (! $this->messages) {
            $this->passes();
        }

        $invalid = array_intersect_key(
            $this->data,
            $this->attributesThatHaveMessages()
        );

        $result = [];

        $failed = Arr::only(Arr::dot($invalid), array_keys($this->failed()));

        foreach ($failed as $key => $failure) {
            Arr::set($result, $key, $failure);
        }

        return $result;
    }

    /**
     * Generate an array of all attributes that have messages.
     */
    protected function attributesThatHaveMessages(): array
    {
        return (new Collection($this->messages()->toArray()))
            ->map(fn ($message, $key) => explode('.', $key)[0])
            ->unique()
            ->flip()
            ->all();
    }

    /**
     * Get the failed validation rules.
     */
    public function failed(): array
    {
        return $this->failedRules;
    }

    /**
     * Get the message container for the validator.
     */
    public function messages(): MessageBag
    {
        if (! $this->messages) {
            $this->passes();
        }

        return $this->messages;
    }

    /**
     * An alternative more semantic shortcut to the message container.
     */
    public function errors(): MessageBag
    {
        return $this->messages();
    }

    /**
     * Get the messages for the instance.
     */
    public function getMessageBag(): MessageBag
    {
        return $this->messages();
    }

    /**
     * Determine if the given attribute has a rule in the given set.
     */
    public function hasRule(string $attribute, array|string $rules): bool
    {
        return ! is_null($this->getRule($attribute, $rules));
    }

    /**
     * Get a rule and its parameters for a given attribute.
     */
    protected function getRule(string $attribute, array|string $rules): ?array
    {
        if (! array_key_exists($attribute, $this->rules)) {
            return null;
        }

        $rules = (array) $rules;

        foreach ($this->rules[$attribute] as $rule) {
            [$rule, $parameters] = ValidationRuleParser::parse($rule);

            if (in_array($rule, $rules)) {
                return [$rule, $parameters];
            }
        }

        return null;
    }

    /**
     * Get the data under validation.
     */
    public function attributes(): array
    {
        return $this->getData();
    }

    /**
     * Get the data under validation.
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Set the data under validation.
     */
    public function setData(array $data): static
    {
        $this->data = $this->parseData($data);

        $this->setRules($this->initialRules);

        return $this;
    }

    /**
     * Get the value of a given attribute.
     */
    public function getValue(string $attribute): mixed
    {
        return Arr::get($this->data, $attribute);
    }

    /**
     * Set the value of a given attribute.
     */
    public function setValue(string $attribute, mixed $value): void
    {
        Arr::set($this->data, $attribute, $value);
    }

    /**
     * Get the validation rules.
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Get the validation rules with key placeholders removed.
     */
    public function getRulesWithoutPlaceholders(): array
    {
        return (new Collection($this->rules))
            ->mapWithKeys(fn ($value, $key) => [
                static::decodeAttributeWithPlaceholder((string) $key) => $value,
            ])->all();
    }

    /**
     * Set the validation rules.
     */
    public function setRules(array $rules): static
    {
        $rules = (new Collection($rules))
            ->mapWithKeys(function ($value, $key) {
                return [static::encodeAttributeWithPlaceholder((string) $key) => $value];
            })->toArray();

        $this->initialRules = $rules;

        $this->rules = [];

        $this->addRules($rules);

        return $this;
    }

    /**
     * Append new validation rules to the validator.
     */
    public function appendRules(array $rules): static
    {
        $rules = (new Collection($rules))
            ->map(fn ($value) => is_string($value) ? explode('|', $value) : $value)
            ->all();

        return $this->setRules(array_merge_recursive($this->getRulesWithoutPlaceholders(), $rules));
    }

    /**
     * Parse the given rules and merge them into current rules.
     */
    public function addRules(array $rules): void
    {
        // The primary purpose of this parser is to expand any "*" rules to the all
        // of the explicit rules needed for the given data. For example the rule
        // names.* would get expanded to names.0, names.1, etc. for this data.
        $response = (new ValidationRuleParser($this->data))
            ->explode(ValidationRuleParser::filterConditionalRules($rules, $this->data));

        foreach ($response->rules as $key => $rule) {
            $this->rules[$key] = array_merge($this->rules[$key] ?? [], $rule);
        }

        $this->implicitAttributes = array_merge(
            $this->implicitAttributes,
            $response->implicitAttributes
        );

        $this->implicitAttributeMap = null;
    }

    /**
     * Add conditions to a given field based on a Closure.
     */
    public function sometimes(array|string $attribute, array|string $rules, callable $callback): static
    {
        $payload = new Fluent($this->data);

        foreach ((array) $attribute as $key) {
            $response = (new ValidationRuleParser($this->data))->explode([$key => $rules]);

            $this->implicitAttributes = array_merge($response->implicitAttributes, $this->implicitAttributes);

            foreach ($response->rules as $ruleKey => $ruleValue) {
                if ($callback($payload, $this->dataForSometimesIteration($ruleKey, ! str_ends_with($key, '.*')))) {
                    $this->addRules([static::encodeAttributeWithPlaceholder($ruleKey) => $ruleValue]);
                }
            }
        }

        $this->implicitAttributeMap = null;

        return $this;
    }

    /**
     * Get the data that should be injected into the iteration of a wildcard "sometimes" callback.
     * @return array|Fluent|mixed
     */
    private function dataForSometimesIteration(string $attribute, bool $removeLastSegmentOfAttribute): mixed
    {
        $lastSegmentOfAttribute = strrchr($attribute, '.');

        $attribute = $lastSegmentOfAttribute && $removeLastSegmentOfAttribute
            ? Str::replaceLast($lastSegmentOfAttribute, '', $attribute)
            : $attribute;

        return is_array($data = data_get($this->data, $attribute))
            ? new Fluent($data)
            : $data;
    }

    /**
     * Instruct the validator to stop validating after the first rule failure.
     */
    public function stopOnFirstFailure(bool $stopOnFirstFailure = true): static
    {
        $this->stopOnFirstFailure = $stopOnFirstFailure;

        return $this;
    }

    /**
     * Register an array of custom validator extensions.
     */
    public function addExtensions(array $extensions): void
    {
        if ($extensions) {
            $keys = array_map(StrCache::snake(...), array_keys($extensions));

            $extensions = array_combine($keys, array_values($extensions));
        }

        $this->extensions = array_merge($this->extensions, $extensions);
    }

    /**
     * Register an array of custom implicit validator extensions.
     */
    public function addImplicitExtensions(array $extensions): void
    {
        $this->addExtensions($extensions);

        foreach ($extensions as $rule => $extension) {
            $this->implicitRules[] = StrCache::studly($rule);
        }
    }

    /**
     * Register an array of custom dependent validator extensions.
     */
    public function addDependentExtensions(array $extensions): void
    {
        $this->addExtensions($extensions);

        foreach ($extensions as $rule => $extension) {
            $this->dependentRules[] = StrCache::studly($rule);
        }
    }

    /**
     * Register a custom validator extension.
     */
    public function addExtension(string $rule, Closure|string $extension): void
    {
        $this->extensions[StrCache::snake($rule)] = $extension;
    }

    /**
     * Register a custom implicit validator extension.
     */
    public function addImplicitExtension(string $rule, Closure|string $extension): void
    {
        $this->addExtension($rule, $extension);

        $this->implicitRules[] = StrCache::studly($rule);
    }

    /**
     * Register a custom dependent validator extension.
     */
    public function addDependentExtension(string $rule, Closure|string $extension): void
    {
        $this->addExtension($rule, $extension);

        $this->dependentRules[] = StrCache::studly($rule);
    }

    /**
     * Register an array of custom validator message replacers.
     */
    public function addReplacers(array $replacers): void
    {
        if ($replacers) {
            $keys = array_map(StrCache::snake(...), array_keys($replacers));

            $replacers = array_combine($keys, array_values($replacers));
        }

        $this->replacers = array_merge($this->replacers, $replacers);
    }

    /**
     * Register a custom validator message replacer.
     */
    public function addReplacer(string $rule, Closure|string $replacer): void
    {
        $this->replacers[StrCache::snake($rule)] = $replacer;
    }

    /**
     * Set the custom messages for the validator.
     */
    public function setCustomMessages(array $messages): static
    {
        $this->customMessages = array_merge($this->customMessages, $messages);

        return $this;
    }

    /**
     * Set the custom attributes on the validator.
     */
    public function setAttributeNames(array $attributes): static
    {
        $this->customAttributes = $attributes;

        return $this;
    }

    /**
     * Add custom attributes to the validator.
     */
    public function addCustomAttributes(array $attributes): static
    {
        $this->customAttributes = array_merge($this->customAttributes, $attributes);

        return $this;
    }

    /**
     * Set the callback that used to format an implicit attribute.
     */
    public function setImplicitAttributesFormatter(?callable $formatter = null): static
    {
        $this->implicitAttributesFormatter = $formatter;

        return $this;
    }

    /**
     * Set the custom values on the validator.
     */
    public function setValueNames(array $values): static
    {
        $this->customValues = $values;

        return $this;
    }

    /**
     * Add the custom values for the validator.
     */
    public function addCustomValues(array $customValues): static
    {
        $this->customValues = array_merge($this->customValues, $customValues);

        return $this;
    }

    /**
     * Set the fallback messages for the validator.
     */
    public function setFallbackMessages(array $messages): void
    {
        $this->fallbackMessages = $messages;
    }

    /**
     * Get the Presence Verifier implementation.
     *
     * @throws RuntimeException
     */
    public function getPresenceVerifier(?string $connection = null): PresenceVerifierInterface
    {
        if (! isset($this->presenceVerifier)) {
            throw new RuntimeException('Presence verifier has not been set.');
        }

        if ($this->presenceVerifier instanceof DatabasePresenceVerifierInterface) {
            $this->presenceVerifier->setConnection($connection);
        }

        return $this->presenceVerifier;
    }

    /**
     * Set the Presence Verifier implementation.
     */
    public function setPresenceVerifier(PresenceVerifierInterface $presenceVerifier): void
    {
        $this->presenceVerifier = $presenceVerifier;
    }

    /**
     * Get the exception to throw upon failed validation.
     *
     * @return class-string<ValidationException>
     */
    public function getException(): string
    {
        return $this->exception;
    }

    /**
     * Set the exception to throw upon failed validation.
     *
     * @param class-string<ValidationException>|Throwable $exception
     *
     * @throws InvalidArgumentException
     */
    public function setException(string|Throwable $exception): static
    {
        if (! is_a($exception, ValidationException::class, true)) {
            throw new InvalidArgumentException(
                sprintf('Exception [%s] is invalid. It must extend [%s].', $exception, ValidationException::class)
            );
        }

        $this->exception = $exception;

        return $this;
    }

    /**
     * Ensure exponents are within range using the given callback.
     */
    public function ensureExponentWithinAllowedRangeUsing(Closure $callback): static
    {
        $this->ensureExponentWithinAllowedRangeUsing = $callback;

        return $this;
    }

    /**
     * Get the Translator implementation.
     */
    public function getTranslator(): Translator
    {
        return $this->translator;
    }

    /**
     * Set the Translator implementation.
     */
    public function setTranslator(Translator $translator): void
    {
        $this->translator = $translator;
    }

    /**
     * Set the IoC container instance.
     */
    public function setContainer(Container $container): void
    {
        $this->container = $container;
    }

    /**
     * Call a custom validator extension.
     */
    protected function callExtension(string $rule, array $parameters): ?bool
    {
        $callback = $this->extensions[$rule];

        if (is_callable($callback)) {
            return $callback(...array_values($parameters));
        }
        if (is_string($callback)) {
            return $this->callClassBasedExtension($callback, $parameters);
        }

        return null;
    }

    /**
     * Call a class based validator extension.
     */
    protected function callClassBasedExtension(string $callback, array $parameters): bool
    {
        [$class, $method] = Str::parseCallback($callback, 'validate');

        /* @phpstan-ignore-next-line */
        return $this->container->make($class)
            ->{$method}(...array_values($parameters));
    }

    /**
     * Encode the attribute with the placeholder hash.
     */
    protected static function encodeAttributeWithPlaceholder(string $attribute): string
    {
        return str_replace('\.', '__dot__' . static::$placeholderHash, $attribute);
    }

    /**
     * Decode an attribute with a placeholder hash.
     */
    protected static function decodeAttributeWithPlaceholder(string $attribute): string
    {
        return str_replace('__dot__' . static::$placeholderHash, '\.', $attribute);
    }

    /**
     * Flush the validator's global state.
     */
    public static function flushState(): void
    {
        static::$placeholderHash = null;
    }

    /**
     * Handle dynamic calls to class methods.
     *
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $parameters): mixed
    {
        $rule = StrCache::snake(substr($method, 8));

        if (isset($this->extensions[$rule])) {
            return $this->callExtension($rule, $parameters);
        }

        throw new BadMethodCallException(sprintf(
            'Method %s::%s does not exist.',
            static::class,
            $method
        ));
    }
}
