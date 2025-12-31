<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Jobs;

use Hypervel\Scout\Jobs\RemoveableScoutCollection;
use Hypervel\Tests\Scout\Models\CustomScoutKeyModel;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Hypervel\Tests\TestCase;

/**
 * Tests for RemoveableScoutCollection.
 *
 * @internal
 * @coversNothing
 */
class RemoveableScoutCollectionTest extends TestCase
{
    public function testGetQueueableIdsReturnsScoutKeys(): void
    {
        $model1 = new SearchableModel(['title' => 'First', 'body' => 'Content']);
        $model1->id = 1;

        $model2 = new SearchableModel(['title' => 'Second', 'body' => 'Content']);
        $model2->id = 2;

        $collection = RemoveableScoutCollection::make([$model1, $model2]);

        $this->assertEquals([1, 2], $collection->getQueueableIds());
    }

    public function testGetQueueableIdsResolvesCustomScoutKeys(): void
    {
        $model1 = new CustomScoutKeyModel(['title' => 'First', 'body' => 'Content']);
        $model1->id = 1;

        $model2 = new CustomScoutKeyModel(['title' => 'Second', 'body' => 'Content']);
        $model2->id = 2;

        $model3 = new CustomScoutKeyModel(['title' => 'Third', 'body' => 'Content']);
        $model3->id = 3;

        $collection = RemoveableScoutCollection::make([$model1, $model2, $model3]);

        $this->assertEquals([
            'custom-key.1',
            'custom-key.2',
            'custom-key.3',
        ], $collection->getQueueableIds());
    }

    public function testGetQueueableIdsReturnsEmptyArrayForEmptyCollection(): void
    {
        $collection = RemoveableScoutCollection::make([]);

        $this->assertEquals([], $collection->getQueueableIds());
    }

    public function testGetQueueableIdsWithMixedModels(): void
    {
        // Mix of standard and custom Scout key models
        $standard1 = new SearchableModel(['title' => 'Standard', 'body' => 'Content']);
        $standard1->id = 100;

        $custom1 = new CustomScoutKeyModel(['title' => 'Custom', 'body' => 'Content']);
        $custom1->id = 200;

        // When collection has mixed models, the first model's behavior determines the path
        // Standard model first - uses standard Scout keys
        $collection1 = RemoveableScoutCollection::make([$standard1, $custom1]);
        $ids1 = $collection1->getQueueableIds();

        // Both models use Searchable trait, so getScoutKey() is called on each
        $this->assertEquals([100, 'custom-key.200'], $ids1);
    }
}
