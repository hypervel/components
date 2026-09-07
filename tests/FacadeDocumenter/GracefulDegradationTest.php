<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class GracefulDegradationTest extends FacadeDocumenterTestCase
{
    public function testPrototypeTypeResolutionErrorsAreNotTreatedAsMissingPrototypes(): void
    {
        $this->writeAppFile(
            'Degradation/PrototypeFailure/NeverLoads.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Degradation\PrototypeFailure;

                class NeverLoads extends NeverInstalledParent
                {
                }
                PHP
        );

        $this->writeAppFile(
            'Degradation/PrototypeFailure/ParentProxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Degradation\PrototypeFailure;

                class ParentProxy
                {
                    /**
                     * @param NeverLoads $value
                     */
                    public function transform(string $value): string
                    {
                        return $value;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Degradation/PrototypeFailure/ChildProxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Degradation\PrototypeFailure;

                class ChildProxy extends ParentProxy
                {
                    public function transform(string $value): string
                    {
                        return $value;
                    }
                }
                PHP
        );

        $facadeContents = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Degradation\PrototypeFailure;

            /**
             * @see \App\Degradation\PrototypeFailure\ChildProxy
             */
            class Facade
            {
            }
            PHP;

        $facadePath = $this->writeAppFile(
            'Degradation/PrototypeFailure/Facade.php',
            $facadeContents,
        );

        $process = $this->runDocumenter(['App\Degradation\PrototypeFailure\Facade']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString(
            'Class "App\Degradation\PrototypeFailure\NeverInstalledParent" not found',
            $process->getErrorOutput(),
        );
        $this->assertStringNotContainsString('NeverInstalledParent', $process->getOutput());
        $this->assertSame($facadeContents, $this->filesystem->get($facadePath));
    }

    public function testMethodWithoutPrototypeFallsBackToNativeParameterType(): void
    {
        $this->writeAppFile(
            'Degradation/MissingPrototype/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Degradation\MissingPrototype;

                class Proxy
                {
                    public function transform(string $value): string
                    {
                        return $value;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Degradation/MissingPrototype/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Degradation\MissingPrototype;

                /**
                 * @see \App\Degradation\MissingPrototype\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Degradation\MissingPrototype\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString(
            '@method static string transform(string $value)',
            $this->appFileContents('App\Degradation\MissingPrototype\Facade'),
        );
    }

    public function testFacadeWithUnresolvableSeeExitsNonZeroAndReportsMissingClass(): void
    {
        $this->writeAppFile(
            'Degradation/UnresolvableSee/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Degradation\UnresolvableSee;

                /**
                 * @see \App\Degradation\UnresolvableSee\DoesNotExist
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Degradation\UnresolvableSee\Facade']);

        $this->assertNotSame(0, $process->getExitCode(), 'Should fail when @see target does not exist');

        $combined = $process->getOutput() . $process->getErrorOutput();

        $this->assertStringContainsString(
            'ReflectionException: Class "App\Degradation\UnresolvableSee\DoesNotExist" does not exist',
            $combined,
        );
    }

    public function testUnresolvableTypeWarningsUseStderrWhileProgressUsesStdout(): void
    {
        $this->writeAppFile(
            'Degradation/UnresolvableType/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Degradation\UnresolvableType;

                class Proxy
                {
                    /**
                     * @return object{value: string}
                     */
                    public function value(): object
                    {
                        return new \stdClass;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Degradation/UnresolvableType/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Degradation\UnresolvableType;

                /**
                 * @see \App\Degradation\UnresolvableType\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Degradation\UnresolvableType\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString(
            'Unknown type node [PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode]',
            $process->getErrorOutput(),
        );
        $this->assertStringNotContainsString('Updating docblock', $process->getErrorOutput());
        $this->assertStringContainsString(
            'Updating docblock for [App\Degradation\UnresolvableType\Facade].',
            $process->getOutput(),
        );
        $this->assertStringContainsString('@method static object value()', $this->appFileContents(
            'App\Degradation\UnresolvableType\Facade'
        ));
    }

    public function testMissingConstantFetchFallsBackToMixed(): void
    {
        $this->writeAppFile(
            'Degradation/MissingConstant/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Degradation\MissingConstant;

                class Proxy
                {
                    public const int REAL_CONST = 42;

                    /**
                     * @return Proxy::DOES_NOT_EXIST
                     */
                    public function value(): mixed
                    {
                        return null;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Degradation/MissingConstant/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Degradation\MissingConstant;

                /**
                 * @see \App\Degradation\MissingConstant\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Degradation\MissingConstant\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\Degradation\MissingConstant\Facade');

        $this->assertStringContainsString('@method static mixed value()', $contents);
    }

    public function testKeyOfAgainstNonArrayConstantFallsBackToMixed(): void
    {
        $this->writeAppFile(
            'Degradation/NonArrayKeyOf/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Degradation\NonArrayKeyOf;

                class Proxy
                {
                    public const int SCALAR_CONST = 42;

                    /**
                     * @return key-of<Proxy::SCALAR_CONST>
                     */
                    public function label(): mixed
                    {
                        return null;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Degradation/NonArrayKeyOf/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Degradation\NonArrayKeyOf;

                /**
                 * @see \App\Degradation\NonArrayKeyOf\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Degradation\NonArrayKeyOf\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\Degradation\NonArrayKeyOf\Facade');

        $this->assertStringContainsString('@method static mixed label()', $contents);
    }
}
