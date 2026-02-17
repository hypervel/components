<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel;

use Exception;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEloquentCollectionQueueableTest extends TestCase
{
    public function testSerializesPivotsEntitiesId()
    {
        $spy = m::spy(Pivot::class);

        $c = new Collection([$spy]);

        $c->getQueueableIds();

        $spy->shouldHaveReceived()
            ->getQueueableId()
            ->once();
    }

    public function testSerializesModelEntitiesById()
    {
        $spy = m::spy(Model::class);

        $c = new Collection([$spy]);

        $c->getQueueableIds();

        $spy->shouldHaveReceived()
            ->getQueueableId()
            ->once();
    }

    /**
     * @throws Exception
     */
    public function testJsonSerializationOfCollectionQueueableIdsWorks()
    {
        // When the ID of a Model is binary instead of int or string, the Collection
        // serialization + JSON encoding breaks because of UTF-8 issues. Encoding
        // of a QueueableCollection must favor QueueableEntity::queueableId().
        $mock = m::mock(Model::class, [
            'getKey' => random_bytes(10),
            'getQueueableId' => 'mocked',
        ]);

        $c = new Collection([$mock]);

        $payload = [
            'ids' => $c->getQueueableIds(),
        ];

        $this->assertNotFalse(
            json_encode($payload),
            'EloquentCollection is not using the QueueableEntity::getQueueableId() method.'
        );
    }
}
