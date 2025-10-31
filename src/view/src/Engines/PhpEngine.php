<?php

declare(strict_types=1);

namespace Hypervel\View\Engines;

use Hypervel\View\Contracts\Engine;
use Hypervel\Filesystem\Filesystem;
use Throwable;

class PhpEngine implements Engine
{
    /**
     * The filesystem instance.
     */
    protected Filesystem $files;

    /**
     * Create a new file engine instance.
     */
    public function __construct(Filesystem $files)
    {
        $this->files = $files;
    }

    /**
     * Get the evaluated contents of the view.
     */
    public function get(string $path, array $data = []): string
    {
        return $this->evaluatePath($path, $data);
    }

    /**
     * Get the evaluated contents of the view at the given path.
     */
    protected function evaluatePath(string $path, array $data): string
    {
        $obLevel = ob_get_level();

        ob_start();

        // We'll evaluate the contents of the view inside a try/catch block so we can
        // flush out any stray output that might get out before an error occurs or
        // an exception is thrown. This prevents any partial views from leaking.
        try {
            $this->files->getRequire($path, $data);
        } catch (Throwable $e) {
            $this->handleViewException($e, $obLevel);
        }

        return ltrim(ob_get_clean());
    }

    /**
     * Handle a view exception.
     *
     * @throws Throwable
     */
    protected function handleViewException(Throwable $e, int $obLevel): void
    {
        while (ob_get_level() > $obLevel) {
            ob_end_clean();
        }

        throw $e;
    }
}
