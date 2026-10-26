<?php

declare(strict_types=1);

namespace Hypervel\Head\Rendering;

use Hypervel\Contracts\Container\Transient;
use Hypervel\Head\HeadData;
use Hypervel\Head\Schema\SchemaValidator;
use Hypervel\Head\TagRegistry;
use Hypervel\Head\Tags\Canonical;
use Hypervel\Head\Tags\Description;
use Hypervel\Head\Tags\OpenGraph;
use Hypervel\Head\Tags\Robots;
use Hypervel\Head\Tags\TagBuilder;
use Hypervel\Head\Tags\Title;
use Hypervel\Http\Request;
use Hypervel\Support\Arr;

class ResolvedHead implements Transient
{
    /**
     * Create a resolved view of the page metadata.
     */
    public function __construct(
        protected HeadData $data,
        protected TagRegistry $registry,
        protected ?Request $request = null,
        protected ?SchemaValidator $schemas = null,
    ) {
    }

    /**
     * Get the resolved head data.
     */
    public function data(): HeadData
    {
        return $this->data;
    }

    /**
     * Get the request used for rendering.
     */
    public function request(): ?Request
    {
        return $this->request;
    }

    /**
     * Validate a structured data definition.
     *
     * @param array<string, mixed> $schema
     */
    public function validateSchema(array $schema): void
    {
        $this->schemas?->validate($schema);
    }

    /**
     * Get the resolved title.
     */
    public function title(): ?string
    {
        $title = $this->builder(Title::class);

        return $title instanceof Title ? $title->render() : null;
    }

    /**
     * Get the resolved description.
     */
    public function description(): ?string
    {
        $description = $this->builder(Description::class);

        return $description instanceof Description ? $description->render() : null;
    }

    /**
     * Get the resolved canonical URL.
     */
    public function canonical(): ?string
    {
        $canonical = $this->builder(Canonical::class);

        return $canonical instanceof Canonical ? $canonical->render($this->request) : null;
    }

    /**
     * Get the resolved robots directives.
     */
    public function robots(): ?string
    {
        $robots = $this->builder(Robots::class);

        return $robots instanceof Robots ? $robots->render() : null;
    }

    /**
     * Get the first Open Graph image and its alternative text.
     *
     * @return null|array<string, string>
     */
    public function openGraphImage(): ?array
    {
        $openGraph = $this->builder(OpenGraph::class);

        if (! $openGraph instanceof OpenGraph || ($images = $openGraph->images()) === []) {
            return null;
        }

        $image = reset($images);

        return Arr::whereNotNull([
            'url' => $image['url'],
            'alt' => $image['alt'] ?? null,
        ]);
    }

    /**
     * Get the tag builders to render, in declared order, including those that derive
     * their values from the rest of the head even when never set explicitly.
     *
     * @return array<int, TagBuilder>
     */
    public function builders(): array
    {
        $builders = [];

        foreach ($this->registry->builders() as $builder) {
            $value = $this->builder($builder);

            if ($value instanceof TagBuilder) {
                $builders[] = $value;
            } elseif ($builder::rendersWhenEmpty($this)) {
                $builders[] = new $builder;
            }
        }

        return $builders;
    }

    /**
     * Resolve one tag builder into its Head::toArray() value,
     * including defaults for builder data that was never set.
     *
     * @param class-string<TagBuilder> $builder
     */
    public function headArray(string $builder): mixed
    {
        $value = $this->builder($builder);

        if (! $value instanceof TagBuilder && $builder::rendersWhenEmpty($this)) {
            $value = new $builder;
        }

        return $value?->toHeadArray($this) ?? $builder::headArrayDefault();
    }

    /**
     * Get an existing tag builder from the resolved data.
     *
     * @param class-string<TagBuilder> $builder
     */
    public function builder(string $builder): ?TagBuilder
    {
        return $this->data->get($builder);
    }
}
