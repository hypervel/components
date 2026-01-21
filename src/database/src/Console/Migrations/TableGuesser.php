<?php

declare(strict_types=1);

namespace Hypervel\Database\Console\Migrations;

class TableGuesser
{
    public const CREATE_PATTERNS = [
        '/^create_(\w+)_table$/',
        '/^create_(\w+)$/',
    ];

    public const CHANGE_PATTERNS = [
        '/.+_(to|from|in)_(\w+)_table$/',
        '/.+_(to|from|in)_(\w+)$/',
    ];

    /**
     * Attempt to guess the table name and "creation" status of the given migration.
     *
     * @param  string  $migration
     * @return array{string, bool}|null
     */
    public static function guess(string $migration): ?array
    {
        foreach (self::CREATE_PATTERNS as $pattern) {
            if (preg_match($pattern, $migration, $matches)) {
                return [$matches[1], true];
            }
        }

        foreach (self::CHANGE_PATTERNS as $pattern) {
            if (preg_match($pattern, $migration, $matches)) {
                return [$matches[2], false];
            }
        }

        return null;
    }
}
