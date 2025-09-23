<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers;

use Hyperf\ViewEngine\Compiler\BladeCompiler as BaseBladeCompiler;
use Hypervel\Mail\Compiler\ComponentTagCompiler;

class BladeCompiler extends BaseBladeCompiler
{
    use Concerns\CompilesHelpers;
    use Concerns\CompilesAuthorization;
    use Concerns\CompilesInjections;
    use Concerns\CompilesJs;
    use Concerns\CompilesSession;
    use Concerns\CompilesUseStatements;

    /**
     * Compile the component tags within the given string.
     */
    protected function compileComponentTags(string $value): string
    {
        if (! $this->compilesComponentTags) {
            return $value;
        }

        return (new ComponentTagCompiler(
            $this->classComponentAliases,
            $this->classComponentNamespaces,
            $this,
            $this->getComponentAutoload() ?: []
        ))->compile($value);
    }
}
