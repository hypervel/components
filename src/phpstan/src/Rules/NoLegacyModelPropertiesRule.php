<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Database\Eloquent\Attributes\Appends;
use Hypervel\Database\Eloquent\Attributes\Connection;
use Hypervel\Database\Eloquent\Attributes\DateFormat;
use Hypervel\Database\Eloquent\Attributes\Fillable;
use Hypervel\Database\Eloquent\Attributes\Guarded;
use Hypervel\Database\Eloquent\Attributes\Hidden;
use Hypervel\Database\Eloquent\Attributes\Refreshes;
use Hypervel\Database\Eloquent\Attributes\Table;
use Hypervel\Database\Eloquent\Attributes\Touches;
use Hypervel\Database\Eloquent\Attributes\Unguarded;
use Hypervel\Database\Eloquent\Attributes\Visible;
use Hypervel\Database\Eloquent\Attributes\WithoutIncrementing;
use Hypervel\Database\Eloquent\Attributes\WithoutTimestamps;
use Hypervel\Database\Eloquent\Model;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function array_all;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_search;
use function array_unique;
use function array_values;
use function class_basename;
use function implode;
use function in_array;
use function is_array;
use function sprintf;

/**
 * Recommend model configuration attributes over the properties they replace, which remain supported.
 *
 * A declaration is reported only when removing it, or replacing it with the attribute, leaves the value the
 * model initializes the setting to unchanged, given the model's current parents and attributes. No fix is
 * offered: subclasses can rely on the declaration, and a nearer attribute replaces an inherited one entirely.
 *
 * @implements Rule<InClassNode>
 */
#[Conditional(attribute: new AutowiredService, if: '%noLegacyModelProperties%')]
class NoLegacyModelPropertiesRule implements Rule
{
    /**
     * The attributes the model reads for each setting.
     */
    private const array ATTRIBUTES = [
        'table' => [Table::class],
        'connection' => [Connection::class],
        'primaryKey' => [Table::class],
        'keyType' => [Table::class],
        'incrementing' => [WithoutIncrementing::class, Table::class],
        'timestamps' => [WithoutTimestamps::class, Table::class],
        'dateFormat' => [DateFormat::class, Table::class],
        'fillable' => [Fillable::class],
        'guarded' => [Guarded::class, Unguarded::class],
        'hidden' => [Hidden::class],
        'visible' => [Visible::class],
        'appends' => [Appends::class],
        'touches' => [Touches::class],
        'refreshes' => [Refreshes::class],
    ];

    /**
     * The model methods that change each setting when the constructor calls them.
     */
    private const array SETTERS = [
        'table' => ['setTable'],
        'connection' => ['setConnection'],
        'primaryKey' => ['setKeyName'],
        'keyType' => ['setKeyType'],
        'incrementing' => ['setIncrementing'],
        'dateFormat' => ['setDateFormat'],
        'fillable' => ['fillable', 'mergeFillable'],
        'guarded' => ['guard', 'mergeGuarded'],
        'hidden' => ['setHidden', 'mergeHidden', 'makeHidden', 'makeHiddenIf', 'makeVisible', 'makeVisibleIf'],
        'visible' => ['setVisible', 'mergeVisible', 'makeVisible', 'makeVisibleIf'],
        'appends' => ['setAppends', 'mergeAppends', 'append', 'withoutAppends'],
        'touches' => ['setTouchedRelations'],
    ];

    /**
     * The setting each Table argument configures.
     */
    private const array TABLE_SETTINGS = [
        'name' => 'table',
        'key' => 'primaryKey',
        'keyType' => 'keyType',
        'incrementing' => 'incrementing',
        'timestamps' => 'timestamps',
        'dateFormat' => 'dateFormat',
    ];

    /**
     * The attribute listing each list setting's values, with its parameter.
     */
    private const array LISTS = [
        'fillable' => [Fillable::class, 'columns'],
        'hidden' => [Hidden::class, 'columns'],
        'visible' => [Visible::class, 'columns'],
        'appends' => [Appends::class, 'columns'],
        'touches' => [Touches::class, 'relations'],
        'refreshes' => [Refreshes::class, 'columns'],
    ];

