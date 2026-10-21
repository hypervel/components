<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Tools;

use Closure;
use Hypervel\Ai\Store;
use Hypervel\Support\Collection;

class FileSearch extends ProviderTool
{
    /**
     * The file search filters.
     *
     * @var array<int, array{type: 'eq'|'in'|'ne'|'nin', key: string, value: mixed}>
     */
    public array $filters = [];

    /**
     * Create a new file search tool instance.
     *
     * @param null|array|(Closure(FileSearchQuery): mixed) $where
     */
    public function __construct(
        public array $stores,
        Closure|array|null $where = null,
    ) {
        $this->filters = $this->resolveFilters($where);
    }

    /**
     * Get the string store IDs assigned to the tool.
     */
    public function ids(): array
    {
        return (new Collection($this->stores))
            ->map(fn (mixed $store): mixed => $store instanceof Store
                ? $store->id
                : $store)->all();
    }

    /**
     * Resolve the filters from the given value.
     *
     * @param null|array|(Closure(FileSearchQuery): mixed) $where
     * @return array<int, array{type: 'eq'|'in'|'ne'|'nin', key: string, value: mixed}>
     */
    protected function resolveFilters(Closure|array|null $where): array
    {
        if ($where === null) {
            return [];
        }

        if (is_array($where)) {
            return (new Collection($where))->map(fn (mixed $value, int|string $key): array => [
                'type' => 'eq',
                'key' => (string) $key,
                'value' => $value,
            ])->values()->all();
        }

        $where($query = new FileSearchQuery);

        return $query->toArray();
    }
}
