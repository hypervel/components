<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class DynamicParameterTest extends FacadeDocumenterTestCase
{
    /**
     * Render trailing parameters declared only in PHPDoc.
     */
    public function testRendersTrailingPhpDocParameter(): void
    {
        $this->writeAppFile(
            'DynamicParameters/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\DynamicParameters;

                class Proxy
                {
                    /**
                     * @param string $name
                     * @param int $extra
                     */
                    public function transform(string $name): string
                    {
                        return $name;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'DynamicParameters/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\DynamicParameters;

                /**
                 * @see \App\DynamicParameters\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\DynamicParameters\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString(
            '@method static string transform(string $name, int $extra = null)',
            $this->appFileContents('App\DynamicParameters\Facade')
        );
    }
}
