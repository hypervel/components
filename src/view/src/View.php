<?php

declare(strict_types=1);

namespace Hypervel\View;

use ArrayAccess;
use BadMethodCallException;
use Hypervel\Support\Collection;
use Hypervel\Support\Contracts\Arrayable;
use Hypervel\Support\Contracts\Htmlable;
use Hypervel\Support\Contracts\MessageBag as MessageBagContract;
use Hypervel\Support\Contracts\MessageProvider;
use Hypervel\Support\Contracts\Renderable;
use Hypervel\Support\HtmlString;
use Hypervel\Support\MessageBag;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Support\ViewErrorBag;
use Hypervel\View\Contracts\Engine;
use Hypervel\View\Contracts\View as ViewContract;
use Stringable;
use Throwable;

class View implements ArrayAccess, Htmlable, Stringable, ViewContract
{
    use Macroable {
        __call as macroCall;
    }

    /**
     * The array of view data.
     */
    protected array $data;

    /**
     * Create a new view instance.
     */
    public function __construct(
        protected Factory $factory,
        protected Engine $engine,
        protected string $view,
        protected string $path,
        mixed $data = []
    ) {
        $this->data = $data instanceof Arrayable ? $data->toArray() : (array) $data;
    }

    /**
     * Get the evaluated contents of a given fragment.
     */
    public function fragment(string $fragment): HtmlString
    {
        $content = $this->render(function () use ($fragment) {
            return $this->factory->getFragment($fragment);
        });

        return new HtmlString($content);
    }

    /**
     * Get the evaluated contents for a given array of fragments or return all fragments.
     */
    public function fragments(?array $fragments = null): string
    {
        return is_null($fragments)
            ? $this->allFragments()
            : (new Collection($fragments))->map(fn ($f) => $this->fragment($f))->implode('');
    }

    /**
     * Get the evaluated contents of a given fragment if the given condition is true.
     */
    public function fragmentIf(bool $boolean, string $fragment): string|Htmlable
    {
        if (value($boolean)) {
            return $this->fragment($fragment);
        }

        return $this->render();
    }

    /**
     * Get the evaluated contents for a given array of fragments if the given condition is true.
     */
    public function fragmentsIf(bool $boolean, ?array $fragments = null): string
    {
        if (value($boolean)) {
            return $this->fragments($fragments);
        }

        return $this->render();
    }

    /**
     * Get all fragments as a single string.
     */
    protected function allFragments(): string
    {
        return (new Collection($this->render(fn () => $this->factory->getFragments())))->implode('');
    }

    /**
     * Get the string contents of the view.
     *
     * @throws Throwable
     */
    public function render(?callable $callback = null): string
    {
        try {
            $contents = $this->renderContents();

            $response = isset($callback) ? $callback($this, $contents) : null;

            // Once we have the contents of the view, we will flush the sections if we are
            // done rendering all views so that there is nothing left hanging over when
            // another view gets rendered in the future by the application developer.
            $this->factory->flushStateIfDoneRendering();

            return ! is_null($response) ? $response : $contents;
        } catch (Throwable $e) {
            $this->factory->flushState();

            throw $e;
        }
    }

    /**
     * Get the contents of the view instance.
     */
    protected function renderContents(): string
    {
        // We will keep track of the number of views being rendered so we can flush
        // the section after the complete rendering operation is done. This will
        // clear out the sections for any separate views that may be rendered.
        $this->factory->incrementRender();

        $this->factory->callComposer($this);

        $contents = $this->getContents();

        // Once we've finished rendering the view, we'll decrement the render count
        // so that each section gets flushed out next time a view is created and
        // no old sections are staying around in the memory of an environment.
        $this->factory->decrementRender();

        return $contents;
    }

    /**
     * Get the evaluated contents of the view.
     */
    protected function getContents(): string
    {
        return $this->engine->get($this->path, $this->gatherData());
    }

    /**
     * Get the data bound to the view instance.
     */
    public function gatherData(): array
    {
        $data = array_merge($this->factory->getShared(), $this->data);

        foreach ($data as $key => $value) {
            if ($value instanceof Renderable) {
                $data[$key] = $value->render();
            }
        }

        return $data;
    }