    /**
     * Create a new no legacy model properties rule instance.
     */
    public function __construct(
        private LegacyPropertyHelper $helper,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * Report the model's configuration properties that attributes can replace or that can be removed.
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();

        // The base model declares the storage the attributes initialize.
        if (! $classReflection->is(Model::class) || $classReflection->getName() === Model::class) {
            return [];
        }

        // Only the model's own declarations are reported, so a model declaring none has nothing to track.
        if (array_all(
            array_keys(self::ATTRIBUTES),
            static fn (string $property): bool => $classReflection->getNativeReflection()->getProperty($property)->getDeclaringClass()->getName() !== $classReflection->getName(),
        )) {
            return [];
        }

        $attributeClasses = array_values(array_unique(array_merge(...array_values(self::ATTRIBUTES))));
        $this->helper->trackSources($classReflection, $attributeClasses, Model::class, $scope);

        // PHP requires a trait's declaration to match the one the model inherits, so a trait only restates it.
        $declarations = array_filter(
            $this->helper->declarations($classReflection, $node->getOriginalNode(), array_keys(self::ATTRIBUTES), self::SETTERS),
            static fn (array $declaration): bool => $declaration['trait'] === null,
        );

        if ($declarations === []) {
            return [];
        }

        [$levels, $unknown] = $this->helper->attributeLevels($classReflection, $attributeClasses, Model::class);
        $errors = [];
        $tableValues = [];

        foreach ($declarations as $property => $declaration) {
            $inherited = $this->helper->inheritedDefault($classReflection, $property);

            if ($inherited === null || array_intersect_key(array_flip(self::ATTRIBUTES[$property]), $unknown) !== []) {
                continue;
            }

            $current = $this->resolve($property, $levels, $declaration['value'], $classReflection->getName());

            if ($this->helper->same($this->resolve($property, $levels, $inherited['value'], $inherited['declarer']), $current)) {
                $errors[] = $this->error(sprintf(
                    'Model property %s::$%s should be removed: the model resolves the same value without it.',
                    $classReflection->getDisplayName(),
                    $property,
                ), $declaration['line'], null);

                continue;
            }

            if (in_array($property, ['table', 'primaryKey', 'keyType'], true) || ($property === 'incrementing' && $current === true)) {
                $tableValues[$property] = $current;

                continue;
            }

            $candidate = $this->candidate($property, $current);

            if ($candidate === null) {
                continue;
            }

            [$attributeClass, $arguments] = $candidate;
            $replaced = $this->place($levels, [$attributeClass => $arguments]);

            if (! $this->helper->same($this->resolve($property, $replaced, $inherited['value'], $inherited['declarer']), $current)) {
                continue;
            }

            $rendered = sprintf('#[%s%s]', class_basename($attributeClass), $arguments === [] ? '' : '(' . $this->write($current, $declaration) . ')');
            $errors[] = $this->replacementError($classReflection, $property, $declaration['line'], $rendered, isset(self::LISTS[$property]) ? $attributeClass : null);
        }

        return [...$errors, ...$this->tableErrors($classReflection, $declarations, $tableValues, $levels, $unknown)];
    }

    /**
     * Report the declarations one Table attribute can replace together.
     *
     * The class has one Table, which replaces an inherited one entirely, so the candidate keeps the nearest Table's
     * arguments and is checked against every setting Table configures.
     *
     * @param array<string, array{value: mixed, expression: ?Expr, line: int, trait: ?ClassReflection, public: bool}> $declarations
     * @param array<string, mixed> $tableValues the values of the Table-backed declarations, by property
     * @param list<array{class: string, own: array<class-string, array<string, mixed>>, traits: array<string, array<class-string, array<string, mixed>>>}> $levels
     * @param array<class-string, true> $unknown
     * @return list<IdentifierRuleError>
     */
    private function tableErrors(ClassReflection $classReflection, array $declarations, array $tableValues, array $levels, array $unknown): array
    {
        if ($tableValues === []) {
            return [];
        }

        foreach (self::TABLE_SETTINGS as $setting) {
            if (array_intersect_key(array_flip(self::ATTRIBUTES[$setting]), $unknown) !== []) {
                return [];
            }
        }

        $arguments = $this->helper->nearestAttribute($levels, Table::class)[0] ?? [];

        foreach ($tableValues as $property => $value) {
            $arguments[array_search($property, self::TABLE_SETTINGS, true)] = $property === 'primaryKey' && $value === null ? false : $value;
        }

        $replaced = $this->place($levels, [Table::class => $arguments]);

        foreach (self::TABLE_SETTINGS as $setting) {
            $currentDefault = $this->settingDefault($classReflection, $declarations, $setting, false);
            $replacedDefault = $this->settingDefault($classReflection, $declarations, $setting, array_key_exists($setting, $tableValues));

            if (
                $currentDefault === null
                || $replacedDefault === null
                || ! $this->helper->same(
                    $this->resolve($setting, $replaced, $replacedDefault['value'], $replacedDefault['declarer']),
                    $this->resolve($setting, $levels, $currentDefault['value'], $currentDefault['declarer']),
                )
            ) {
                return [];
            }
        }

        $written = [];

        foreach (self::TABLE_SETTINGS as $parameter => $setting) {
            if (($arguments[$parameter] ?? null) === null) {
                continue;
            }

            $value = array_key_exists($setting, $tableValues) ? $this->write($arguments[$parameter], $declarations[$setting]) : $this->helper->render($arguments[$parameter]);
            $written[] = $parameter === 'name' ? $value : $parameter . ': ' . $value;
        }

        $rendered = '#[Table' . ($written === [] ? '' : '(' . implode(', ', $written) . ')') . ']';
        $errors = [];

        foreach (array_keys($tableValues) as $property) {
            $errors[] = $this->replacementError($classReflection, $property, $declarations[$property]['line'], $rendered, Table::class);
        }

        return $errors;
    }

