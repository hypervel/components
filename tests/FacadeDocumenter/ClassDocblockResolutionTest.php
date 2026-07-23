<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class ClassDocblockResolutionTest extends FacadeDocumenterTestCase
{
    /**
     * Resolve magic method types for a class with a constructor.
     */
    public function testResolvesMagicMethodTypesForClassWithConstructor(): void
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
     * Resolve magic method types against a constructor-less owner.
     */
    public function testResolvesMagicMethodTypesForClassWithoutConstructor(): void
    {
        $this->writeAppFile(
            'AstFallback/FallbackPath/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\AstFallback\FallbackPath;

                use DateTimeImmutable as Payload;

                /**
                 * @method static Payload fetch(class-string<Payload> $class)
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

        $this->assertStringContainsString('@method static \DateTimeImmutable fetch(string $class)', $contents);
        $this->assertStringNotContainsString('class-string<', $contents);
    }

    /**
     * Resolve magic method types against the child that owns the docblock.
     */
    public function testResolvesMagicMethodTypesAgainstChildWithInheritedConstructor(): void
    {
        $this->writeAppFile(
            'ClassDocblock/Inherited/ParentProxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ClassDocblock\Inherited;

                use stdClass as Payload;

                class ParentProxy
                {
                    public function __construct()
                    {
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'ClassDocblock/Inherited/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ClassDocblock\Inherited;

                use DateTimeImmutable as Payload;

                /**
                 * @method static Payload fetch()
                 */
                class Proxy extends ParentProxy
                {
                }
                PHP
        );

        $this->writeAppFile(
            'ClassDocblock/Inherited/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ClassDocblock\Inherited;

                /**
                 * @see \App\ClassDocblock\Inherited\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\ClassDocblock\Inherited\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\ClassDocblock\Inherited\Facade');

        $this->assertStringContainsString('@method static \DateTimeImmutable fetch()', $contents);
        $this->assertStringNotContainsString('@method static \stdClass fetch()', $contents);
    }

    /**
     * Use facade imports only for global method types managed by the formatter.
     */
    public function testUsesFacadeImportsOnlyForGlobalMethodTypes(): void
    {
        $this->writeAppFile(
            'ClassDocblock/FacadeImports/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ClassDocblock\FacadeImports;

                use DateTimeImmutable as OwnerPayload;
                use Hypervel\Support\Collection;

                /**
                 * @method static OwnerPayload transform(OwnerPayload $value)
                 * @method static \stdClass unimported()
                 * @method static Collection namespaced()
                 * @method static \App\ClassDocblock\FacadeImports\DateTimeImmutable collidingBasename()
                 */
                class Proxy
                {
                }
                PHP
        );

        $this->writeAppFile(
            'ClassDocblock/FacadeImports/DateTimeImmutable.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ClassDocblock\FacadeImports;

                class DateTimeImmutable
                {
                }
                PHP
        );

        $this->writeAppFile(
            'ClassDocblock/FacadeImports/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ClassDocblock\FacadeImports;

                use DateTimeImmutable as Payload;
                use Hypervel\Support\Collection as ImportedCollection;

                /**
                 * @see \App\ClassDocblock\FacadeImports\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\ClassDocblock\FacadeImports\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\ClassDocblock\FacadeImports\Facade');

        $this->assertStringContainsString('@method static Payload transform(Payload $value)', $contents);
        $this->assertStringContainsString('@method static \stdClass unimported()', $contents);
        $this->assertStringContainsString('@method static \Hypervel\Support\Collection namespaced()', $contents);
        $this->assertStringContainsString('@method static \App\ClassDocblock\FacadeImports\DateTimeImmutable collidingBasename()', $contents);
        $this->assertStringNotContainsString('@method static \App\ClassDocblock\FacadeImports\Payload collidingBasename()', $contents);
        $this->assertStringNotContainsString('@method static ImportedCollection namespaced()', $contents);
    }
}
