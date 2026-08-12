<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class ImportResolutionTest extends FacadeDocumenterTestCase
{
    public function testImportedClassShortNameInSeeResolves(): void
    {
        $this->writeAppFile(
            'Imports/Imported/Real/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\Imported\Real;

                class Proxy
                {
                    public function ping(): string
                    {
                        return 'pong';
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Imports/Imported/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\Imported;

                use App\Imports\Imported\Real\Proxy;

                /**
                 * @see Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Imports\Imported\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\Imports\Imported\Facade');

        $this->assertStringContainsString('@method static string ping()', $contents);
    }

    public function testAliasedImportInSeeResolves(): void
    {
        $this->writeAppFile(
            'Imports/Aliased/Real/RealProxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\Aliased\Real;

                class RealProxy
                {
                    public function announce(): string
                    {
                        return 'hi';
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Imports/Aliased/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\Aliased;

                use App\Imports\Aliased\Real\RealProxy as AliasedProxy;

                /**
                 * @see AliasedProxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Imports\Aliased\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\Imports\Aliased\Facade');

        $this->assertStringContainsString('@method static string announce()', $contents);
    }

    public function testSameNamespaceUnqualifiedSeeResolves(): void
    {
        $this->writeAppFile(
            'Imports/SameNamespace/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\SameNamespace;

                class Proxy
                {
                    public function greet(): string
                    {
                        return 'hello';
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Imports/SameNamespace/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\SameNamespace;

                /**
                 * @see Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Imports\SameNamespace\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\Imports\SameNamespace\Facade');

        $this->assertStringContainsString('@method static string greet()', $contents);
    }

    public function testImportedInterfaceInSeeResolves(): void
    {
        $this->writeAppFile(
            'Imports/InterfaceImport/Real/Contract.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\InterfaceImport\Real;

                interface Contract
                {
                    public function describe(): string;
                }
                PHP
        );

        $this->writeAppFile(
            'Imports/InterfaceImport/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\InterfaceImport;

                use App\Imports\InterfaceImport\Real\Contract;

                /**
                 * @see Contract
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Imports\InterfaceImport\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\Imports\InterfaceImport\Facade');

        $this->assertStringContainsString('@method static string describe()', $contents);
    }

    /**
     * Resolve standard namespace-level class import forms.
     */
    public function testResolvesNamespaceImportGrammar(): void
    {
        $this->writeAppFile(
            'Imports/Grammar/Types/Class_With_Underscore.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\Grammar\Types;

                class Class_With_Underscore
                {
                }
                PHP
        );

        $this->writeAppFile(
            'Imports/Grammar/value.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\Grammar;

                class value
                {
                }
                PHP
        );

        $this->writeAppFile(
            'Imports/Grammar/HYPERVEL_START.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\Grammar;

                class HYPERVEL_START
                {
                }
                PHP
        );

        $this->writeAppFile(
            'Imports/Grammar/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\Grammar;

                use App\Imports\Grammar\Types\Class_With_Underscore;
                use DateTimeImmutable, DateTimeZone;
                use Hypervel\Contracts\Support\{
                    Arrayable as ArrayContract,
                    Htmlable
                };
                use Hypervel\Support as Support;
                use Hypervel\Support\{Collection as MixedCollection, function value, const HYPERVEL_START};
                use function Hypervel\Support\str;
                use const PHP_VERSION;

                class Proxy
                {
                    /** @return Class_With_Underscore */
                    public function underscored(): mixed
                    {
                        return null;
                    }

                    /** @return DateTimeImmutable */
                    public function firstCommaImport(): mixed
                    {
                        return null;
                    }

                    /** @return DateTimeZone */
                    public function secondCommaImport(): mixed
                    {
                        return null;
                    }

                    /** @return ArrayContract */
                    public function groupedAlias(): mixed
                    {
                        return null;
                    }

                    /** @return Htmlable */
                    public function multilineGroup(): mixed
                    {
                        return null;
                    }

                    /** @return Support\Collection */
                    public function qualifiedAlias(): mixed
                    {
                        return null;
                    }

                    /** @return MixedCollection */
                    public function mixedGroupClass(): mixed
                    {
                        return null;
                    }

                    /** @return value */
                    public function ignoredFunctionImport(): mixed
                    {
                        return null;
                    }

                    /** @return HYPERVEL_START */
                    public function ignoredConstantImport(): mixed
                    {
                        return null;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Imports/Grammar/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\Grammar;

                /**
                 * @see \App\Imports\Grammar\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Imports\Grammar\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\Imports\Grammar\Facade');

        $this->assertStringContainsString('@method static \App\Imports\Grammar\Types\Class_With_Underscore underscored()', $contents);
        $this->assertStringContainsString('@method static \DateTimeImmutable firstCommaImport()', $contents);
        $this->assertStringContainsString('@method static \DateTimeZone secondCommaImport()', $contents);
        $this->assertStringContainsString('@method static \Hypervel\Contracts\Support\Arrayable groupedAlias()', $contents);
        $this->assertStringContainsString('@method static \Hypervel\Contracts\Support\Htmlable multilineGroup()', $contents);
        $this->assertStringContainsString('@method static \Hypervel\Support\Collection qualifiedAlias()', $contents);
        $this->assertStringContainsString('@method static \Hypervel\Support\Collection mixedGroupClass()', $contents);
        $this->assertStringContainsString('@method static \App\Imports\Grammar\value ignoredFunctionImport()', $contents);
        $this->assertStringContainsString('@method static \App\Imports\Grammar\HYPERVEL_START ignoredConstantImport()', $contents);
    }

    /**
     * Resolve imports inside a bracketed namespace.
     */
    public function testResolvesBracketedNamespaceImports(): void
    {
        $this->writeAppFile(
            'Imports/Bracketed/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\Bracketed {
                    use DateTimeImmutable as Clock;

                    class Proxy
                    {
                        /** @return Clock */
                        public function clock(): mixed
                        {
                            return null;
                        }
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Imports/Bracketed/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\Bracketed;

                /** @see \App\Imports\Bracketed\Proxy */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Imports\Bracketed\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString(
            '@method static \DateTimeImmutable clock()',
            $this->appFileContents('App\Imports\Bracketed\Facade')
        );
    }

    /**
     * Resolve imports from files with CRLF line endings.
     */
    public function testResolvesCrLfImports(): void
    {
        $proxy = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Imports\CrLf;

            use DateTimeImmutable as Clock;

            class Proxy
            {
                /** @return Clock */
                public function clock(): mixed
                {
                    return null;
                }
            }
            PHP;

        $this->writeAppFile('Imports/CrLf/Proxy.php', str_replace("\n", "\r\n", $proxy));

        $this->writeAppFile(
            'Imports/CrLf/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\CrLf;

                /** @see \App\Imports\CrLf\Proxy */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Imports\CrLf\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString(
            '@method static \DateTimeImmutable clock()',
            $this->appFileContents('App\Imports\CrLf\Facade')
        );
    }

    /**
     * Ignore closure captures while scanning namespace imports.
     */
    public function testSkipsClosureCaptureUse(): void
    {
        $this->writeAppFile(
            'Imports/ClosureCapture/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\ClosureCapture;

                use DateTimeImmutable as Clock;

                $captured = null;
                $callback = static function () use ($captured): void {
                };

                class Proxy
                {
                    /** @return Clock */
                    public function clock(): mixed
                    {
                        return null;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Imports/ClosureCapture/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\ClosureCapture;

                /** @see \App\Imports\ClosureCapture\Proxy */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Imports\ClosureCapture\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString(
            '@method static \DateTimeImmutable clock()',
            $this->appFileContents('App\Imports\ClosureCapture\Facade')
        );
    }

    /**
     * Ignore trait imports in classes preceding the reflected class.
     */
    public function testSkipsPrecedingClassTraitImport(): void
    {
        $this->writeAppFile(
            'Imports/TraitUse/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\TraitUse;

                use DateTimeImmutable as Clock;

                trait ExampleTrait
                {
                }

                class BeforeProxy
                {
                    use ExampleTrait;
                }

                class Proxy
                {
                    /** @return Clock */
                    public function clock(): mixed
                    {
                        return null;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Imports/TraitUse/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\TraitUse;

                /** @see \App\Imports\TraitUse\Proxy */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Imports\TraitUse\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString(
            '@method static \DateTimeImmutable clock()',
            $this->appFileContents('App\Imports\TraitUse\Facade')
        );
    }

    /**
     * Preserve namespace depth across interpolated strings.
     */
    public function testInterpolatedStringsDoNotCorruptImportDepth(): void
    {
        $this->writeAppFile(
            'Imports/Interpolated/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\Interpolated;

                class BeforeProxy
                {
                    public function interpolate(string $value, string $name): string
                    {
                        // Deprecated syntax is intentional: it is the only form that emits T_DOLLAR_OPEN_CURLY_BRACES.
                        return "{$value} ${$name}";
                    }
                }

                use DateTimeImmutable as Clock;

                class Proxy
                {
                    /** @return Clock */
                    public function clock(): mixed
                    {
                        return null;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Imports/Interpolated/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\Interpolated;

                /** @see \App\Imports\Interpolated\Proxy */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Imports\Interpolated\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString(
            '@method static \DateTimeImmutable clock()',
            $this->appFileContents('App\Imports\Interpolated\Facade')
        );
    }

    /**
     * Resolve aliases case-insensitively while preserving declared class casing.
     */
    public function testCanonicalizesResolvedClassNames(): void
    {
        $this->writeAppFile(
            'Imports/Canonical/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\Canonical;

                use DateTimeImmutable as ClockValue;

                class PayloadValue
                {
                }

                class Countable
                {
                }

                class Proxy
                {
                    /** @return clockvalue */
                    public function importedAlias(): mixed
                    {
                        return null;
                    }

                    /** @return payloadvalue */
                    public function sameNamespace(): mixed
                    {
                        return null;
                    }

                    /** @return Countable */
                    public function shadowsGlobal(): mixed
                    {
                        return null;
                    }

                    /** @return Closure */
                    public function globalFallback(): mixed
                    {
                        return null;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Imports/Canonical/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\Canonical;

                /** @see \App\Imports\Canonical\Proxy */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Imports\Canonical\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\Imports\Canonical\Facade');

        $this->assertStringContainsString('@method static \DateTimeImmutable importedAlias()', $contents);
        $this->assertStringContainsString('@method static \App\Imports\Canonical\PayloadValue sameNamespace()', $contents);
        $this->assertStringContainsString('@method static \App\Imports\Canonical\Countable shadowsGlobal()', $contents);
        $this->assertStringContainsString('@method static \Closure globalFallback()', $contents);
    }

    /**
     * Preserve facade import alias casing when shortening global types.
     */
    public function testPreservesFacadeImportAliasCasing(): void
    {
        $this->writeAppFile(
            'Imports/AliasCasing/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\AliasCasing;

                class Proxy
                {
                    public function callback(): \Closure
                    {
                        return static function (): void {
                        };
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Imports/AliasCasing/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\AliasCasing;

                use Closure as CallbackAlias;

                /** @see \App\Imports\AliasCasing\Proxy */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Imports\AliasCasing\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString(
            '@method static CallbackAlias callback()',
            $this->appFileContents('App\Imports\AliasCasing\Facade')
        );
    }

    /**
     * Report the imported class when an aliased proxy is missing.
     */
    public function testMissingImportedSeeReportsImportedClass(): void
    {
        $this->writeAppFile(
            'Imports/MissingImported/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\MissingImported;

                use Vendor\Missing\Proxy as MissingProxy;

                /** @see MissingProxy */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Imports\MissingImported\Facade']);

        $this->assertSame(1, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString(
            'Class "Vendor\Missing\Proxy" does not exist',
            $process->getErrorOutput() . $process->getOutput()
        );
    }

    /**
     * Report the source-namespaced class when an unqualified proxy is missing.
     */
    public function testMissingUnqualifiedSeeReportsNamespacedClass(): void
    {
        $this->writeAppFile(
            'Imports/MissingUnqualified/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Imports\MissingUnqualified;

                /** @see MissingProxy */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Imports\MissingUnqualified\Facade']);

        $this->assertSame(1, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString(
            'Class "App\Imports\MissingUnqualified\MissingProxy" does not exist',
            $process->getErrorOutput() . $process->getOutput()
        );
    }
}
