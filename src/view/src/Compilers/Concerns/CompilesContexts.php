<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers\Concerns;

trait CompilesContexts
{
    /**
     * Compile the context statements into valid PHP.
     */
    protected function compileContext(string $expression): string
    {
        $expression = $this->stripParentheses($expression);

        return '<?php $__contextArgs = [' . $expression . '];
if (context()->has($__contextArgs[0])) :
if (isset($value)) { $__contextPrevious[] = $value; }
$value = context()->get($__contextArgs[0]); ?>';
    }

    /**
     * Compile the endcontext statements into valid PHP.
     */
    protected function compileEndcontext(?string $expression): string
    {
        return '<?php unset($value);
if (isset($__contextPrevious) && !empty($__contextPrevious)) { $value = array_pop($__contextPrevious); }
if (isset($__contextPrevious) && empty($__contextPrevious)) { unset($__contextPrevious); }
endif;
unset($__contextArgs); ?>';
    }
}
