<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Queue;

use Hypervel\Contracts\Broadcasting\ShouldBeUnique as ShouldBeUniqueBroadcast;
use Hypervel\Contracts\Broadcasting\ShouldBroadcast;
use Hypervel\Contracts\Broadcasting\ShouldBroadcastNow;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Mail\Mailable;
use Hypervel\Notifications\Notification;
use Hypervel\PHPStan\Rules\LegacyPropertyHelper;
use Hypervel\Queue\Attributes\Backoff;
use Hypervel\Queue\Attributes\Connection;
use Hypervel\Queue\Attributes\CountCrashesAsExceptions;
use Hypervel\Queue\Attributes\DebounceFor;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\Attributes\DeleteWhenMissingModels;
use Hypervel\Queue\Attributes\FailOnTimeout;
use Hypervel\Queue\Attributes\MaxExceptions;
use Hypervel\Queue\Attributes\Queue as QueueAttribute;
use Hypervel\Queue\Attributes\Timeout;
use Hypervel\Queue\Attributes\Tries;
use Hypervel\Queue\Attributes\UniqueFor;
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
use PHPStan\Type\Type;

use function array_all;
use function array_column;
use function array_flip;
use function array_is_list;
use function array_keys;
use function class_basename;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Recommend queue attributes over the configuration properties they replace, which remain supported.
 *
 * A declaration is reported only where the object's dispatch path reads the attribute, and only when removing
 * it, or replacing it with the attribute, leaves the setting the path reads unchanged, given the class's current
 * parents and attributes. Only public properties are read by the queue, so others are not reported.
 *
 * @implements Rule<InClassNode>
 */
#[Conditional(attribute: new AutowiredService, if: '%noLegacyQueueProperties%')]
class NoLegacyQueuePropertiesRule implements Rule
{
    /**
     * The attribute each setting's property maps to, with the parameter holding its value.
     */
    private const array ATTRIBUTES = [
        'connection' => [Connection::class, 'connection'],
        'queue' => [QueueAttribute::class, 'queue'],
        'delay' => [Delay::class, 'delay'],
        'tries' => [Tries::class, 'tries'],
        'timeout' => [Timeout::class, 'timeout'],
        'maxExceptions' => [MaxExceptions::class, 'maxExceptions'],
        'backoff' => [Backoff::class, 'backoff'],
        'uniqueFor' => [UniqueFor::class, 'uniqueFor'],
        'debounceFor' => [DebounceFor::class, 'debounceFor'],
        'failOnTimeout' => [FailOnTimeout::class, null],
        'countCrashesAsExceptions' => [CountCrashesAsExceptions::class, null],
        'deleteWhenMissingModels' => [DeleteWhenMissingModels::class, null],
    ];

    /**
     * The Queueable methods that change each setting when the constructor calls them.
     */
    private const array SETTERS = [
        'connection' => ['onConnection', 'allOnConnection'],
        'queue' => ['onQueue', 'allOnQueue'],
        'delay' => ['delay', 'withoutDelay'],
    ];

