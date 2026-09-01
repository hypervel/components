<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class GenericPreservationTest extends FacadeDocumenterTestCase
{
    public function testArrayWithKeyAndValueTypes(): void
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

    public function testGeneratorWithKeyAndValueTypes(): void
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

    public function testNestedUnionInsideGenericSurvives(): void
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

    /**
     * Preserve balanced union-typed template bounds.
     */
    public function testUnionTypedTemplateBoundRemainsBalanced(): void
    {
        $this->writeAppFile(
            'Generic/UnionTemplate/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Generic\UnionTemplate;

                class Proxy
                {
                    /**
                     * @template TEnum of \BackedEnum
                     * @template TDefault of TEnum|null
                     * @return TEnum|TDefault
                     */
                    public function pickEnum(mixed $key, mixed $enumClass, mixed $default = null)
                    {
                        return $default;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'Generic/UnionTemplate/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Generic\UnionTemplate;

                /**
                 * @see \App\Generic\UnionTemplate\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Generic\UnionTemplate\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\Generic\UnionTemplate\Facade');

        $this->assertStringContainsString(
            '@method static \BackedEnum|null pickEnum(mixed $key, mixed $enumClass, mixed $default = null)',
            $contents
        );
        $this->assertStringNotContainsString('\BackedEnum|(', $contents);
    }

    public function testRefinedArraysPreserveTheirSupportedGenericTypes(): void
    {
        $this->writeAppFile(
            'Generic/RefinedArrays/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Generic\RefinedArrays;

                class Proxy
                {
                    /** @return non-empty-list<string> */
                    public function nonEmptyList(): mixed { return null; }

                    /** @return non-empty-array<string> */
                    public function valueArray(): mixed { return null; }

                    /** @return non-empty-array<string, int> */
                    public function keyedArray(): mixed { return null; }

                    /** @return non-empty-list<non-empty-string|positive-int> */
                    public function nestedUnion(): mixed { return null; }

                    /** @return non-empty-array<string, non-empty-list<positive-int>> */
                    public function nestedGeneric(): mixed { return null; }

                    /** @return non-empty-list<string>&\Countable */
                    public function intersection(): mixed { return null; }

                    /** @return UnknownContainer<string> */
                    public function unknownGeneric(): mixed { return null; }
                }
                PHP
        );

        $this->writeAppFile(
            'Generic/RefinedArrays/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Generic\RefinedArrays;

                /** @see \App\Generic\RefinedArrays\Proxy */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\Generic\RefinedArrays\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\Generic\RefinedArrays\Facade');

        $this->assertStringContainsString('@method static array<int, string> nonEmptyList()', $contents);
        $this->assertStringContainsString('@method static array<string> valueArray()', $contents);
        $this->assertStringContainsString('@method static array<string, int> keyedArray()', $contents);
        $this->assertStringContainsString('@method static array<int, string|int> nestedUnion()', $contents);
        $this->assertStringContainsString('@method static array<string, array<int, int>> nestedGeneric()', $contents);
        $this->assertStringContainsString('@method static array<int, string>&\Countable intersection()', $contents);
        $this->assertStringContainsString('@method static mixed unknownGeneric()', $contents);
        $this->assertStringNotContainsString('mixed<', $contents);
    }
}
