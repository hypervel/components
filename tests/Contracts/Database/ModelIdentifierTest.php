<?php

declare(strict_types=1);

namespace Hypervel\Tests\Contracts\Database;

use Hypervel\Contracts\Database\ModelIdentifier;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Tests\TestCase;

class ModelIdentifierTest extends TestCase
{
    public function testFlushStateRestoresRawClassSerialization()
    {
        try {
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
        } finally {
            Relation::morphMap([], false);
            ModelIdentifier::flushState();
        }
    }
}

class ModelIdentifierTestUser
{
}
