<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Types\EagerLoadMap;

/**
 * The relation-keyed maps an eager-load-map type describes, each backed by its PHPDoc name.
 *
 * Morph maps are read the way their consumer reads them: loadMorph() and its aggregate siblings look up a
 * model's class before its morph alias, while MorphTo resolves a key through the morph map first.
 */
enum EagerLoadMapKind: string
{
    case EagerLoads = 'eager-loads';
    case RelationAggregates = 'relation-aggregates';
    case MorphEagerLoads = 'morph-eager-loads';
    case MorphAggregates = 'morph-aggregates';
    case MorphTypeEagerLoads = 'morph-type-eager-loads';
    case MorphTypeAggregates = 'morph-type-aggregates';
    case MorphTypeConstraints = 'morph-type-constraints';

    /**
     * Determine whether the map's PHPDoc form names the model whose relations it holds.
     */
    public function hasModel(): bool
    {
        return $this === self::EagerLoads || $this === self::RelationAggregates;
    }

    /**
     * Determine whether a morph key resolves through the morph map before naming a class.
     */
    public function resolvesAliasFirst(): bool
    {
        return $this === self::MorphTypeEagerLoads
            || $this === self::MorphTypeAggregates
            || $this === self::MorphTypeConstraints;
    }
}
