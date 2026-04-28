<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class CaseInsensitiveDedupeTest extends FacadeDocumenterTestCase
{
    /**
     * PHP method names are case-insensitive. When a proxy's @method tags (or
     * methods pulled via @mixin) include the same name in different casings
     * — e.g. Redis's "hscan" + "hScan", "setnx" + "setNx" — the documenter
     * must collapse them to a single @method line. Otherwise the facade
     * declares duplicate methods that phpstan and IDEs will flag as
     * collisions.
     */
    public function testSameMethodInDifferentCasingsIsDedupedToOne()
    {
        $this->writeAppFile(
            'CaseDedupe/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\CaseDedupe;

                /**
                 * @method int doFoo(int $x)
                 * @method int DOFOO(int $x)
                 */
                class Proxy
                {
                    public function __construct()
                    {
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'CaseDedupe/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\CaseDedupe;

                /**
                 * @see \App\CaseDedupe\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\CaseDedupe\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\CaseDedupe\Facade');

        // Exactly one @method line referencing either casing — count all
        // case-insensitive occurrences of the method name in @method lines.
        preg_match_all('/@method\s[^\n]*\b(doFoo|DOFOO)\s*\(/i', $contents, $matches);

        $this->assertCount(
            1,
            $matches[0],
            'Expected exactly one @method line for doFoo (case-insensitive), got: ' . implode(', ', $matches[0])
        );
    }
}
