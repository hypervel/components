<?php

declare(strict_types=1);

namespace ModelScopeMethods;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Casts\Attribute;
use Countable;
use Hypervel\Database\Eloquent\Model;
use LogicException;

interface FiltersByStatus
{
    /** @param Builder<Model> $query */
    public function scopeStatus(Builder $query, string $status): void;
}

trait FiltersByOwner
{
    /** @param Builder<Model> $query */
    public function scopeOwnedBy(Builder $query, int $ownerId): void
    {
    }
}

class Post extends Model implements FiltersByStatus
{
    use FiltersByOwner;

    /** @param Builder<Model> $query */
    protected function scopeActive(Builder $query): void
    {
    }

    /** @param Builder<Model> $query */
    public function scopeStatus(Builder $query, string $status): void
    {
    }

    /** @param Builder<Model> $query */
    public function scopeRead(Builder $query): void
    {
    }

    public function read(): bool
    {
        return true;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return [];
    }

    /** @param list<string> $haystack */
    public function scopeExistsIn(string $scope, array $haystack): bool
    {
        return true;
    }

    /** @return Attribute<string, never> */
    public function title()
    {
        return Attribute::get(fn (): string => 'title');
    }

    /** @return null|Attribute<string, never> */
    public function summary(): ?Attribute
    {
        return null;
    }

    /** @return Attribute<string, never>|string */
    public function excerpt(): Attribute|string
    {
        return 'excerpt';
    }

    /** @return Attribute<string, never>&Countable */
    public function wordCount(): Attribute&Countable
    {
        throw new LogicException;
    }
}

class Draft extends Post
{
    /** @param Builder<Model> $query */
    protected function scopeActive(Builder $query): void
    {
    }
}

class Route
{
    public function scopeBindings(): static
    {
        return $this;
    }
}
