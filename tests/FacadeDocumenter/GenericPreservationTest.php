<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class GenericPreservationTest extends FacadeDocumenterTestCase
{
    public function testArrayWithKeyAndValueTypes()
    {
        $this->writeAppFile(
            'Generic/AssocArray/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Generic\AssocArray;

                class Proxy
                {
                    /**
                     * @return array<string, bool>
                     */
                    public function flags(): array
                    {
                        return [];
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Generic/AssocArray/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Generic\AssocArray;

                /**
                 * @see \App\Generic\AssocArray\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Generic\AssocArray\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\Generic\AssocArray\Facade');

        $this->assertStringContainsString('@method static array<string, bool> flags()', $contents);
    }

    public function testGeneratorWithKeyAndValueTypes()
    {
        $this->writeAppFile(
            'Generic/Generator/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Generic\Generator;

                class Proxy
                {
                    /**
                     * @return \Generator<int, \stdClass>
                     */
                    public function cursor(): \Generator
                    {
                        yield;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Generic/Generator/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Generic\Generator;

                /**
                 * @see \App\Generic\Generator\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Generic\Generator\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\Generic\Generator\Facade');

        $this->assertStringContainsString('@method static \Generator<int, \stdClass> cursor()', $contents);
    }

    public function testNestedUnionInsideGenericSurvives()
    {
        $this->writeAppFile(
            'Generic/NestedUnion/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Generic\NestedUnion;

                class Proxy
                {
                    /**
                     * @param array<int, int|string> $parameters
                     */
                    public function accept(array $parameters): void
                    {
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Generic/NestedUnion/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Generic\NestedUnion;

                /**
                 * @see \App\Generic\NestedUnion\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Generic\NestedUnion\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\Generic\NestedUnion\Facade');

        $this->assertStringContainsString('@method static void accept(array<int, int|string> $parameters)', $contents);
    }
}
