<?php

declare(strict_types=1);

namespace Hypervel\Head;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Htmlable;
use Hypervel\Head\Concerns\BuildsHead;
use Hypervel\Head\Rendering\HeadRenderer;
use Hypervel\Head\Routing\RouteAttributeParser;
use Hypervel\Head\Schema\SchemaFactory;
use Hypervel\Head\Tags\Canonical;
use Hypervel\Head\Tags\TagBuilder;
use Hypervel\Http\Request;
use Hypervel\Inertia\InertiaState;
use Hypervel\Routing\Route;
use Hypervel\Support\HtmlString;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;

/**
 * @implements Arrayable<array<string, mixed>>
 */
class HeadManager implements Arrayable, Htmlable
{
    use BuildsHead;
    use Conditionable;
    use Macroable;

    public const string INERTIA_PROP = 'head';

    protected HeadData $inertiaGlobals;

    protected HeadData $defaults;

    protected string $inertiaProp = self::INERTIA_PROP;

    protected ErrorPages $errorPages;

    /** @var null|(Closure(HeadBuilder): mixed) */
    protected ?Closure $defaultsResolver = null;

    /** @var null|(Closure(HeadBuilder, int): mixed) */
    protected ?Closure $errorsResolver = null;

    /**
     * Create the head manager.
     */
    public function __construct(
        protected Container $app,
        protected HeadRenderer $renderer,
        protected TagRegistry $registry,
    ) {
        $this->inertiaGlobals = new HeadData;
        $this->defaults = new HeadData;
        $this->errorPages = new ErrorPages(
            $this->registry,
            fn (callable $callback): HeadData => $this->define($callback),
        );
    }

    /**
     * Define head tags rendered as server-owned tags on every Inertia document.
     *
     * Boot-only. These tags are shared by every request in the worker.
     *
     * @param callable(HeadBuilder): mixed $callback
     */
    public function inertiaGlobals(callable $callback): static
    {
        $this->inertiaGlobals = $this->inertiaGlobals->merge($this->define($callback));

        return $this;
    }

    /**
     * Define default head data merged beneath every page's head.
     *
     * Boot-only. These defaults are shared by every request in the worker.
     *
     * @param callable(HeadBuilder): mixed $callback
     */
    public function defaults(callable $callback): static
    {
        $this->defaults = $this->defaults->merge($this->define($callback)->asDefaults());

        return $this;
    }

    /**
     * Resolve default head data from the current request context.
     *
     * Boot-only. The callback is shared by every request in the worker.
     *
     * @param null|(Closure(HeadBuilder): mixed) $resolver
     */
    public function resolveDefaultsUsing(?Closure $resolver): static
    {
        $this->defaultsResolver = $resolver;

        return $this;
    }

    /**
     * Define head data for error pages.
     *
     * Boot-only. These error definitions are shared by every request in the worker.
     *
     * @param callable(ErrorPages): mixed $callback
     */
    public function errors(callable $callback): static
    {
        $callback($this->errorPages);

        return $this;
    }

    /**
     * Resolve error head data from the current request context and status.
     *
     * Boot-only. The callback is shared by every request in the worker.
     *
     * @param null|(Closure(HeadBuilder, int): mixed) $resolver
     */
    public function resolveErrorsUsing(?Closure $resolver): static
    {
        $this->errorsResolver = $resolver;

        return $this;
    }

    /**
     * Resolve the canonical origin from the current request context.
     *
     * Boot-only. The callback persists in a static property and affects every request in the worker.
     *
     * @param null|(Closure(): ?string) $resolver
     */
    public function resolveCanonicalOriginUsing(?Closure $resolver): static
    {
        Canonical::resolveOriginUsing($resolver);

        return $this;
    }

    /**
     * Customize the Inertia prop the head elements are shared under.
     *
     * Boot-only. The prop name is shared by every request in the worker.
     */
    public function inertia(string $prop = self::INERTIA_PROP): static
    {
        $this->inertiaProp = $prop;

        return $this;
    }

    /**
     * Get the shared Inertia prop name.
     */
    public function inertiaProp(): string
    {
        return $this->inertiaProp;
    }

    /**
     * Register a custom tag builder to render on every head.
     *
     * Boot-only. Registered builders affect every request in the worker.
     *
     * @param class-string<TagBuilder> $builder
     */
    public function extend(string $builder): static
    {
        $this->registry->extend($builder);

        return $this;
    }

