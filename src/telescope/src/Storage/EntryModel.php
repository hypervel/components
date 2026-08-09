<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Storage;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;
use Hypervel\Telescope\Database\Factories\EntryModelFactory;

class EntryModel extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected ?string $table = 'telescope_entries';

    /**
     * The name of the "updated at" column.
     *
     * @var null|string
     */
    public const UPDATED_AT = null;

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [
        'content' => 'json',
    ];

    /**
     * The primary key for the model.
     */
    protected string $primaryKey = 'uuid';

    /**
     * The "type" of the auto-incrementing ID.
     */
    protected string $keyType = 'string';

    /**
     * Prevent Eloquent from overriding uuid with `lastInsertId`.
     */
    public bool $incrementing = false;

    /**
     * Scope the query for the given query options.
     */
    public function scopeWithTelescopeOptions(Builder $query, ?string $type, EntryQueryOptions $options): Builder
    {
        $this->whereType($query, $type)
            ->whereBatchId($query, $options)
            ->whereUuids($query, $options)
            ->whereTag($query, $options)
            ->whereFamilyHash($query, $options)
            ->whereBeforeSequence($query, $options)
            ->filter($query, $options);

        return $query;
    }

    /**
     * Scope the query for the given type.
     */
    protected function whereType(Builder $query, ?string $type): static
    {
        $query->when($type, function ($query, $type) {
            return $query->where('type', $type);
        });

        return $this;
    }

    /**
     * Scope the query for the given batch ID.
     */
    protected function whereBatchId(Builder $query, EntryQueryOptions $options): static
    {
        $query->when($options->batchId, function ($query, $batchId) {
            return $query->where('batch_id', $batchId);
        });

        return $this;
    }

    /**
     * Scope the query for the given entry UUIDs.
     */
    protected function whereUuids(Builder $query, EntryQueryOptions $options): static
    {
        if ($options->uuids !== null) {
            $query->whereIn('uuid', $options->uuids);
        }

        return $this;
    }

    /**
     * Scope the query for the given tag.
     */
    protected function whereTag(Builder $query, EntryQueryOptions $options): static
    {
        if ($options->tag !== null) {
            $tags = Collection::make(explode(',', $options->tag))->map(fn ($tag) => trim($tag));

            $query->whereIn('uuid', function ($query) use ($tags) {
                $query->select('entry_uuid')->from('telescope_entries_tags')
                    ->whereIn('entry_uuid', function ($query) use ($tags) {
                        $query->select('entry_uuid')->from('telescope_entries_tags')->whereIn('tag', $tags->all());
                    });
            });
        }

        return $this;
    }

    /**
     * Scope the query for the given family hash.
     */
    protected function whereFamilyHash(Builder $query, EntryQueryOptions $options): static
    {
        $query->when($options->familyHash, function ($query, $hash) {
            return $query->where('family_hash', $hash);
        });

        return $this;
    }

    /**
     * Scope the query for the given pagination options.
     */
    protected function whereBeforeSequence(Builder $query, EntryQueryOptions $options): static
    {
        if ($options->beforeSequence !== null) {
            $query->where('sequence', '<', $options->beforeSequence);
        }

        return $this;
    }

    /**
     * Scope the query for the given display options.
     */
    protected function filter(Builder $query, EntryQueryOptions $options): static
    {
        if ($options->familyHash || $options->tag !== null || $options->batchId) {
            return $this;
        }

        $query->where('should_display_on_index', true);

        return $this;
    }

    /**
     * Get the current connection name for the model.
     */
    public function getConnectionName(): ?string
    {
        return config('telescope.storage.database.connection');
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ?EntryModelFactory
    {
        return EntryModelFactory::new();
    }
}
