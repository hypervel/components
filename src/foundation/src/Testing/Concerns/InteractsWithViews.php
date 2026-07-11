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
use RuntimeException;
use Throwable;

trait InteractsWithViews
{
    /**
     * Create a new TestView from the given view.
     */
    protected function view(string $view, Arrayable|array $data = []): TestView
    {
        /** @var View $rendered */
        $rendered = view($view, $data);

        return new TestView($rendered);
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

        $placeholder = @tempnam($tempDirectory, 'hypervel-blade');

        if ($placeholder === false) {
            throw new RuntimeException('Unable to create a temporary Blade view file.');
        }

        $tempFileInfo = pathinfo($placeholder);
        $tempFile = $tempFileInfo['dirname'] . '/' . $tempFileInfo['filename'] . '.blade.php';

        try {
            if (@file_put_contents($placeholder, $template) !== strlen($template)) {
                throw new RuntimeException('Unable to write the complete temporary Blade view.');
            }

            if (! @rename($placeholder, $tempFile)) {
                throw new RuntimeException("Unable to move the temporary Blade view to [{$tempFile}].");
            }
        } catch (Throwable $exception) {
            @unlink($placeholder);
            @unlink($tempFile);

            throw $exception;
        }

        $this->beforeApplicationDestroyed(function () use ($tempFile) {
            @unlink($tempFile);
        });

        /** @var View $rendered */
        $rendered = view($tempFileInfo['filename'], $data);

        return new TestView($rendered);
    }

    /**
     * Render the given view component.
     */
    protected function component(string $componentClass, Arrayable|array $data = []): TestComponent
    {
        $component = $this->app->make($componentClass, $data);

        $view = value($component->resolveView(), $data);

        /** @var View $view */
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
        ViewFacade::share('errors', (new ViewErrorBag)->put($key, new MessageBag($errors)));

        return $this;
    }
}
