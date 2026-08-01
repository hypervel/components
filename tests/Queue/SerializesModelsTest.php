<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Contracts\Database\ModelIdentifier;
use Hypervel\Contracts\Queue\QueueableCollection;
use Hypervel\Contracts\Queue\QueueableEntity;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Queue\Attributes\WithoutRelations;
use Hypervel\Queue\SerializesModels;
use Hypervel\Tests\TestCase;
use LogicException;

class SerializesModelsTest extends TestCase
{
    public function testConcreteClassWithoutRelationsAttributeStripsRelations(): void
    {
        $payload = (new ClassWithoutRelationsSerializationFixture(
            new QueueableEntitySerializationFixture
        ))->__serialize();

        $this->assertInstanceOf(ModelIdentifier::class, $payload['entity']);
        $this->assertSame([], $payload['entity']->relations);
    }

    public function testInheritedClassWithoutRelationsAttributeIsNotAppliedToChild(): void
    {
        $payload = (new ChildClassInheritingWithoutRelationsSerializationFixture(
            new QueueableEntitySerializationFixture
        ))->__serialize();

        $this->assertInstanceOf(ModelIdentifier::class, $payload['entity']);
        $this->assertSame(['roles'], $payload['entity']->relations);
    }

    public function testPropertyWithoutRelationsAttributeStripsRelations(): void
    {
        $payload = (new PropertyWithoutRelationsSerializationFixture(
            new QueueableEntitySerializationFixture,
            new QueueableEntitySerializationFixture,
        ))->__serialize();

        $this->assertInstanceOf(ModelIdentifier::class, $payload['entity']);
        $this->assertInstanceOf(ModelIdentifier::class, $payload['other']);
        $this->assertSame([], $payload['entity']->relations);
        $this->assertSame(['roles'], $payload['other']->relations);
    }

    public function testNonEloquentQueueContractsUseOrdinaryObjectSerialization(): void
    {
        $fixture = new NonEloquentQueueablesSerializationFixture(
            new NonEloquentQueueableEntitySerializationFixture('entity'),
            new NonEloquentQueueableCollectionSerializationFixture(['collection']),
        );

        $payload = $fixture->__serialize();

        $this->assertInstanceOf(NonEloquentQueueableEntitySerializationFixture::class, $payload['entity']);
        $this->assertInstanceOf(NonEloquentQueueableCollectionSerializationFixture::class, $payload['collection']);

        $restored = unserialize(serialize($fixture));

        $this->assertInstanceOf(NonEloquentQueueablesSerializationFixture::class, $restored);
        $this->assertNotSame($fixture->entity, $restored->entity);
        $this->assertNotSame($fixture->collection, $restored->collection);
        $this->assertSame('entity', $restored->entity->value);
        $this->assertSame(['collection'], $restored->collection->items);
    }

    public function testKeylessEloquentModelCannotPublishAQueueIdentifier(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Model [Hypervel\Tests\Queue\KeylessModelSerializationFixture] has no queueable ID.');

        (new EloquentModelSerializationFixture(new KeylessModelSerializationFixture))->__serialize();
    }
}

class EloquentModelSerializationFixture
{
    use SerializesModels;

    public function __construct(public Model $model)
    {
    }
}

class KeylessModelSerializationFixture extends Model
{
}

#[WithoutRelations]
class ClassWithoutRelationsSerializationFixture
{
    use SerializesModels;

    public function __construct(
        public QueueableEntitySerializationFixture $entity,
    ) {
    }
}

#[WithoutRelations]
class ParentClassWithoutRelationsSerializationFixture
{
    use SerializesModels;

    public function __construct(
        public QueueableEntitySerializationFixture $entity,
    ) {
    }
}

class ChildClassInheritingWithoutRelationsSerializationFixture extends ParentClassWithoutRelationsSerializationFixture
{
}

class PropertyWithoutRelationsSerializationFixture
{
    use SerializesModels;

    public function __construct(
        #[WithoutRelations]
        public QueueableEntitySerializationFixture $entity,
        public QueueableEntitySerializationFixture $other,
    ) {
    }
}

class QueueableEntitySerializationFixture extends Model
{
    public function getQueueableId(): int
    {
        return 1;
    }

    public function getQueueableRelations(): array
    {
        return ['roles'];
    }

    public function getQueueableConnection(): ?string
    {
        return 'testing';
    }
}

class NonEloquentQueueablesSerializationFixture
{
    use SerializesModels;

    public function __construct(
        public NonEloquentQueueableEntitySerializationFixture $entity,
        public NonEloquentQueueableCollectionSerializationFixture $collection,
    ) {
    }
}

class NonEloquentQueueableEntitySerializationFixture implements QueueableEntity
{
    public function __construct(
        public string $value,
    ) {
    }

    public function getQueueableId(): string
    {
        return $this->value;
    }

    public function getQueueableRelations(): array
    {
        return [];
    }

    public function getQueueableConnection(): ?string
    {
        return null;
    }
}

class NonEloquentQueueableCollectionSerializationFixture implements QueueableCollection
{
    public function __construct(
        public array $items,
    ) {
    }

    public function getQueueableClass(): ?string
    {
        return NonEloquentQueueableEntitySerializationFixture::class;
    }

    public function getQueueableIds(): array
    {
        return array_keys($this->items);
    }

    public function getQueueableRelations(): array
    {
        return [];
    }

    public function getQueueableConnection(): ?string
    {
        return null;
    }
}
