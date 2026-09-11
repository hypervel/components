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

    public function testInheritedClassWithoutRelationsAttributeIsAppliedToChild(): void
    {
        $payload = (new ChildClassInheritingWithoutRelationsSerializationFixture(
            new QueueableEntitySerializationFixture
        ))->__serialize();

        $this->assertInstanceOf(ModelIdentifier::class, $payload['entity']);
        $this->assertSame([], $payload['entity']->relations);
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

    /**
     * Create a fixture containing an Eloquent model.
     */
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

    /**
     * Create a fixture with class-level relation exclusion.
     */
    public function __construct(
        public QueueableEntitySerializationFixture $entity,
    ) {
    }
}

#[WithoutRelations]
class ParentClassWithoutRelationsSerializationFixture
{
    use SerializesModels;

    /**
     * Create a parent fixture with relation exclusion.
     */
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

    /**
     * Create a fixture with property-level relation exclusion.
     */
    public function __construct(
        #[WithoutRelations]
        public QueueableEntitySerializationFixture $entity,
        public QueueableEntitySerializationFixture $other,
    ) {
    }
}

class QueueableEntitySerializationFixture extends Model
{
    /**
     * Get the identifier for the fixture.
     */
    public function getQueueableId(): int
    {
        return 1;
    }

    /**
     * Get the fixture's queueable relationships.
     */
    public function getQueueableRelations(): array
    {
        return ['roles'];
    }

    /**
     * Get the fixture's queueable connection.
     */
    public function getQueueableConnection(): ?string
    {
        return 'testing';
    }
}

class NonEloquentQueueablesSerializationFixture
{
    use SerializesModels;

    /**
     * Create a fixture containing non-Eloquent queueable objects.
     */
    public function __construct(
        public NonEloquentQueueableEntitySerializationFixture $entity,
        public NonEloquentQueueableCollectionSerializationFixture $collection,
    ) {
    }
}

class NonEloquentQueueableEntitySerializationFixture implements QueueableEntity
{
    /**
     * Create a queueable entity with the given value.
     */
    public function __construct(
        public string $value,
    ) {
    }

    /**
     * Get the queueable identifier.
     */
    public function getQueueableId(): string
    {
        return $this->value;
    }

    /**
     * Get the queueable relationships.
     */
    public function getQueueableRelations(): array
    {
        return [];
    }

    /**
     * Get the queueable connection.
     */
    public function getQueueableConnection(): ?string
    {
        return null;
    }
}

class NonEloquentQueueableCollectionSerializationFixture implements QueueableCollection
{
    /**
     * Create a queueable collection with the given items.
     */
    public function __construct(
        public array $items,
    ) {
    }

    /**
     * Get the class of the queueable entities.
     */
    public function getQueueableClass(): ?string
    {
        return NonEloquentQueueableEntitySerializationFixture::class;
    }

    /**
     * Get the queueable identifiers.
     */
    public function getQueueableIds(): array
    {
        return array_keys($this->items);
    }

    /**
     * Get the queueable relationships.
     */
    public function getQueueableRelations(): array
    {
        return [];
    }

    /**
     * Get the queueable connection.
     */
    public function getQueueableConnection(): ?string
    {
        return null;
    }
}
