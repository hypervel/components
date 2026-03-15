<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Exceptions\Renderer;

use Hypervel\Contracts\View\Factory;
use Hypervel\Foundation\Exceptions\Renderer\Mappers\BladeMapper;
use Hypervel\Foundation\Vite;
use Hypervel\Http\Request;
use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;
use Throwable;

class Renderer
{
    /**
     * The path to the renderer's distribution files.
     */
    protected const DIST = __DIR__ . '/../../../resources/exceptions/renderer/dist/';

    /**
     * Create a new exception renderer instance.
     *
     * @param Listener $listener The exception listener that collects query data
     * @param HtmlErrorRenderer $htmlErrorRenderer Symfony's HTML error renderer for flattening exceptions
     * @param BladeMapper $bladeMapper Maps compiled Blade paths back to original view files
     */
    public function __construct(
        protected Factory $viewFactory,
        protected Listener $listener,
        protected HtmlErrorRenderer $htmlErrorRenderer,
        protected BladeMapper $bladeMapper,
        protected string $basePath,
    ) {
    }

    /**
     * Render the given exception as an HTML string.
     */
    public function render(Request $request, Throwable $throwable): string
    {
        $flattenException = $this->bladeMapper->map(
            $this->htmlErrorRenderer->render($throwable),
        );

        $exception = new Exception($flattenException, $request, $this->listener, $this->basePath);

        $exceptionAsMarkdown = $this->viewFactory->make('hypervel-exceptions-renderer::markdown', [
            'exception' => $exception,
        ])->render();

        return $this->viewFactory->make('hypervel-exceptions-renderer::show', [
            'exception' => $exception,
            'exceptionAsMarkdown' => $exceptionAsMarkdown,
        ])->render();
    }

    /**
     * Get the renderer's CSS content.
     */
    public static function css(): string
    {
        return '<style>' . file_get_contents(static::DIST . 'styles.css') . '</style>';
    }

    /**
     * Get the renderer's JavaScript content.
     */
    public static function js(): string
    {
        $viteJsAutoRefresh = '';

        $vite = app(Vite::class);

        if (is_file($vite->hotFile())) {
            $viteJsAutoRefresh = $vite->__invoke([]);
        }

        return '<script>'
            . file_get_contents(static::DIST . 'scripts.js')
            . '</script>' . $viteJsAutoRefresh;
    }
}
