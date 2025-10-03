<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers\Concerns;

use Hypervel\Foundation\Vite;

trait CompilesHelpers
{
    /**
     * Compile the CSRF statements into valid PHP.
     */
    protected function compileCsrf(): string
    {
        return '<?php echo csrf_field(); ?>';
    }

    /**
     * Compile the method statements into valid PHP.
     */
    protected function compileMethod(string $method): string
    {
        return "<?php echo method_field{$method}; ?>";
    }

    /**
     * Compile the "vite" statements into valid PHP.
     */
    protected function compileVite(?string $arguments): string
    {
        $arguments ??= '()';

        $class = Vite::class;

        return "<?php echo app('{$class}'){$arguments}; ?>";
    }
}
