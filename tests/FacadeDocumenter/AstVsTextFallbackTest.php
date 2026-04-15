<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class AstVsTextFallbackTest extends FacadeDocumenterTestCase
{
    /**
     * When the proxy class defines __construct, resolveDocMethods() takes the
     * AST path: @method tag types flow through resolveDocblockTypes() and are
     * normalised (e.g. class-string<\stdClass> collapses to string).
     */
    public function testAstPathNormalisesMethodTagTypes()
    {
        $this->writeAppFile(
            'AstFallback/AstPath/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\AstFallback\AstPath;

                /**
                 * @method static string fetch(class-string<\stdClass> $class)
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
            'AstFallback/AstPath/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\AstFallback\AstPath;

                /**
                 * @see \App\AstFallback\AstPath\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\AstFallback\AstPath\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\AstFallback\AstPath\Facade');

        $this->assertStringContainsString('@method static string fetch(string $class)', $contents);
        $this->assertStringNotContainsString('class-string<', $contents);
    }

    /**
     * When the proxy class has no __construct, reflection on '__construct'
     * throws ReflectionException, the try/catch kicks in, and the text-scrape
     * fallback emits the @method tag verbatim. The test asserts the tool
     * still produces output (no crash) even though types are not normalised.
     */
    public function testFallbackPathEmitsTagVerbatimWithoutCrashing()
    {
        $this->writeAppFile(
            'AstFallback/FallbackPath/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\AstFallback\FallbackPath;

                /**
                 * @method static string fetch(class-string<\stdClass> $class)
                 */
                class Proxy
                {
                    // Intentionally no __construct defined.
                }
                PHP
        );

        $this->writeAppFile(
            'AstFallback/FallbackPath/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\AstFallback\FallbackPath;

                /**
                 * @see \App\AstFallback\FallbackPath\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\AstFallback\FallbackPath\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\AstFallback\FallbackPath\Facade');

        // The fallback does not normalise types, so the tag is emitted
        // verbatim (modulo Str::squish whitespace normalisation).
        $this->assertStringContainsString('@method static string fetch(class-string<\stdClass> $class)', $contents);
    }
}
