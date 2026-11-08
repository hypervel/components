<?php

declare(strict_types=1);

namespace ModelPropertyDependency\Models;

/**
 * Builder calls on the builder itself, never naming a model that ModelUsage or RelationUsage calls through.
 */
class BuilderUsage
{
    /**
     * Run a passthru method on the builder.
     */
    public function exists(UserBuilder $builder): bool
    {
        return $builder->exists();
    }

    /**
     * Call a method of the builder's query class.
     */
    public function active(UserBuilder $builder): void
    {
        $builder->whereActive();
    }
}
