<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class TraitImportSourceTest extends FacadeDocumenterTestCase
{
    /**
     * When a proxy method lives in a trait whose file imports types the proxy's
     * own file does not, docblock type resolution must walk the trait's use
     * statements — not the declaring class's — to resolve short names.
     */
    public function testMethodInheritedFromTraitResolvesTraitFileImports()
    {
        $this->writeAppFile(
            'TraitImport/Other/Payload.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\TraitImport\Other;

                class Payload
                {
                }
                PHP
        );

        $this->writeAppFile(
            'TraitImport/ProxySide/PayloadTrait.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\TraitImport\ProxySide;

                use App\TraitImport\Other\Payload;

                trait PayloadTrait
                {
                    /**
                     * @return Payload
                     */
                    public function payload(): mixed
                    {
                        return new Payload();
                    }
                }
                PHP
        );

        // Deliberately omit `use App\TraitImport\Other\Payload;` from the
        // Proxy file so the only working resolution path is via the trait.
        $this->writeAppFile(
            'TraitImport/ProxySide/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\TraitImport\ProxySide;

                class Proxy
                {
                    use PayloadTrait;
                }
                PHP
        );

        $this->writeAppFile(
            'TraitImport/ProxySide/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\TraitImport\ProxySide;

                /**
                 * @see \App\TraitImport\ProxySide\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\TraitImport\ProxySide\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\TraitImport\ProxySide\Facade');

        // The short name "Payload" in the trait's @return resolves to
        // App\TraitImport\Other\Payload ONLY if the trait file's imports are
        // used. If the declaring class's file were scanned instead, Payload
        // would not be resolvable and the output would differ.
        $this->assertStringContainsString('@method static \App\TraitImport\Other\Payload payload()', $contents);
    }
}
