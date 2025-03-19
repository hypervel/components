<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers\Concerns;

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
}
