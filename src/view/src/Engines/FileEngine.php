<?php

namespace Hypervel\View\Engines;

use Hypervel\Contracts\View\Engine;
use Hypervel\Filesystem\Filesystem;

class FileEngine implements Engine
{
    /**
     * The filesystem instance.
     *
     * @var \Hypervel\Filesystem\Filesystem
     */
    protected Filesystem $files;

    /**
     * Create a new file engine instance.
     *
     * @param  \Hypervel\Filesystem\Filesystem  $files
     * @return void
     */
    public function __construct(Filesystem $files): void
    {
        $this->files = $files;
    }

    /**
     * Get the evaluated contents of the view.
     *
     * @param  string  $path
     * @param  array  $data
     * @return string
     */
    public function get(string $path, array $data = []): string
    {
        return $this->files->get($path);
    }
}
