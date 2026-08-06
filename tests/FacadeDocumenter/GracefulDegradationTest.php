<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class GracefulDegradationTest extends FacadeDocumenterTestCase
{
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
