<?php

namespace Hypervel\View\Compilers\Concerns;

use Hypervel\Foundation\Vite;

trait CompilesHelpers
{
    /**
     * Compile the CSRF statements into valid PHP.
     *
     * @return string
     */
    protected function compileCsrf(): string
    {
        return '<?php echo csrf_field(); ?>';
    }

    /**
     * Compile the "dd" statements into valid PHP.
     *
     * @param  string  $arguments
     * @return string
     */
    protected function compileDd(string $arguments): string
    {
        return "<?php dd{$arguments}; ?>";
    }

    /**
     * Compile the "dump" statements into valid PHP.
     *
     * @param  string  $arguments
     * @return string
     */
    protected function compileDump(string $arguments): string
    {
        return "<?php dump{$arguments}; ?>";
    }

    /**
     * Compile the method statements into valid PHP.
     *
     * @param  string  $method
     * @return string
     */
    protected function compileMethod(string $method): string
    {
        return "<?php echo method_field{$method}; ?>";
    }

    /**
     * Compile the "vite" statements into valid PHP.
     *
     * @param  string|null  $arguments
     * @return string
     */
    protected function compileVite(?string $arguments): string
    {
        $arguments ??= '()';

        $class = Vite::class;

        return "<?php echo app('$class'){$arguments}; ?>";
    }

    /**
     * Compile the "viteReactRefresh" statements into valid PHP.
     *
     * @return string
     */
    protected function compileViteReactRefresh(): string
    {
        $class = Vite::class;

        return "<?php echo app('$class')->reactRefresh(); ?>";
    }
}
