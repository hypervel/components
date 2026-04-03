<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Facades\View as ViewFacade;
use Hypervel\Support\MessageBag;
use Hypervel\Support\ViewErrorBag;
use Hypervel\Testing\TestComponent;
use Hypervel\Testing\TestView;
use Hypervel\View\View;

trait InteractsWithViews
{
    /**
     * Create a new TestView from the given view.
     */
    protected function view(string $view, Arrayable|array $data = []): TestView
    {
        return new TestView(view($view, $data));
    }

    /**
     * Render the contents of the given Blade template string.
     */
    protected function blade(string $template, Arrayable|array $data = []): TestView
    {
        $tempDirectory = sys_get_temp_dir();

        if (! in_array($tempDirectory, ViewFacade::getFinder()->getPaths())) {
            ViewFacade::addLocation(sys_get_temp_dir());
        }

        $tempFileInfo = pathinfo(tempnam($tempDirectory, 'hypervel-blade'));

        // Remove the placeholder file created by tempnam() — we only need the unique name.
        @unlink($tempFileInfo['dirname'] . '/' . $tempFileInfo['basename']);

        $tempFile = $tempFileInfo['dirname'] . '/' . $tempFileInfo['filename'] . '.blade.php';

        file_put_contents($tempFile, $template);

        $this->beforeApplicationDestroyed(function () use ($tempFile) {
            @unlink($tempFile);
        });

        return new TestView(view($tempFileInfo['filename'], $data));
    }

    /**
     * Render the given view component.
     */
    protected function component(string $componentClass, Arrayable|array $data = []): TestComponent
    {
        $component = $this->app->make($componentClass, $data);

        $view = value($component->resolveView(), $data);

        $view = $view instanceof View
            ? $view->with($component->data())
            : view($view, $component->data());

        return new TestComponent($component, $view);
    }

    /**
     * Populate the shared view error bag with the given errors.
     *
     * @return $this
     */
    protected function withViewErrors(array $errors, string $key = 'default'): static
    {
        ViewFacade::share('errors', (new ViewErrorBag())->put($key, new MessageBag($errors)));

        return $this;
    }
}