    /**
     * Get the sections of the rendered view.
     *
     * This function is similar to render. We need to call `renderContents` function first.
     * Because sections are only populated during the view rendering process.
     *
     * @throws Throwable
     */
    public function renderSections(): array
    {
        try {
            $this->renderContents();

            $response = $this->factory->getSections();

            // Once we have the contents of the view, we will flush the sections if we are
            // done rendering all views so that there is nothing left hanging over when
            // another view gets rendered in the future by the application developer.
            $this->factory->flushStateIfDoneRendering();

            return $response;
        } catch (Throwable $e) {
            $this->factory->flushState();

            throw $e;
        }
    }

    /**
     * Add a piece of data to the view.
     */
    public function with(string|array $key, mixed $value = null): static
    {
        if (is_array($key)) {
            $this->data = array_merge($this->data, $key);
        } else {
            $this->data[$key] = $value;
        }

        return $this;
    }

    /**
     * Add a view instance to the view data.
     */
    public function nest(string $key, string $view, array $data = []): static
    {
        return $this->with($key, $this->factory->make($view, $data));
    }

    /**
     * Add validation errors to the view.
     */
    public function withErrors(MessageProvider|array|string $provider, string $bag = 'default'): static
    {
        return $this->with('errors', (new ViewErrorBag())->put(
            $bag,
            $this->formatErrors($provider)
        ));
    }

    /**
     * Parse the given errors into an appropriate value.
     */
    protected function formatErrors(MessageProvider|array|string $provider): MessageBagContract
    {
        return $provider instanceof MessageProvider
                        ? $provider->getMessageBag()
                        : new MessageBag((array) $provider);
    }

    /**
     * Get the name of the view.
     */
    public function name(): string
    {
        return $this->getName();
    }

    /**
     * Get the name of the view.
     */
    public function getName(): string
    {
        return $this->view;
    }

    /**
     * Get the array of view data.
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get the path to the view file.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Set the path to the view.
     */
    public function setPath(string $path): void
    {
        $this->path = $path;
    }

    /**
     * Get the view factory instance.
     */
    public function getFactory(): Factory
    {
        return $this->factory;
    }

    /**
     * Get the view's rendering engine.
     */
    public function getEngine(): Engine
    {
        return $this->engine;
    }

    /**
     * Determine if a piece of data is bound.
     */
    public function offsetExists(mixed $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Get a piece of bound data to the view.
     */
    public function offsetGet(mixed $key): mixed
    {
        return $this->data[$key];
    }

    /**
     * Set a piece of data on the view.
     */
    public function offsetSet(mixed $key, mixed $value): void
    {
        $this->with($key, $value);
    }

    /**
     * Unset a piece of data from the view.
     */
    public function offsetUnset(mixed $key): void
    {
        unset($this->data[$key]);
    }

    /**
     * Get a piece of data from the view.
     */
    public function &__get(string $key): mixed
    {
        return $this->data[$key];
    }

    /**
     * Set a piece of data on the view.
     */
    public function __set(string $key, mixed $value): void
    {
        $this->with($key, $value);
    }

    /**
     * Check if a piece of data is bound to the view.
     */
    public function __isset(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * Remove a piece of bound data from the view.
     */
    public function __unset(string $key): void
    {
        unset($this->data[$key]);
    }

    /**
     * Dynamically bind parameters to the view.
     *
     * @return static
     *
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        if (! str_starts_with($method, 'with')) {
            throw new BadMethodCallException(sprintf(
                'Method %s::%s does not exist.',
                static::class,
                $method
            ));
        }

        return $this->with(Str::camel(substr($method, 4)), $parameters[0]);
    }

    /**
     * Get content as a string of HTML.
     *
     * @throws Throwable
     */
    public function toHtml(): string
    {
        return $this->render();
    }

    /**
     * Get the string contents of the view.
     *
     * @throws Throwable
     */
    public function __toString(): string
    {
        return $this->render();
    }
}
