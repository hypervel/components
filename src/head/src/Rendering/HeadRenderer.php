<?php

declare(strict_types=1);

namespace Hypervel\Head\Rendering;

use Hypervel\Head\HeadData;
use Hypervel\Head\Schema\SchemaValidator;
use Hypervel\Head\TagRegistry;
use Hypervel\Head\Tags\TagBuilder;
use Hypervel\Http\Request;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;

class HeadRenderer
{
    protected TagRenderer $tags;

    /**
     * Create the head renderer.
     */
    public function __construct(
        protected SchemaValidator $schemas,
        protected TagRegistry $registry,
        ?TagRenderer $tags = null,
    ) {
        $this->tags = $tags ?? new TagRenderer;
    }

    /**
     * Render the resolved head as an HTML string.
     */
    public function render(HeadData $head, ?Request $request = null): string
    {
        return implode(PHP_EOL, $this->toElements($head, $request));
    }

    /**
     * Render the initial Inertia document head as an HTML string.
     */
    public function renderInertiaDocument(HeadData $inertiaGlobals, HeadData $head, ?Request $request = null): string
    {
        return implode(PHP_EOL, $this->toInertiaDocumentElements($inertiaGlobals, $head, $request));
    }

    /**
     * Render the resolved head as individual HTML element strings.
     *
     * @return array<int, string>
     */
    public function toElements(HeadData $head, ?Request $request = null): array
    {
        return $this->elements($head, $request, $this->tags);
    }

    /**
     * Render the resolved head as individual HTML element strings with stable
     * data-inertia keys for Inertia's server-managed head reconciler.
     *
     * @return array<int, string>
     */
    public function toInertiaElements(HeadData $head, ?Request $request = null): array
    {
        return $this->uniqueInertiaElements(
            $this->elements($head, $request, $this->tags->withInertiaAttributes()),
        );
    }

    /**
     * Remove exact Inertia elements from an HTML string.
     *
     * @param array<int, string> $elements
     */
    public function withoutInertiaElements(string $html, array $elements): string
    {
        return str_replace($elements, '', $html);
    }

    /**
     * Render the initial Inertia document head. Inertia globals are emitted as
     * plain, server-owned tags while page tags receive Inertia ownership keys.
     *
     * @return array<int, string>
     */
    public function toInertiaDocumentElements(HeadData $inertiaGlobals, HeadData $head, ?Request $request = null): array
    {
        return [
            ...$this->toElements($inertiaGlobals, $request),
            ...$this->toInertiaElements($head, $request),
        ];
    }

    /**
     * Apply Inertia's last-write-wins ownership semantics to rendered elements.
     *
     * @param array<int, string> $elements
     * @return array<int, string>
     */
    protected function uniqueInertiaElements(array $elements): array
    {
        $unique = [];
        $indexes = [];

        foreach ($elements as $element) {
            $key = Str::match('/\sdata-inertia="([^"]+)"/', $element);

            // Inertia collects titles separately from all attribute-keyed elements.
            $key = str_starts_with($element, '<title ')
                ? 'title'
                : ($key === '' ? '' : 'tag:' . $key);

            if ($key === '') {
                $unique[] = $element;

                continue;
            }

            if (isset($indexes[$key])) {
                $unique[$indexes[$key]] = $element;

                continue;
            }

            $indexes[$key] = count($unique);
            $unique[] = $element;
        }

        return $unique;
    }

    /**
     * Render every builder in the resolved head.
     *
     * @return array<int, string>
     */
    protected function elements(HeadData $head, ?Request $request, TagRenderer $tags): array
    {
        $resolved = new ResolvedHead($head, $this->registry, $request, $this->schemas);

        return collect($resolved->builders())
            ->flatMap(fn (TagBuilder $builder): array => $builder->toTags($resolved, $tags))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Render the resolved head as the array returned by Head::toArray().
     *
     * @return array<string, mixed>
     */
    public function toArray(HeadData $head, ?Request $request = null): array
    {
        $resolved = new ResolvedHead($head, $this->registry, $request, $this->schemas);

        $headArray = [];

        foreach ($this->registry->builders() as $builder) {
            Arr::set($headArray, $builder::headArrayKey(), $resolved->headArray($builder));
        }

        return $headArray;
    }
}
