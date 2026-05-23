<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class IgnoredMethodsTest extends FacadeDocumenterTestCase
{
    public function testFacadeMayExcludeProxyMethodsFromGeneratedDocblock()
    {
        $this->writeAppFile(
            'IgnoredMethods/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\IgnoredMethods;

                class Proxy
                {
                    public function visible(): string
                    {
                        return 'visible';
                    }

                    public function hidden(): string
                    {
                        return 'hidden';
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'IgnoredMethods/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\IgnoredMethods;

                /**
                 * @see \App\IgnoredMethods\Proxy
                 */
                class Facade
                {
                    /**
                     * @return array<int, string>
                     */
                    protected static function ignoredFacadeDocumenterMethods(): array
                    {
                        return ['hidden'];
                    }
                }
                PHP
        );

        $process = $this->runDocumenter(['App\IgnoredMethods\Facade']);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\IgnoredMethods\Facade');

        $this->assertStringContainsString('@method static string visible()', $contents);
        $this->assertStringNotContainsString('@method static string hidden()', $contents);
    }
}
