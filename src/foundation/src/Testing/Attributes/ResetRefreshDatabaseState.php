<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Attributes;

use Attribute;
use Hypervel\Foundation\Testing\Contracts\Attributes\AfterAll;
use Hypervel\Foundation\Testing\Contracts\Attributes\BeforeAll;
use Hypervel\Foundation\Testing\RefreshDatabaseState;

/**
 * Resets the database state before and after all tests in a class.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ResetRefreshDatabaseState implements AfterAll, BeforeAll
{
    /**
     * Handle the attribute before all tests.
     */
    public function beforeAll(): void
    {
        self::run();
    }

    /**
     * Handle the attribute after all tests.
     */
    public function afterAll(): void
    {
        self::run();
    }

    /**
     * Execute the state reset.
     */
    public static function run(): void
    {
        RefreshDatabaseState::$inMemoryConnections = [];
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$lazilyRefreshed = false;
    }
}