    /**
     * Get the default and declaring class a setting's property has, with or without the class's own declaration.
     * Null when the class declares the property in a way the rule does not read.
     *
     * @param array<string, array{value: mixed, expression: ?Expr, line: int, trait: ?ClassReflection, public: bool}> $declarations
     * @return null|array{value: mixed, declarer: ?string}
     */
    private function settingDefault(ClassReflection $classReflection, array $declarations, string $property, bool $removed): ?array
    {
        if (! $removed && isset($declarations[$property])) {
            return ['value' => $declarations[$property]['value'], 'declarer' => $classReflection->getName()];
        }

        if (! $removed && $classReflection->getNativeReflection()->getProperty($property)->getDeclaringClass()->getName() === $classReflection->getName()) {
            return null;
        }

        return $this->helper->inheritedDefault($classReflection, $property);
    }

    /**
     * Get the attribute that takes a setting's value, with its arguments. Null when no attribute can.
     *
     * @return null|array{class-string, array<string, mixed>}
     */
    private function candidate(string $property, mixed $value): ?array
    {
        if (isset(self::LISTS[$property])) {
            [$attributeClass, $parameter] = self::LISTS[$property];

            return is_array($value) ? [$attributeClass, [$parameter => [$value]]] : null;
        }

        return match ($property) {
            'connection' => $value === null ? null : [Connection::class, ['name' => $value]],
            'incrementing' => $value === false ? [WithoutIncrementing::class, []] : null,
            'timestamps' => $value === false ? [WithoutTimestamps::class, []] : null,
            'dateFormat' => $value === null ? null : [DateFormat::class, ['format' => $value]],
            'guarded' => $value === [] ? [Unguarded::class, []] : (is_array($value) ? [Guarded::class, ['columns' => [$value]]] : null),
            default => null,
        };
    }

    /**
     * Place attributes on the class in place of its own attributes of the same classes. Its other attributes stay,
     * as the suggestion leaves them.
     *
     * @param list<array{class: string, own: array<class-string, array<string, mixed>>, traits: array<string, array<class-string, array<string, mixed>>>}> $levels
     * @param array<class-string, array<string, mixed>> $attributes
     * @return list<array{class: string, own: array<class-string, array<string, mixed>>, traits: array<string, array<class-string, array<string, mixed>>>}>
     */
    private function place(array $levels, array $attributes): array
    {
        $levels[0]['own'] = $attributes + $levels[0]['own'];

        return $levels;
    }

    /**
     * Resolve the value the model initializes a setting to, as `Model::initializeModelAttributes()` and the
     * concerns' initializers do, from the property's default, its declaring class and the class attributes.
     *
     * @param key-of<self::ATTRIBUTES> $property
     * @param list<array{class: string, own: array<class-string, array<string, mixed>>, traits: array<string, array<class-string, array<string, mixed>>>}> $levels
     */
    private function resolve(string $property, array $levels, mixed $default, ?string $declarer): mixed
    {
        $table = $this->helper->nearestAttribute($levels, Table::class)[0] ?? null;
        $listed = isset(self::LISTS[$property]) ? $this->listedValues($levels, $property) : [];

        return match ($property) {
            // The merge*() methods leave the property unchanged for an empty list.
            'fillable', 'hidden', 'visible', 'appends' => $listed === [] ? $default : array_values(array_unique(array_merge($default, $listed))),
            'touches', 'refreshes' => $default === [] ? $listed : $default,
            'table' => ($table['name'] ?? null) !== null && $this->classAttributeOverrides($levels, Table::class, $declarer)
                ? $table['name']
                : ($default ?? $table['name'] ?? null),
            'connection' => $this->classAttributeOverrides($levels, Connection::class, $declarer)
                ? $this->helper->nearestAttribute($levels, Connection::class)[0]['name']
                : ($default ?? $this->helper->nearestAttribute($levels, Connection::class)[0]['name'] ?? null),
            'primaryKey' => $default === 'id' && ($table['key'] ?? null) !== null
                ? ($table['key'] === false ? null : $table['key'])
                : $default,
            'keyType' => $default === 'int' ? ($table['keyType'] ?? $default) : $default,
            'incrementing' => $this->helper->nearestAttribute($levels, WithoutIncrementing::class) !== null
                ? false
                : ($table['incrementing'] ?? $default),
            'timestamps' => $default !== true
                ? $default
                : ($this->helper->nearestAttribute($levels, WithoutTimestamps::class) === null && ($table['timestamps'] ?? true)),
            'dateFormat' => $default ?? $this->helper->nearestAttribute($levels, DateFormat::class)[0]['format'] ?? $table['dateFormat'] ?? null,
            'guarded' => $this->guardConfiguration($levels, $declarer) ?? $default,
        };
    }

