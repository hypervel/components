<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers;

use Hyperf\ViewEngine\Compiler\BladeCompiler as BaseBladeCompiler;

class BladeCompiler extends BaseBladeCompiler
{
    use Concerns\CompilesHelpers;
    use Concerns\CompilesAuthorization;
    use Concerns\CompilesInjections;
    use Concerns\CompilesJs;
    use Concerns\CompilesSession;
    use Concerns\CompilesUseStatements;
}
