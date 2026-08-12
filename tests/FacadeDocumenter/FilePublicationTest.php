<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class FilePublicationTest extends FacadeDocumenterTestCase
{
    /**
     * Preserve facade permissions when publishing a generated docblock.
     */
    public function testPublishedFacadePreservesItsPermissions(): void
    {
        $this->writeAppFile(
            'FilePublication/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\FilePublication;

                class Proxy
                {
                    public function value(): string
                    {
                        return 'value';
                    }
                }
                PHP
        );

        $facadePath = $this->writeAppFile(
            'FilePublication/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\FilePublication;

                /** @see \App\FilePublication\Proxy */
                class Facade
                {
                }
                PHP
        );

        chmod($facadePath, 0640);

        $process = $this->runDocumenter(['App\FilePublication\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString(
            '@method static string value()',
            $this->appFileContents('App\FilePublication\Facade')
        );
        $this->assertSame(0640, fileperms($facadePath) & 0777);
    }
}
