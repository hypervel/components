<?php

declare(strict_types=1);

namespace Hypervel\Tests\Contracts\Database;

use Hypervel\Contracts\Database\ModelIdentifier;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Tests\TestCase;
use stdClass;

class ModelIdentifierTest extends TestCase
{
    public function testFlushStateRestoresRawClassSerialization(): void
    {
        Relation::morphMap([
            'model-identifier-user' => ModelIdentifierTestUser::class,
        ]);
        ModelIdentifier::useMorphMap();

        $this->assertSame(
            'model-identifier-user',
            (new ModelIdentifier(ModelIdentifierTestUser::class, 1, []))->class
        );

        ModelIdentifier::flushState();

        $this->assertSame(
            ModelIdentifierTestUser::class,
            (new ModelIdentifier(ModelIdentifierTestUser::class, 1, []))->class
        );
    }

    public function testClassNamesAreNotRemappedUnlessMorphMapsAreEnabled(): void
    {
        Relation::morphMap([ModelIdentifierTestUser::class => stdClass::class]);

        $identifier = new ModelIdentifier(ModelIdentifierTestUser::class, 1, []);

        $this->assertSame(ModelIdentifierTestUser::class, $identifier->getClass());

        ModelIdentifier::useMorphMap();

        $this->assertSame(stdClass::class, $identifier->getClass());
    }

    public function testIntegerMorphAliasesKeepTheirSerializedType(): void
    {
        Relation::morphMap([1 => ModelIdentifierTestUser::class]);
        ModelIdentifier::useMorphMap();

        $identifier = new ModelIdentifier(ModelIdentifierTestUser::class, 1, []);
        $serialized = sprintf(
            'O:%d:"%s":5:{s:5:"class";i:1;s:2:"id";i:1;s:9:"relations";a:0:{}s:10:"connection";N;s:15:"collectionClass";N;}',
            strlen(ModelIdentifier::class),
            ModelIdentifier::class,
        );

        $this->assertSame($serialized, serialize($identifier));

        $restored = unserialize($serialized);

        $this->assertInstanceOf(ModelIdentifier::class, $restored);
        $this->assertSame(1, $restored->class);
        $this->assertSame(ModelIdentifierTestUser::class, $restored->getClass());

        Relation::morphMap([], false);

        $this->assertSame('1', $restored->getClass());
    }

    public function testNullClassIsPreserved(): void
    {
        $identifier = new ModelIdentifier(null, [], []);

        $this->assertNull($identifier->getClass());

        ModelIdentifier::useMorphMap();

        $this->assertNull($identifier->getClass());
    }
}

class ModelIdentifierTestUser
{
}
