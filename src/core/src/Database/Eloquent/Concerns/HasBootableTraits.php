<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Hyperf\Database\Model\TraitInitializers;
use Hypervel\Database\Eloquent\Attributes\Boot;
use Hypervel\Database\Eloquent\Attributes\Initialize;
use ReflectionClass;

use function Hyperf\Support\class_uses_recursive;

/**
 * Provides support for Boot and Initialize attributes on trait methods.
 *
 * This trait overrides the default bootTraits() method to also check for
 * #[Boot] and #[Initialize] attributes on methods, allowing trait methods
 * to be named anything instead of requiring the conventional naming.
 */
trait HasBootableTraits
{
    /**
     * Boot all of the bootable traits on the model.
     *
     * This method extends the parent implementation to also support
     * #[Boot] and #[Initialize] attributes on trait methods.
     */
    protected function bootTraits(): void
    {
        $class = static::class;

        $booted = [];
        TraitInitializers::$container[$class] = [];

        $uses = class_uses_recursive($class);

        // Build conventional method names for traits
        $conventionalBootMethods = array_map(
            static fn (string $trait): string => 'boot' . class_basename($trait),
            $uses
        );
        $conventionalInitMethods = array_map(
            static fn (string $trait): string => 'initialize' . class_basename($trait),
            $uses
        );

        // Iterate through all methods looking for boot/initialize methods
        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            $methodName = $method->getName();

            // Handle boot methods (conventional naming OR #[Boot] attribute)
            if (
                ! in_array($methodName, $booted, true)
                && $method->isStatic()
                && (
                    in_array($methodName, $conventionalBootMethods, true)
                    || $method->getAttributes(Boot::class) !== []
                )
            ) {
                $method->invoke(null);
                $booted[] = $methodName;
            }

            // Handle initialize methods (conventional naming OR #[Initialize] attribute)
            if (
                in_array($methodName, $conventionalInitMethods, true)
                || $method->getAttributes(Initialize::class) !== []
            ) {
                TraitInitializers::$container[$class][] = $methodName;
            }
        }

        TraitInitializers::$container[$class] = array_unique(TraitInitializers::$container[$class]);
    }
}
