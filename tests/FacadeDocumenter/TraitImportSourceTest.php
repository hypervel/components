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
    public function testMethodInheritedFromTraitResolvesTraitFileImports(): void
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

    public function testMethodInheritedFromNestedTraitResolvesNestedTraitImports(): void
    {
        $this->writeAppFile(
            'TraitImport/Nested/Payload.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\TraitImport\Nested;

                class Payload
                {
                }
                PHP
        );

        $this->writeAppFile(
            'TraitImport/Nested/Traits/InnerTrait.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\TraitImport\Nested\Traits;

                use App\TraitImport\Nested\Payload;

                trait InnerTrait
                {
                    /** @return Payload */
                    public function nestedPayload(): mixed
                    {
                        return new Payload();
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'TraitImport/Nested/Traits/OuterTrait.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\TraitImport\Nested\Traits;

                trait OuterTrait
                {
                    use InnerTrait;
                }
                PHP
        );

        $this->writeAppFile(
            'TraitImport/Nested/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\TraitImport\Nested;

                use App\TraitImport\Nested\Traits\OuterTrait;

                class Proxy
                {
                    use OuterTrait;
                }
                PHP
        );

        $this->writeAppFile(
            'TraitImport/Nested/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\TraitImport\Nested;

                /** @see \App\TraitImport\Nested\Proxy */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\TraitImport\Nested\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString(
            '@method static \App\TraitImport\Nested\Payload nestedPayload()',
            $this->appFileContents('App\TraitImport\Nested\Facade'),
        );
    }

    public function testSameFileTraitImportsResolveFromTheExactSourceRange(): void
    {
        $this->writeAppFile(
            'TraitImport/SameFile/Owner/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\TraitImport\SameFile\First {
                    use DateTimeImmutable as Payload;

                    trait FirstTrait
                    {
                        /** @return Payload */
                        public function first(): mixed
                        {
                            return null;
                        }
                    }
                }

                namespace App\TraitImport\SameFile\Second {
                    use DateTimeZone as Payload;

                    trait SecondTrait
                    {
                        /** @return Payload */
                        public function second(): mixed
                        {
                            return null;
                        }
                    }
                }

                namespace App\TraitImport\SameFile\Owner {
                    use App\TraitImport\SameFile\First\FirstTrait;
                    use App\TraitImport\SameFile\Second\SecondTrait;
                    use stdClass as Payload;

                    class Proxy
                    {
                        use FirstTrait;
                        use SecondTrait;

                        /** @return Payload */
                        public function owner(): mixed
                        {
                            return null;
                        }
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'TraitImport/SameFile/Owner/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\TraitImport\SameFile\Owner;

                /** @see \App\TraitImport\SameFile\Owner\Proxy */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\TraitImport\SameFile\Owner\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\TraitImport\SameFile\Owner\Facade');

        $this->assertStringContainsString('@method static \DateTimeImmutable first()', $contents);
        $this->assertStringContainsString('@method static \stdClass owner()', $contents);
        $this->assertStringContainsString('@method static \DateTimeZone second()', $contents);
    }
}
