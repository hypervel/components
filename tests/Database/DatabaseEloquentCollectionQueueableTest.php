<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Exception;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;

class DatabaseEloquentCollectionQueueableTest extends TestCase
{
    public function testSerializesPivotsEntitiesId(): void
    {
        $spy = m::spy(Pivot::class);
        $spy->shouldReceive('getQueueableId')->once()->andReturn('project_id:1:user_id:2');

        $c = new Collection([$spy]);

        $this->assertSame(['project_id:1:user_id:2'], $c->getQueueableIds());
    }

    public function testSerializesModelEntitiesById(): void
    {
        $spy = m::spy(Model::class);
        $spy->shouldReceive('getQueueableId')->once()->andReturn(1);

        $c = new Collection([$spy]);

        $this->assertSame([1], $c->getQueueableIds());
    }

    public function testRejectsAKeylessModelInsideAQueueableCollection(): void
    {
        $keyed = (new CollectionQueueableTestModel)->forceFill(['id' => 1]);
        $keyless = new CollectionQueueableTestModel;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Model [Hypervel\Tests\Database\CollectionQueueableTestModel] has no queueable ID.');

        (new Collection([$keyed, $keyless]))->getQueueableIds();
    }

    public function testReturnsCompleteAndCompoundQueueableIdsInCollectionOrder(): void
    {
        $pivot = Pivot::fromRawAttributes(
            new CollectionQueueableTestModel,
            ['project_id' => 1, 'user_id' => 2],
            'collaborators',
            true
        )->setPivotKeys('project_id', 'user_id');

        $this->assertSame(
            [3, 'project_id:1:user_id:2'],
            (new Collection([(new CollectionQueueableTestModel)->forceFill(['id' => 3]), $pivot]))->getQueueableIds()
        );
    }

    /**
     * @throws Exception
     */
    public function testJsonSerializationOfCollectionQueueableIdsWorks(): void
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

class CollectionQueueableTestModel extends Model
{
}
