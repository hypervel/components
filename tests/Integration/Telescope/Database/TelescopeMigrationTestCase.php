<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Telescope\Database;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Storage\DatabaseEntriesRepository;
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

    public function testEntriesCanBeFoundByUuidOrUuidPrefix(): void
    {
        DB::table('telescope_entries')->insert(array_map(static fn (string $uuid): array => [
            'uuid' => $uuid,
            'batch_id' => '00000000-0000-4000-8000-000000000000',
            'type' => EntryType::REQUEST,
            'content' => '{}',
            'created_at' => CarbonImmutable::now(),
        ], [
            'abcdef01-0000-4000-8000-000000000001',
            'abcdef01-0000-4000-8000-000000000002',
        ]));
        DB::table('telescope_entries_tags')->insert([
            'entry_uuid' => 'abcdef01-0000-4000-8000-000000000002',
            'tag' => 'banana',
        ]);

        $repository = new DatabaseEntriesRepository(config()->string('database.default'));

        $this->assertSame(
            'abcdef01-0000-4000-8000-000000000001',
            $repository->find('abcdef01-0000-4000-8000-000000000001')->id,
        );

        $entry = $repository->find('ABCDEF01')->jsonSerialize();

        $this->assertSame('abcdef01-0000-4000-8000-000000000002', $entry['id']);
        $this->assertSame(['banana'], $entry['tags']);

        $this->expectException(ModelNotFoundException::class);

        $repository->find('nonexistent-uuid');
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
