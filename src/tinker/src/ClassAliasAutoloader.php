<?php

declare(strict_types=1);

namespace Hypervel\Tinker;

use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Psy\Shell;

class ClassAliasAutoloader
{
    /**
     * All of the discovered classes.
     */
    protected array $classes = [];

    /**
     * Path to the vendor directory.
     */
    protected string $vendorPath;

    /**
     * Explicitly included namespaces/classes.
     */
    protected Collection $includedAliases;

    /**
     * Excluded namespaces/classes.
     */
    protected Collection $excludedAliases;

    /**
     * Register a new alias loader instance.
     */
    public static function register(Shell $shell, string $classMapPath, array $includedAliases = [], array $excludedAliases = []): static
    {
        return tap(new static($shell, $classMapPath, $includedAliases, $excludedAliases), function ($loader) {
            spl_autoload_register([$loader, 'aliasClass']);
        });
    }

    /**
     * Create a new alias loader instance.
     */
    public function __construct(
        protected Shell $shell,
        string $classMapPath,
        array $includedAliases = [],
        array $excludedAliases = [],
    ) {
        $this->vendorPath = dirname(dirname($classMapPath));
        $this->includedAliases = collect($includedAliases);
        $this->excludedAliases = collect($excludedAliases);

        $classes = require $classMapPath;

        foreach ($classes as $class => $path) {
            if (! $this->isAliasable($class, $path)) {
                continue;
            }

            $name = class_basename($class);

            if (! isset($this->classes[$name])) {
                $this->classes[$name] = $class;
            }
        }
    }

    /**
     * Find the closest class by name.
     */
    public function aliasClass(string $class): void
    {
        if (Str::contains($class, '\\')) {
            return;
        }

        $fullName = $this->classes[$class] ?? false;

        if ($fullName) {
            $this->shell->writeStdout("[!] Aliasing '{$class}' to '{$fullName}' for this Tinker session.\n");

            class_alias($fullName, $class);
        }
    }

    /**
     * Unregister the alias loader instance.
     */
    public function unregister(): void
    {
        spl_autoload_unregister([$this, 'aliasClass']);
    }

    /**
     * Handle the destruction of the instance.
     */
    public function __destruct()
    {
        $this->unregister();
    }

    /**
     * Determine whether a class may be aliased.
     */
    public function isAliasable(string $class, string $path): bool
    {
        if (! Str::contains($class, '\\')) {
            return false;
        }

        if ($this->includedAliases->contains(function ($alias) use ($class) {
            return Str::startsWith($class, $alias);
        })) {
            return true;
        }

        if (Str::startsWith($path, $this->vendorPath)) {
            return false;
        }

        if ($this->excludedAliases->contains(function ($alias) use ($class) {
            return Str::startsWith($class, $alias);
        })) {
            return false;
        }

        return true;
    }
}
