<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Context\ApplicationContext;
use Hypervel\Database\Eloquent\Attributes\ObservedBy;
use Hypervel\Database\Eloquent\ObserverManager;
use ReflectionAttribute;
use ReflectionClass;
use RuntimeException;

trait HasObservers
{
    /**
     * Boot the has observers trait for a model.
     *
     * Automatically registers any observers declared via the ObservedBy attribute.
     */
    public static function bootHasObservers(): void
    {
        $observers = static::resolveObserveAttributes();

        if (! empty($observers)) {
            static::observe($observers);
        }
    }

    /**
     * Resolve the observer class names from the ObservedBy attributes.
     *
     * Collects ObservedBy attributes from parent classes, traits, and the
     * current class itself, merging them together. The order is:
     * parent class observers -> trait observers -> class observers.
     *
     * @return array<int, class-string>
     */
    public static function resolveObserveAttributes(): array
    {
        $reflectionClass = new ReflectionClass(static::class);

        $parentClass = get_parent_class(static::class);
        $hasParentWithTrait = $parentClass
            && $parentClass !== Model::class
            && method_exists($parentClass, 'resolveObserveAttributes');

        // Collect attributes from traits, then from the class itself
        $attributes = new Collection();

        foreach ($reflectionClass->getTraits() as $trait) {
            foreach ($trait->getAttributes(ObservedBy::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $attributes->push($attribute);
            }
        }

        foreach ($reflectionClass->getAttributes(ObservedBy::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $attributes->push($attribute);
        }

        // Process all collected attributes
        $observers = $attributes
            ->map(fn (ReflectionAttribute $attribute) => $attribute->getArguments())
            ->flatten();

        // Prepend parent's observers if applicable
        return $observers
            ->when($hasParentWithTrait, function (Collection $attrs) use ($parentClass) {
                /** @var class-string $parentClass */
                return (new Collection($parentClass::resolveObserveAttributes()))
                    ->merge($attrs);
            })
            ->all();
    }

    /**
     * Register observers with the model.
     *
     * @throws RuntimeException
     */
    public static function observe(array|object|string $classes): void
    {
        $manager = ApplicationContext::getContainer()
            ->get(ObserverManager::class);

        foreach (Arr::wrap($classes) as $class) {
            $manager->register(static::class, $class);
        }
    }
}
