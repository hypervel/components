<?php

declare(strict_types=1);

namespace Hypervel\Inertia\DevTools\Http;

use Hypervel\Http\Request;
use Hypervel\Inertia\DevTools\EntriesRepository;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;

class EntriesController
{
    /**
     * Create a new entries controller instance.
     */
    public function __construct(protected EntriesRepository $repository)
    {
    }

    /**
     * List the recorded entries, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function index(Request $request): Collection
    {
        $component = $request->query('component');
        $include = $this->typeList($request->query('type'));
        $exclude = $this->typeList($request->query('exclude'));
        $offset = max(0, (int) $request->query('offset', '0'));
        $limit = $request->query('limit');

        return collect($this->repository->all())
            ->when(is_string($component) && $component !== '', fn (Collection $entries): Collection => $entries->where('component', $component))
            ->when($include !== [], fn (Collection $entries): Collection => $entries->whereIn('requestType', $include))
            ->when($exclude !== [], fn (Collection $entries): Collection => $entries->whereNotIn('requestType', $exclude))
            ->when($offset > 0, fn (Collection $entries): Collection => $entries->slice($offset))
            ->when(is_numeric($limit), fn (Collection $entries): Collection => $entries->take(max(1, (int) $limit)))
            ->values();
    }

    /**
     * Show the given recorded entry.
     *
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        if (! Str::isUlid($id)) {
            abort(404, 'Not found.');
        }

        return $this->repository->get($id) ?? abort(404, 'Not found.');
    }

    /**
     * Parse a comma-separated request-type query value into a list.
     *
     * @return array<int, string>
     */
    protected function typeList(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