    /**
     * Create a new no legacy queue properties rule instance.
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
     * Report the queue configuration properties that attributes can replace or that can be removed.
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();
        $settings = $this->settings($classReflection);

        // Only the class's own and its traits' declarations are reported, so a class declaring none has nothing to track.
        if (array_all(
            array_keys($settings),
            static fn (string $property): bool => ! $classReflection->hasNativeProperty($property)
                || $classReflection->getNativeReflection()->getProperty($property)->getDeclaringClass()->getName() !== $classReflection->getName(),
        )) {
            return [];
        }

        $attributeClasses = array_column(self::ATTRIBUTES, 0);
        $this->helper->trackSources($classReflection, $attributeClasses, null, $scope);

        $declarations = $this->helper->declarations($classReflection, $node->getOriginalNode(), array_keys($settings), self::SETTERS);

        if ($declarations === []) {
            return [];
        }

        [$levels, $unknown] = $this->helper->attributeLevels($classReflection, $attributeClasses, null);
        $errors = [];

        foreach ($declarations as $property => $declaration) {
            [$attributeClass] = self::ATTRIBUTES[$property];
            $inherited = $this->helper->inheritedDefault($classReflection, $property);

            if (
                ! $declaration['public']
                || ! $this->representable($property, $declaration['value'])
                || isset($unknown[$attributeClass])
                || $inherited === null
                // The queue cannot read a protected default, but a mailable's own methods can.
                || (! $inherited['public'] && $inherited['value'] !== null)
            ) {
                continue;
            }

            $propertyFirst = $settings[$property];
            $current = $this->read($property, $levels, $declaration['value'], $classReflection->getName(), $propertyFirst);

            if ($declaration['trait'] === null && $this->helper->same($this->read($property, $levels, $inherited['value'], $inherited['declarer'], $propertyFirst), $current)) {
                $errors[] = $this->error(sprintf(
                    'Queue property %s::$%s should be removed: the same value applies without it.',
                    $classReflection->getDisplayName(),
                    $property,
                ), $classReflection, $declaration, false);

                continue;
            }

            // A trait's attribute serves every class using it, so it keeps no maximum wait the class inherits.
            $arguments = $this->candidate($property, $declaration['value'], $declaration['trait'] === null ? $levels : []);
            $replaced = $this->place($levels, $declaration['trait'], $attributeClass, $arguments);

            if (! $this->helper->same($this->read($property, $replaced, $inherited['value'], $inherited['declarer'], $propertyFirst), $current)) {
                continue;
            }

            $attribute = $this->renderAttribute($attributeClass, $arguments, $declaration['trait'] === null ? $declaration['expression'] : null);
            $message = $declaration['trait'] === null
                ? sprintf('Queue property %s::$%s should be replaced by attribute %s.', $classReflection->getDisplayName(), $property, $attribute)
                : sprintf('Queue property $%s of trait %s should be replaced by attribute %s on the trait.', $property, $declaration['trait']->getDisplayName(), $attribute);

            $errors[] = $this->error($message, $classReflection, $declaration, $property === 'debounceFor');
        }

        return $errors;
    }

    /**
     * Get the settings the class's dispatch path reads through attributes, each with whether the path reads a
     * set property before the attribute. A setting a method computes on that path is left out.
     *
     * @return array<string, bool>
     */
    private function settings(ClassReflection $classReflection): array
    {
        $common = ['connection', 'queue', 'delay', 'tries', 'timeout', 'maxExceptions', 'backoff', 'failOnTimeout', 'countCrashesAsExceptions', 'deleteWhenMissingModels'];
        $broadcast = false;

        if ($classReflection->is(Mailable::class)) {
            // SendQueuedMailable forwards only backoff() to the mailable.
            [$settings, $methods] = [$common, ['backoff' => 'backoff']];
        } elseif ($classReflection->is(Notification::class) && $classReflection->is(ShouldQueue::class)) {
            [$settings, $methods] = [$common, ['backoff' => 'backoff', 'delay' => 'withDelay']];
        } elseif ($classReflection->is(ShouldBroadcast::class) && ! $classReflection->is(ShouldBroadcastNow::class)) {
            $broadcast = true;
            $settings = ['connection', 'queue', 'tries', 'timeout', 'backoff', 'maxExceptions', 'deleteWhenMissingModels'];
            $methods = ['queue' => 'broadcastQueue', 'uniqueFor' => 'uniqueFor'];

            if ($classReflection->is(ShouldBeUniqueBroadcast::class)) {
                $settings[] = 'uniqueFor';
            }
        } elseif ($classReflection->is(ShouldQueue::class)) {
            // A queued class may be a job or a listener, so either path's methods compute the setting.
            $settings = [...$common, 'debounceFor'];
            $methods = ['tries' => 'tries', 'backoff' => 'backoff', 'uniqueFor' => 'uniqueFor', 'connection' => 'viaConnection', 'queue' => 'viaQueue', 'delay' => 'withDelay'];

            if ($classReflection->is(ShouldBeUnique::class)) {
                $settings[] = 'uniqueFor';
            }
        } else {
            return [];
        }

        $read = [];

        foreach ($settings as $setting) {
            if (
                (isset($methods[$setting]) && $classReflection->hasNativeMethod($methods[$setting]))
                // A broadcast's $broadcastQueue takes precedence over its queue.
                || ($broadcast && $setting === 'queue' && $classReflection->hasNativeProperty('broadcastQueue'))
            ) {
                continue;
            }

            $read[$setting] = $broadcast && in_array($setting, ['connection', 'queue'], true);
        }

        return $read;
    }

    /**
     * Determine whether the setting's attribute can hold the declared value.
     */
    private function representable(string $property, mixed $value): bool
    {
        return match ($property) {
            'connection', 'queue' => is_string($value) || $value instanceof Type,
            'backoff' => is_int($value) || (is_array($value) && array_is_list($value) && array_all($value, static fn (mixed $item): bool => is_int($item))),
            'failOnTimeout', 'countCrashesAsExceptions', 'deleteWhenMissingModels' => $value === true,
            default => is_int($value),
        };
    }

    /**
     * Get the arguments of the attribute taking a setting's value. A debounce keeps the maximum wait of the
     * nearest of the given levels' attributes, which the queue reads from the attribute alone.
     *
     * @param list<array{class: string, own: array<class-string, array<string, mixed>>, traits: array<string, array<class-string, array<string, mixed>>>}> $levels
     * @return array<string, mixed>
     */
    private function candidate(string $property, mixed $value, array $levels): array
    {
        [, $parameter] = self::ATTRIBUTES[$property];

        return match ($property) {
            'failOnTimeout', 'countCrashesAsExceptions', 'deleteWhenMissingModels' => [],
            'backoff' => ['backoff' => [$value]],
            'debounceFor' => ['debounceFor' => $value, 'maxWait' => $this->helper->nearestAttribute($levels, DebounceFor::class)[0]['maxWait'] ?? null],
            default => [$parameter => $value],
        };
    }

