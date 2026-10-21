<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;
use Hypervel\Data\Lazy;

class PartialClassConditionalData extends Data
{
    public static array $includeDefinitions = [];

    public static array $excludeDefinitions = [];

    public static array $onlyDefinitions = [];

    public static array $exceptDefinitions = [];

    /**
     * Set the class-owned partial definitions.
     */
    public static function setDefinitions(
        array $includeDefinitions = [],
        array $excludeDefinitions = [],
        array $onlyDefinitions = [],
        array $exceptDefinitions = []
    ): void {
        static::$includeDefinitions = $includeDefinitions;
        static::$excludeDefinitions = $excludeDefinitions;
        static::$onlyDefinitions = $onlyDefinitions;
        static::$exceptDefinitions = $exceptDefinitions;
    }

    /**
     * Create the fixture.
     */
    public function __construct(
        public bool $enabled,
        public Lazy|string $string,
        public Lazy|SimpleData $nested,
    ) {
    }

    /**
     * Get the class-owned include definitions.
     */
    protected function includeProperties(): array
    {
        return self::$includeDefinitions;
    }

    /**
     * Get the class-owned exclude definitions.
     */
    protected function excludeProperties(): array
    {
        return self::$excludeDefinitions;
    }

    /**
     * Get the class-owned except definitions.
     */
    protected function exceptProperties(): array
    {
        return self::$exceptDefinitions;
    }

    /**
     * Get the class-owned only definitions.
     */
    protected function onlyProperties(): array
    {
        return self::$onlyDefinitions;
    }

    /**
     * Create the fixture with plain values.
     */
    public static function create(bool $enabled): self
    {
        return new self(
            $enabled,
            'Hello World',
            SimpleData::from('Hello World')
        );
    }

    /**
     * Create the fixture with lazy values.
     */
    public static function createLazy(bool $enabled): self
    {
        return new self(
            $enabled,
            Lazy::create(fn (): string => 'Hello World'),
            Lazy::create(fn (): SimpleData => SimpleData::from('Hello World'))
        );
    }

    /**
     * Create the fixture with lazy values included by default.
     */
    public static function createDefaultIncluded(bool $enabled): self
    {
        return new self(
            $enabled,
            Lazy::create(fn (): string => 'Hello World')->defaultIncluded(),
            Lazy::create(fn (): SimpleData => SimpleData::from('Hello World'))->defaultIncluded()
        );
    }
}
