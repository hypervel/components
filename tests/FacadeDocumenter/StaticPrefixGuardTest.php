<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class StaticPrefixGuardTest extends FacadeDocumenterTestCase
{
    /**
     * When the proxy's @method tag is explicitly static, the AST-based
     * resolveDocMethods() emits a string that already starts with "static ",
     * and the render loop's guard prevents the facade from producing an
     * invalid "@method static static …" line.
     */
    public function testStaticMethodTagIsNotDoublePrefixed()
    {
        $this->writeAppFile(
            'StaticGuard/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\StaticGuard;

                /**
                 * @method static int countAll()
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
            'StaticGuard/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\StaticGuard;

                /**
                 * @see \App\StaticGuard\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\StaticGuard\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\StaticGuard\Facade');

        $this->assertStringContainsString('@method static int countAll()', $contents);
        $this->assertStringNotContainsString('static static', $contents);
    }
}