    /**
     * Determine whether a class attribute replaces the property, as `Model::classAttributeOverridesProperty()` does
     * for the default value: a class's own attribute below the property's declaring class. Traits are not read.
     *
     * @param list<array{class: string, own: array<class-string, array<string, mixed>>, traits: array<string, array<class-string, array<string, mixed>>>}> $levels
     * @param class-string $attributeClass
     */
    private function classAttributeOverrides(array $levels, string $attributeClass, ?string $declarer): bool
    {
        foreach ($levels as $level) {
            if ($level['class'] === $declarer) {
                return false;
            }

            if (isset($level['own'][$attributeClass])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the guarded columns the attributes configure, as `GuardsAttributes::guardConfiguration()` does: each
     * class's own Guarded or Unguarded, then its traits', stopping at the property's declaring class.
     *
     * @param list<array{class: string, own: array<class-string, array<string, mixed>>, traits: array<string, array<class-string, array<string, mixed>>>}> $levels
     * @return null|array<mixed>
     */
    private function guardConfiguration(array $levels, ?string $declarer): ?array
    {
        foreach ($levels as $level) {
            if ($level['class'] === $declarer) {
                return null;
            }

            if (isset($level['own'][Guarded::class])) {
                return $this->listed($level['own'][Guarded::class]['columns'] ?? []);
            }

            if (isset($level['own'][Unguarded::class])) {
                return [];
            }

            $unguarded = false;

            foreach ($level['traits'] as $attributes) {
                if (isset($attributes[Guarded::class])) {
                    return $this->listed($attributes[Guarded::class]['columns'] ?? []);
                }

                $unguarded = $unguarded || isset($attributes[Unguarded::class]);
            }

            if ($unguarded) {
                return [];
            }
        }

        return null;
    }

    /**
     * Get the values the nearest attribute of a list setting holds.
     *
     * @param list<array{class: string, own: array<class-string, array<string, mixed>>, traits: array<string, array<class-string, array<string, mixed>>>}> $levels
     * @return array<mixed>
     */
    private function listedValues(array $levels, string $property): array
    {
        [$attributeClass, $parameter] = self::LISTS[$property];

        return $this->listed($this->helper->nearestAttribute($levels, $attributeClass)[0][$parameter] ?? []);
    }

    /**
     * Get the values a list attribute's variadic arguments hold: a first array argument, else the arguments.
     *
     * @param list<mixed> $arguments
     * @return array<mixed>
     */
    private function listed(array $arguments): array
    {
        return is_array($arguments[0] ?? null) ? $arguments[0] : $arguments;
    }

    /**
     * Build the report for a declaration an attribute can replace.
     *
     * @param null|class-string $replacingAttribute the attribute a subclass's own one replaces entirely, losing inherited values
     */
    private function replacementError(ClassReflection $classReflection, string $property, int $line, string $attribute, ?string $replacingAttribute): IdentifierRuleError
    {
        return $this->error(
            sprintf('Model property %s::$%s should be replaced by attribute %s.', $classReflection->getDisplayName(), $property, $attribute),
            $line,
            $replacingAttribute,
        );
    }

    /**
     * Write an attribute argument taking a declaration's value: as the declaration writes it when the value is
     * unchanged, since an attribute on the class reads the same names, and otherwise as the value.
     *
     * @param array{value: mixed, expression: ?Expr, line: int, trait: ?ClassReflection, public: bool} $declaration
     */
    private function write(mixed $value, array $declaration): string
    {
        return $declaration['expression'] !== null && $this->helper->same($value, $declaration['value'])
            ? $this->helper->printExpression($declaration['expression'])
            : $this->helper->render($value);
    }

    /**
     * Build a report with the guidance on subclasses.
     *
     * @param null|class-string $replacingAttribute the attribute a subclass's own one replaces entirely, losing inherited values
     */
    private function error(string $message, int $line, ?string $replacingAttribute): IdentifierRuleError
    {
        $error = RuleErrorBuilder::message($message)
            ->identifier('hypervel.legacyModelProperty')
            ->line($line)
            ->addTip("Subclasses' own properties and attributes can take precedence differently: review them, and keep the property where attributes cannot express the hierarchy. See https://hypervel.org/docs/static-analysis#model-configuration");

        if ($replacingAttribute !== null) {
            $error->addTip(sprintf("A subclass's own #[%s] replaces this one entirely, so it must repeat the values it inherits.", class_basename($replacingAttribute)));
        }

        return $error->build();
    }
}
