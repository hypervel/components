<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class WrapperCollapseTest extends FacadeDocumenterTestCase
{
    public function testClassStringCollapsesToString()
    {
        $this->writeAppFile(
            'WrapperCollapse/ClassString/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\WrapperCollapse\ClassString;

                class Proxy
                {
                    /**
                     * @return class-string<\stdClass>
                     */
                    public function fetchClass(): mixed
                    {
                        return \stdClass::class;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'WrapperCollapse/ClassString/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\WrapperCollapse\ClassString;

                /**
                 * @see \App\WrapperCollapse\ClassString\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\WrapperCollapse\ClassString\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\WrapperCollapse\ClassString\Facade');

        $this->assertStringContainsString('@method static string fetchClass()', $contents);
        $this->assertStringNotContainsString('string<', $contents);
    }

    public function testIntMaskOfCollapsesToInt()
    {
        $this->writeAppFile(
            'WrapperCollapse/IntMaskOf/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\WrapperCollapse\IntMaskOf;

                class Proxy
                {
                    public const int FLAG_A = 1;

                    public const int FLAG_B = 2;

                    /**
                     * @param int-mask-of<Proxy::FLAG_*> $flags
                     */
                    public function configure(mixed $flags): void
                    {
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'WrapperCollapse/IntMaskOf/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\WrapperCollapse\IntMaskOf;

                /**
                 * @see \App\WrapperCollapse\IntMaskOf\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\WrapperCollapse\IntMaskOf\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\WrapperCollapse\IntMaskOf\Facade');

        $this->assertStringContainsString('@method static void configure(int $flags)', $contents);
        $this->assertStringNotContainsString('int<', $contents);
    }

    public function testBoundedIntCollapsesToInt()
    {
        $this->writeAppFile(
            'WrapperCollapse/BoundedInt/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\WrapperCollapse\BoundedInt;

                class Proxy
                {
                    /**
                     * @param int<0, max> $offset
                     */
                    public function seek(mixed $offset): void
                    {
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'WrapperCollapse/BoundedInt/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\WrapperCollapse\BoundedInt;

                /**
                 * @see \App\WrapperCollapse\BoundedInt\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\WrapperCollapse\BoundedInt\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\WrapperCollapse\BoundedInt\Facade');

        $this->assertStringContainsString('@method static void seek(int $offset)', $contents);
        $this->assertStringNotContainsString('int<', $contents);
    }

    public function testListCollapsesToArray()
    {
        $this->writeAppFile(
            'WrapperCollapse/ListType/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\WrapperCollapse\ListType;

                class Proxy
                {
                    /**
                     * @return list<string>
                     */
                    public function tags(): mixed
                    {
                        return [];
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'WrapperCollapse/ListType/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\WrapperCollapse\ListType;

                /**
                 * @see \App\WrapperCollapse\ListType\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\WrapperCollapse\ListType\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\WrapperCollapse\ListType\Facade');

        $this->assertStringContainsString('@method static array tags()', $contents);
        $this->assertStringNotContainsString('list<', $contents);
    }
}
