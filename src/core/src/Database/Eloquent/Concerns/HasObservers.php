<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Hyperf\Collection\Arr;
use Hyperf\Collection\Collection;
use Hyperf\Database\Model\Model as HyperfModel;
use Hypervel\Context\ApplicationContext;
use Hypervel\Database\Eloquent\Attributes\ObservedBy;
use Hypervel\Database\Eloquent\ObserverManager;
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
     * Collects ObservedBy attributes from the current class and all parent
     * classes (excluding the base Model class), merging them together so
     * that observers declared on parent classes are inherited by children.
     *
     * @return array<int, class-string>
     */
    public static function resolveObserveAttributes(): array
    {
        $reflectionClass = new ReflectionClass(static::class);

        $parentClass = get_parent_class(static::class);
        $hasParentWithTrait = $parentClass
            && $parentClass !== HyperfModel::class
            && method_exists($parentClass, 'resolveObserveAttributes');

        return (new Collection($reflectionClass->getAttributes(ObservedBy::class)))
            ->map(fn ($attribute) => $attribute->getArguments())
            ->flatten()
            ->when($hasParentWithTrait, function (Collection $attributes) use ($parentClass) {
                /** @var class-string $parentClass */
                return (new Collection($parentClass::resolveObserveAttributes()))
                    ->merge($attributes);
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
