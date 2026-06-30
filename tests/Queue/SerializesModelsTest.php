<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Contracts\Database\ModelIdentifier;
use Hypervel\Contracts\Queue\QueueableEntity;
use Hypervel\Queue\Attributes\WithoutRelations;
use Hypervel\Queue\SerializesModels;
use Hypervel\Tests\TestCase;

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

class QueueableEntitySerializationFixture implements QueueableEntity
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
