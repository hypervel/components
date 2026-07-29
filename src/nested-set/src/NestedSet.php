<?php

declare(strict_types=1);

namespace Hypervel\NestedSet;

use Hypervel\Database\Schema\Blueprint;

class NestedSet
{
    /**
     * The name of default lft column.
     */
    public const LFT = '_lft';

    /**
     * The name of default rgt column.
     */
    public const RGT = '_rgt';

    /**
     * The name of default parent id column.
     */
    public const PARENT_ID = 'parent_id';

    /**
     * The name of default depth column.
     */
    public const DEPTH = 'depth';

    /**
     * Cached node-trait membership by concrete class.
     *
     * @var array<class-string, bool>
     */
    protected static array $nodeClasses = [];

    /**
     * Add bigint nested set columns and indexes to the table.
     */
    public static function columns(Blueprint $table, array $scopes = []): void
    {
        static::addBoundsAndDepth($table);
        $table->unsignedBigInteger(self::PARENT_ID)->nullable();
        static::addIndexes($table, $scopes);
    }

    /**
     * Add integer nested set columns and indexes to the table.
     */
    public static function integerColumns(Blueprint $table, array $scopes = []): void
    {
        static::addBoundsAndDepth($table);
        $table->unsignedInteger(self::PARENT_ID)->nullable();
        static::addIndexes($table, $scopes);
    }

    /**
     * Add UUID nested set columns and indexes to the table.
     */
    public static function uuidColumns(Blueprint $table, array $scopes = []): void
    {
        static::addBoundsAndDepth($table);
        $table->uuid(self::PARENT_ID)->nullable();
        static::addIndexes($table, $scopes);
    }

    /**
     * Add ULID nested set columns and indexes to the table.
     */
    public static function ulidColumns(Blueprint $table, array $scopes = []): void
    {
        static::addBoundsAndDepth($table);
        $table->ulid(self::PARENT_ID)->nullable();
        static::addIndexes($table, $scopes);
    }

    /**
     * Drop nested set columns and indexes.
     */
    public static function dropColumns(Blueprint $table, array $scopes = []): void
    {
        $table->dropIndex([...$scopes, self::RGT]);
        $table->dropIndex([...$scopes, self::LFT]);
        $table->dropIndex([...$scopes, self::PARENT_ID, self::LFT]);
        $table->dropColumn(static::getDefaultColumns());
    }

    /**
     * Get a list of default columns.
     */
    public static function getDefaultColumns(): array
    {
        return [self::LFT, self::RGT, self::PARENT_ID, self::DEPTH];
    }

    /**
     * Determine whether the value uses the node trait.
     */
    public static function isNode(mixed $node): bool
    {
        if (! is_object($node)) {
            return false;
        }

        $class = $node::class;

        return static::$nodeClasses[$class]
            ??= in_array(HasNode::class, class_uses_recursive($class), true);
    }

    /**
     * Add the common structural columns to the table.
     */
    protected static function addBoundsAndDepth(Blueprint $table): void
    {
        $table->unsignedInteger(self::LFT)->default(0);
        $table->unsignedInteger(self::RGT)->default(0);
        $table->unsignedSmallInteger(self::DEPTH)->default(0);
    }

    /**
     * Add nested set indexes to the table.
     */
    protected static function addIndexes(Blueprint $table, array $scopes): void
    {
        $table->index([...$scopes, self::RGT]);
        $table->index([...$scopes, self::LFT]);
        $table->index([...$scopes, self::PARENT_ID, self::LFT]);
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$nodeClasses = [];
    }
}
