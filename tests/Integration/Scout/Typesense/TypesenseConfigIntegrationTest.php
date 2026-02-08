<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Typesense;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Scout\EngineManager;
use Hypervel\Tests\Scout\Models\ConfigBasedTypesenseModel;
use Throwable;

/**
 * Integration tests for Typesense configuration options.
 *
 * @internal
 * @coversNothing
 */
class TypesenseConfigIntegrationTest extends TypesenseScoutIntegrationTestCase
{
    public function testModelSettingsCollectionSchemaFromConfig(): void
    {
        $modelClass = ConfigBasedTypesenseModel::class;

        // Configure collection schema via config
        $this->app->get(Repository::class)->set("scout.typesense.model-settings.{$modelClass}", [
            'collection-schema' => [
                'fields' => [
                    ['name' => 'id', 'type' => 'string'],
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'body', 'type' => 'string'],
                ],
            ],
            'search-parameters' => [
                'query_by' => 'title,body',
            ],
        ]);

        // Clear cached engines to pick up new config
        $this->app->get(EngineManager::class)->forgetEngines();

        // Create a model - this should use the config-based schema
        $model = ConfigBasedTypesenseModel::create(['title' => 'Test Title', 'body' => 'Test Body']);

        // Index it
        $this->engine->update(new EloquentCollection([$model]));

        // Verify we can search for it (proves schema and search params work)
        $results = ConfigBasedTypesenseModel::search('Test')->get();

        $this->assertCount(1, $results);
        $this->assertSame($model->id, $results->first()->id);
    }

    public function testModelSettingsSearchParametersFromConfig(): void
    {
        $modelClass = ConfigBasedTypesenseModel::class;

        // Configure with specific query_by that only searches title
        $this->app->get(Repository::class)->set("scout.typesense.model-settings.{$modelClass}", [
            'collection-schema' => [
                'fields' => [
                    ['name' => 'id', 'type' => 'string'],
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'body', 'type' => 'string'],
                ],
            ],
            'search-parameters' => [
                'query_by' => 'title', // Only search title, not body
            ],
        ]);

        // Clear cached engines
        $this->app->get(EngineManager::class)->forgetEngines();

        // Create models
        $model1 = ConfigBasedTypesenseModel::create(['title' => 'Unique Word', 'body' => 'Common']);
        $model2 = ConfigBasedTypesenseModel::create(['title' => 'Common', 'body' => 'Unique Word']);

        // Index them
        $this->engine->update(new EloquentCollection([$model1, $model2]));

        // Search for "Unique" - should only find model1 since query_by is just title
        $results = ConfigBasedTypesenseModel::search('Unique')->get();

        $this->assertCount(1, $results);
        $this->assertSame($model1->id, $results->first()->id);
    }

    public function testMaxTotalResultsConfigLimitsPagination(): void
    {
        $modelClass = ConfigBasedTypesenseModel::class;

        // Configure collection schema
        $this->app->get(Repository::class)->set("scout.typesense.model-settings.{$modelClass}", [
            'collection-schema' => [
                'fields' => [
                    ['name' => 'id', 'type' => 'string'],
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'body', 'type' => 'string'],
                ],
            ],
            'search-parameters' => [
                'query_by' => 'title,body',
            ],
        ]);

        // Set max_total_results to a low value
        $this->app->get(Repository::class)->set('scout.typesense.max_total_results', 3);

        // Clear cached engines to pick up new config
        $this->app->get(EngineManager::class)->forgetEngines();
        $this->engine = $this->app->get(EngineManager::class)->engine('typesense');

        // Create 5 models
        for ($i = 1; $i <= 5; ++$i) {
            $model = ConfigBasedTypesenseModel::create(['title' => "Model {$i}", 'body' => 'Content']);
            $this->engine->update(new EloquentCollection([$model]));
        }

        // Search without limit - should be capped at max_total_results
        $results = ConfigBasedTypesenseModel::search('')->get();

        $this->assertLessThanOrEqual(3, $results->count());
    }

    protected function setUpInCoroutine(): void
    {
        parent::setUpInCoroutine();

        // Clean up any existing collection for ConfigBasedTypesenseModel
        $this->cleanupConfigBasedCollection();
    }

    protected function tearDownInCoroutine(): void
    {
        $this->cleanupConfigBasedCollection();

        parent::tearDownInCoroutine();
    }

    public function testImportActionConfigIsUsed(): void
    {
        $modelClass = ConfigBasedTypesenseModel::class;

        // Configure collection schema
        $this->app->get(Repository::class)->set("scout.typesense.model-settings.{$modelClass}", [
            'collection-schema' => [
                'fields' => [
                    ['name' => 'id', 'type' => 'string'],
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'body', 'type' => 'string'],
                ],
            ],
            'search-parameters' => [
                'query_by' => 'title,body',
            ],
        ]);

        // Set import_action to 'upsert' (default) - allows insert and update
        $this->app->get(Repository::class)->set('scout.typesense.import_action', 'upsert');

        // Clear cached engines
        $this->app->get(EngineManager::class)->forgetEngines();

        // Create and index a model
        $model = ConfigBasedTypesenseModel::create(['title' => 'Original Title', 'body' => 'Content']);
        $this->engine->update(new EloquentCollection([$model]));

        // Verify it's indexed
        $results = ConfigBasedTypesenseModel::search('Original')->get();
        $this->assertCount(1, $results);

        // Update the model and re-index (upsert should allow this)
        $model->title = 'Updated Title';
        $model->save();
        $this->engine->update(new EloquentCollection([$model]));

        // Verify the update worked
        $results = ConfigBasedTypesenseModel::search('Updated')->get();
        $this->assertCount(1, $results);
        $this->assertSame('Updated Title', $results->first()->title);
    }

    private function cleanupConfigBasedCollection(): void
    {
        try {
            $collectionName = $this->testPrefix . 'config_based_typesense_models';
            $this->typesense->collections[$collectionName]->delete();
        } catch (Throwable) {
            // Collection doesn't exist, that's fine
        }
    }
}
