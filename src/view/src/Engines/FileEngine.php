<?php

declare(strict_types=1);

namespace Hypervel\View\Engines;

use Hypervel\View\Contracts\Engine;
use Hypervel\Filesystem\Filesystem;

class FileEngine implements Engine
{
    /**
     * Create a new file engine instance.
     */
    public function __construct(
        protected Filesystem $files
    ) {
    }

    /**
     * Get the evaluated contents of the view.
     */
    public function get(string $path, array $data = []): string
    {
        return $this->files->get($path);
    }
}
