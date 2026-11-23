<?php

declare(strict_types=1);

namespace ModelPropertyDependency\Models;

/**
 * Builder calls through a model, never naming the builder or a model that RelationUsage calls through.
 */
class ModelUsage
{
    /**
     * Run a passthru method through the model.
     */
    public function exists(): bool
    {
        return User::exists();
    }
}
