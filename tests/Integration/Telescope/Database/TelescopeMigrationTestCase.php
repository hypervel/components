<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Telescope\Database;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

abstract class TelescopeMigrationTestCase extends DatabaseTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');

        $config->set(
            'telescope.storage.database.connection',
            $config->string('database.default'),
        );
    }

    public function testFamilyHashIndexUsesAvailableSparseIndexSupport(): void
    {
        $index = array_find(
            Schema::getIndexes('telescope_entries'),
            static fn (array $index): bool => $index['name'] === 'telescope_entries_family_hash_index',
        );

        $this->assertNotNull($index);
        $this->assertSame(['family_hash'], $index['columns']);
        $this->assertSame(
            in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true),
            $index['partial'],
        );

        $createdAtIndex = array_find(
            Schema::getIndexes('telescope_entries'),
            static fn (array $index): bool => $index['name'] === 'telescope_entries_created_at_index',
        );

        $this->assertNotNull($createdAtIndex);
        $this->assertFalse($createdAtIndex['partial']);
    }

    /**
     * Get the migration options for the shipped Telescope schema.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => false,
            '--realpath' => true,
            '--path' => [__DIR__ . '/../../../../src/telescope/database/migrations'],
        ];
    }
}
