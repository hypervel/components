<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Integration\Typesense;

use Hypervel\Tests\Scout\Models\TypesenseSearchableModel;

/**
 * Integration tests for Scout console commands with Typesense.
 *
 * @group integration
 * @group typesense-integration
 *
 * @internal
 * @coversNothing
 */
class TypesenseCommandsIntegrationTest extends TypesenseScoutIntegrationTestCase
{
    public function testImportCommandIndexesModels(): void
    {
        // Create models in the database
        TypesenseSearchableModel::create(['title' => 'First Document', 'body' => 'Content']);
        TypesenseSearchableModel::create(['title' => 'Second Document', 'body' => 'Content']);
        TypesenseSearchableModel::create(['title' => 'Third Document', 'body' => 'Content']);

        // Run the import command
        $this->artisan('scout:import', ['model' => TypesenseSearchableModel::class])
            ->assertOk();

        // Verify models are searchable
        $results = TypesenseSearchableModel::search('Document')->get();

        $this->assertCount(3, $results);
    }

    public function testFlushCommandRemovesModels(): void
    {
        // Create and index models
        TypesenseSearchableModel::create(['title' => 'First', 'body' => 'Content']);
        TypesenseSearchableModel::create(['title' => 'Second', 'body' => 'Content']);

        $this->artisan('scout:import', ['model' => TypesenseSearchableModel::class])
            ->assertOk();

        // Verify models are indexed
        $results = TypesenseSearchableModel::search('')->get();
        $this->assertCount(2, $results);

        // Run the flush command
        $this->artisan('scout:flush', ['model' => TypesenseSearchableModel::class])
            ->assertOk();

        // Verify models are removed from the index (collection is deleted)
        $results = TypesenseSearchableModel::search('')->get();
        $this->assertCount(0, $results);
    }
}
