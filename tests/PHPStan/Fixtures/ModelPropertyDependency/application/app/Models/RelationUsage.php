<?php

declare(strict_types=1);

namespace ModelPropertyDependency\Models;

/**
 * Builder calls through a relation, never naming the builder or a model that ModelUsage calls through.
 */
class RelationUsage
{
    /**
     * Run a passthru method through a relation.
     */
    public function subgroupsExist(Group $group): bool
    {
        return $group->subgroups()->exists();
    }

    /**
     * Call a builder method through a relation.
     */
    public function publishedSubgroups(Group $group): void
    {
        $group->subgroups()->published();
    }
}
