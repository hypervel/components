<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use LogicException;

class DatabaseEloquentCollectionQueueableTest extends TestCase
{
    use VerifiesDoubles;

    public function testSerializesPivotsEntitiesId(): void
    {
        $spy = Double::for(Pivot::class)->strict();
        $spy->expects('getQueueableId')->with(Argument::none())->returns('project_id:1:user_id:2');

        $c = new Collection([$spy]);

        $this->assertSame(['project_id:1:user_id:2'], $c->getQueueableIds());
    }

    public function testSerializesModelEntitiesById(): void
    {
        $spy = Double::for(Model::class)->strict();
        $spy->expects('getQueueableId')->with(Argument::none())->returns(1);

        $c = new Collection([$spy]);

        $this->assertSame([1], $c->getQueueableIds());
    }

    public function testRejectsAKeylessModelInsideAQueueableCollection(): void
    {
        $keyed = (new CollectionQueueableTestModel)->forceFill(['id' => 1]);
        $keyless = new CollectionQueueableTestModel;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Model [Hypervel\Tests\Database\CollectionQueueableTestModel] has no queueable ID.');

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

    public function testJsonSerializationOfCollectionQueueableIdsWorks(): void
    {
        // When the ID of a Model is binary instead of int or string, the Collection
        // serialization + JSON encoding breaks because of UTF-8 issues. Encoding
        // of a QueueableCollection must favor QueueableEntity::queueableId().
        $mock = Double::for(Model::class)->strict();
        $mock->expects('getKey')->never();
        $mock->expects('getQueueableId')->returns('mocked');

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
