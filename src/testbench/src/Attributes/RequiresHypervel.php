<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Attributes;

use Attribute;
use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Contracts\Attributes\Actionable;

use function Hypervel\Testbench\hypervel_version_compare;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class RequiresHypervel implements Actionable
{
    public function __construct(
        public readonly string $versionRequirement
    ) {
    }

    /**
     * @param Closure(string, array<int, mixed>):void $action
     */
    public function handle(ApplicationContract $app, Closure $action): mixed
    {
        if (preg_match('/(?P<operator>[<>=!]{0,2})\s*(?P<version>[\d\.-]+(dev|(RC|alpha|beta)[\d\.])?)[ \t]*\r?$/m', $this->versionRequirement, $matches)) {
            if (empty($matches['operator'])) {
                $matches['operator'] = '>=';
            }

            if (! hypervel_version_compare($matches['version'], $matches['operator'])) {
                \call_user_func($action, 'markTestSkipped', ["Requires Hypervel Framework:{$this->versionRequirement}"]);
            }
        }

        return null;
    }
}
