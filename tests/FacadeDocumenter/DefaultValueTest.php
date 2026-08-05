<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class DefaultValueTest extends FacadeDocumenterTestCase
{
    /**
     * Render string defaults without changing their values.
     */
    public function testStringDefaultsAreRenderedWithoutCorruption(): void
    {
        $this->writeAppFile(
            'DefaultValues/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\DefaultValues;

                class Proxy
                {
                    public function newline(string $value = PHP_EOL): string
                    {
                        return $value;
                    }

                    public function apostrophe(string $value = "don't"): string
                    {
                        return $value;
                    }

                    public function doubleQuote(string $value = 'a"b'): string
                    {
                        return $value;
                    }

                    public function nonAscii(string $value = 'hé'): string
                    {
                        return $value;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'DefaultValues/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\DefaultValues;

                /**
                 * @see \App\DefaultValues\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\DefaultValues\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\DefaultValues\Facade');

        $this->assertStringContainsString(
            <<<'PHP'
                @method static string newline(string $value = "\n")
                PHP,
            $contents,
        );
        $this->assertStringContainsString(
            <<<'PHP'
                @method static string apostrophe(string $value = 'don\'t')
                PHP,
            $contents,
        );
        $this->assertStringContainsString(
            <<<'PHP'
                @method static string doubleQuote(string $value = 'a"b')
                PHP,
            $contents,
        );
        $this->assertStringContainsString(
            <<<'PHP'
                @method static string nonAscii(string $value = 'hé')
                PHP,
            $contents,
        );
    }
}
