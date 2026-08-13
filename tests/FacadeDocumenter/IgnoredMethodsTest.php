<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class IgnoredMethodsTest extends FacadeDocumenterTestCase
{
    public function testFacadeMayExcludeProxyMethodsFromGeneratedDocblock(): void
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

    public function testFacadeMayExcludeOneMixinMethodWithoutHidingAProxyMethod(): void
    {
        $this->writeAppFile(
            'IgnoredMethods/Connection.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\IgnoredMethods;

                interface Connection
                {
                    public function transactionLevel(): int;
                }
                PHP
        );

        $this->writeAppFile(
            'IgnoredMethods/Manager.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\IgnoredMethods;

                class Manager
                {
                    public function disconnect(?string $name = null): void
                    {
                    }

                    public function transactionLevel(): int
                    {
                        return 0;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'IgnoredMethods/MixinFacade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\IgnoredMethods;

                /**
                 * @mixin \App\IgnoredMethods\Connection
                 * @see \App\IgnoredMethods\Manager
                 */
                class MixinFacade
                {
                    /**
                     * @return array<int, string>
                     */
                    protected static function ignoredFacadeDocumenterMethods(): array
                    {
                        return ['transactionLevel'];
                    }
                }
                PHP
        );

        $process = $this->runDocumenter(['App\IgnoredMethods\MixinFacade']);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\IgnoredMethods\MixinFacade');

        $this->assertStringContainsString('@method static void disconnect(string|null $name = null)', $contents);
        $this->assertStringNotContainsString('@method static int transactionLevel()', $contents);
    }

    public function testIgnoreHookMustBeStatic(): void
    {
        $this->writeAppFile(
            'IgnoredMethods/NonStaticProxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\IgnoredMethods;

                class NonStaticProxy
                {
                    public function visible(): string
                    {
                        return 'visible';
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'IgnoredMethods/NonStaticFacade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\IgnoredMethods;

                /**
                 * @see \App\IgnoredMethods\NonStaticProxy
                 */
                class NonStaticFacade
                {
                    /**
                     * @return array<int, string>
                     */
                    protected function ignoredFacadeDocumenterMethods(): array
                    {
                        return [];
                    }
                }
                PHP
        );

        $process = $this->runDocumenter(['App\IgnoredMethods\NonStaticFacade']);
        $combined = $process->getOutput() . $process->getErrorOutput();

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString(
            'ReflectionException: Trying to invoke non static method App\IgnoredMethods\NonStaticFacade::ignoredFacadeDocumenterMethods() without an object',
            $combined,
        );
    }
}