    /**
     * Set the response status used to resolve error page head data.
     */
    public function status(int $status): static
    {
        $this->state()->setStatus($status);

        return $this;
    }

    /**
     * Resolve the document metadata as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(?int $status = null): array
    {
        return $this->renderer->toArray($this->resolveDocument($status), $this->request());
    }

    /**
     * Render the document head elements.
     *
     * @return array<int, string>
     */
    public function toElements(?int $status = null): array
    {
        return $this->renderer->toElements($this->resolveDocument($status), $this->request());
    }

    /**
     * Render the page-managed Inertia head elements.
     *
     * @return array<int, string>
     */
    public function toInertiaElements(?int $status = null): array
    {
        return $this->renderer->toInertiaElements($this->resolve($status), $this->request());
    }

    /**
     * Render the document head as HTML.
     */
    public function render(?int $status = null): HtmlString
    {
        return new HtmlString($this->renderer->render($this->resolveDocument($status), $this->request()));
    }

    /**
     * Render tags for a Blade view, adding Inertia ownership attributes when
     * the current request is rendering an Inertia page.
     */
    public function renderForView(?int $status = null): HtmlString
    {
        if (! $this->renderingInertiaPage()) {
            return $this->render($status);
        }

        return new HtmlString($this->renderer->renderInertiaDocument(
            $this->inertiaGlobals,
            $this->resolve($status),
            $this->request(),
        ));
    }

    /**
     * Get the rendered document head HTML.
     */
    public function toHtml(?int $status = null): string
    {
        return $this->render($status)->toHtml();
    }

    /**
     * Flush runtime data for the current request scope.
     */
    public function flush(): static
    {
        $this->state()->flush();

        return $this;
    }

    /**
     * Run a definition callback against a fresh head builder and return its data.
     */
    protected function define(callable $callback): HeadData
    {
        $data = new HeadData;

        $callback(new HeadBuilder($data, $this->schemaFactory()));

        return $data;
    }

    /**
     * Resolve all configured head layers into the final data for rendering.
     */
    protected function resolve(?int $status = null): HeadData
    {
        $data = (new HeadData)->merge($this->defaults);

        if ($this->defaultsResolver !== null) {
            $data = $data->merge($this->define($this->defaultsResolver)->asDefaults());
        }

        if ($route = $this->route()) {
            foreach (RouteAttributeParser::routeMetadata($route) as $attributes) {
                $data = RouteAttributeParser::apply($data, $attributes, $this->registry);
            }
        }

        $data = $data->merge($this->state()->data());

        $errorStatus = $status ?? $this->state()->status();

        if (! is_null($errorStatus) && $error = $this->errorPages->forStatus($errorStatus)) {
            $data = $data->merge($error);
        }

        if ($errorStatus !== null && $this->errorsResolver !== null) {
            $data = $data->merge($this->define(
                fn (HeadBuilder $head): mixed => ($this->errorsResolver)($head, $errorStatus),
            ));
        }

        return $data;
    }

    /**
     * Resolve server-owned globals beneath the page metadata.
     */
    protected function resolveDocument(?int $status = null): HeadData
    {
        return (new HeadData)
            ->merge($this->inertiaGlobals)
            ->merge($this->resolve($status));
    }

    /**
     * Determine if the current request is rendering an Inertia page. Inertia
     * stores the page in coroutine-local state before the root view renders, so
     * this holds at any depth of the view tree, unlike the view's variables.
     */
    protected function renderingInertiaPage(): bool
    {
        return class_exists(InertiaState::class)
            && (CoroutineContext::get(InertiaState::CONTEXT_KEY)->page ?? []) !== [];
    }

    /**
     * Get the head data instance the fluent methods should write to.
     */
    protected function headData(): HeadData
    {
        return $this->state()->data();
    }

    /**
     * Get the registered schema factory.
     */
    protected function schemaFactory(): SchemaFactory
    {
        return $this->app->make(SchemaFactory::class);
    }

    /**
     * Get the current coroutine's head state.
     */
    protected function state(): CurrentHead
    {
        return $this->app->make(CurrentHead::class);
    }

    /**
     * Get the current request or the application's console request.
     */
    protected function request(): ?Request
    {
        return $this->app->make('request');
    }

    /**
     * Get the current request's route.
     */
    protected function route(): ?Route
    {
        $route = $this->request()?->route();

        return $route instanceof Route ? $route : null;
    }
}