    /**
     * Place an attribute on the class, or on the given trait it uses, in place of its attribute of the same kind.
     *
     * @param list<array{class: string, own: array<class-string, array<string, mixed>>, traits: array<string, array<class-string, array<string, mixed>>>}> $levels
     * @param class-string $attributeClass
     * @param array<string, mixed> $arguments
     * @return list<array{class: string, own: array<class-string, array<string, mixed>>, traits: array<string, array<class-string, array<string, mixed>>>}>
     */
    private function place(array $levels, ?ClassReflection $trait, string $attributeClass, array $arguments): array
    {
        if ($trait === null) {
            $levels[0]['own'][$attributeClass] = $arguments;
        } else {
            $levels[0]['traits'][$trait->getName()][$attributeClass] = $arguments;
        }

        return $levels;
    }

    /**
     * Read a setting as its dispatch path does, through `ReadsClassAttributes::getAttributeValue()`: a public
     * non-null property declared below the nearest attribute's class wins, otherwise the attribute, else the
     * property. A broadcast's connection and queue read a set property first.
     *
     * Connection and queue names compare as strings, as the attributes store them. A debounce reads the maximum
     * wait from the attribute alone.
     *
     * @param list<array{class: string, own: array<class-string, array<string, mixed>>, traits: array<string, array<class-string, array<string, mixed>>>}> $levels
     */
    private function read(string $property, array $levels, mixed $value, ?string $declarer, bool $propertyFirst): mixed
    {
        [$attributeClass] = self::ATTRIBUTES[$property];
        $nearest = $this->helper->nearestAttribute($levels, $attributeClass);
        $levelIndexes = array_flip(array_column($levels, 'class'));

        if ($nearest === null) {
            $setting = $value;
        } elseif ($value !== null && $declarer !== null && ($propertyFirst || $levelIndexes[$declarer] < $levelIndexes[$nearest[1]])) {
            $setting = $value;
        } else {
            $setting = $this->attributeValue($property, $nearest[0]);
        }

        if ($property === 'debounceFor') {
            return [$setting, $nearest[0]['maxWait'] ?? null];
        }

        return $setting instanceof Type ? $this->enumValue($setting) : $setting;
    }

    /**
     * Get the value `ReadsClassAttributes::extractAttributeValue()` takes from an attribute: its first property.
     *
     * @param array<string, mixed> $arguments
     */
    private function attributeValue(string $property, array $arguments): mixed
    {
        [, $parameter] = self::ATTRIBUTES[$property];

        if ($parameter === null) {
            return true;
        }

        if ($property === 'backoff') {
            $backoff = $arguments['backoff'] ?? [];

            return count($backoff) === 1 ? $backoff[0] : $backoff;
        }

        return $arguments[$parameter] ?? null;
    }

    /**
     * Get the string an enum case names a connection or queue by, as `enum_value()` does.
     */
    private function enumValue(Type $case): string
    {
        $case = $case->getEnumCases()[0];
        $backingValue = $case->getBackingValueType()?->getConstantScalarValues()[0] ?? null;

        return $backingValue === null ? $case->getEnumCaseName() : (string) $backingValue;
    }

    /**
     * Write an attribute with its arguments, the setting's value first, as the declaration writes it when given.
     *
     * @param class-string $attributeClass
     * @param array<string, mixed> $arguments
     */
    private function renderAttribute(string $attributeClass, array $arguments, ?Expr $source): string
    {
        $written = [];

        foreach ($arguments as $parameter => $value) {
            if ($written === []) {
                $written[] = $source !== null
                    ? $this->helper->printExpression($source)
                    : $this->helper->render($attributeClass === Backoff::class ? $value[0] : $value);
            } elseif ($value !== null) {
                $written[] = $parameter . ': ' . $this->helper->render($value);
            }
        }

        return sprintf('#[%s%s]', class_basename($attributeClass), $written === [] ? '' : '(' . implode(', ', $written) . ')');
    }

    /**
     * Build a report at the declaration, with the guidance on subclasses.
     *
     * A trait's declaration is reported in the trait's file, in the context of each class using the trait,
     * so one ignore beside it covers them all.
     *
     * @param array{value: mixed, expression: ?Expr, line: int, trait: ?ClassReflection, public: bool} $declaration
     */
    private function error(string $message, ClassReflection $classReflection, array $declaration, bool $replacesInheritedValues): IdentifierRuleError
    {
        $error = RuleErrorBuilder::message($message)
            ->identifier('hypervel.legacyQueueProperty')
            ->line($declaration['line'])
            ->addTip("Subclasses' own properties and attributes can take precedence differently: review them, and keep the property where attributes cannot express the hierarchy. See https://hypervel.org/docs/static-analysis#queue-configuration");

        if ($replacesInheritedValues) {
            $error->addTip("A subclass's own #[DebounceFor] replaces this one entirely, so it must repeat the maximum wait it inherits.");
        }

        $traitFile = $declaration['trait']?->getFileName();

        if ($traitFile !== null) {
            $error->file($traitFile, sprintf('%s (in context of class %s)', $traitFile, $classReflection->getDisplayName()));
        }

        return $error->build();
    }
}
