<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

class RewriteCollection
{
    public const int CLASS_LEVEL = 1;

    public const int METHOD_LEVEL = 2;

    public const array CLASS_EXCLUDED_METHODS = ['__construct'];

    /**
     * Which methods can be rewritten.
     */
    protected array $methods = [];

    /**
     * Method wildcard patterns.
     */
    protected array $pattern = [];

    /**
     * Rewrite level.
     */
    protected int $level = self::METHOD_LEVEL;

    public function __construct(protected string $class)
    {
    }

    /**
     * Add methods or method patterns to the rewrite collection.
     */
    public function add(string|array $methods): self
    {
        $methods = (array) $methods;
        foreach ($methods as $method) {
            if (! str_contains($method, '*')) {
                $this->methods[] = $method;
            } else {
                $preg = str_replace(['*', '\\'], ['.*', '\\\\'], $method);
                $this->pattern[] = "/^{$preg}$/";
            }
        }

        return $this;
    }

    /**
     * Determine if a method should be rewritten.
     */
    public function shouldRewrite(string $method): bool
    {
        if ($this->level === self::CLASS_LEVEL && ! in_array($method, self::CLASS_EXCLUDED_METHODS, true)) {
            return true;
        }

        if (in_array($method, $this->methods, true)) {
            return true;
        }

        foreach ($this->pattern as $pattern) {
            if (preg_match($pattern, $method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set the rewrite level.
     */
    public function setLevel(int $level): self
    {
        $this->level = $level;
        return $this;
    }

    /**
     * Get the rewrite level.
     */
    public function getLevel(): int
    {
        return $this->level;
    }

    /**
     * Get the explicit method names to rewrite.
     */
    public function getMethods(): array
    {
        return $this->methods;
    }

    /**
     * Get the class name this collection belongs to.
     */
    public function getClass(): string
    {
        return $this->class;
    }

    /**
     * Get the methods excluded by class-only rules.
     */
    public function getShouldNotRewriteMethods(): array
    {
        return self::CLASS_EXCLUDED_METHODS;
    }
}
