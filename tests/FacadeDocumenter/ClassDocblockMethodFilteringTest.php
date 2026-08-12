<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class ClassDocblockMethodFilteringTest extends FacadeDocumenterTestCase
{
    /**
     * Filter class-level methods by their structured metadata.
     */
    public function testFiltersAndDeduplicatesClassDocblockMethods(): void
    {
        $this->writeAppFile(
            'ClassDocblockFiltering/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ClassDocblockFiltering;

                /**
                 * @method static int explicitStatic()
                 * @method string implicitStatic()
                 * @method ($value is \stdClass ? int : string) conditionalMethod(mixed $value)
                 * @method void __magicMethod()
                 * @method string FACADECONFLICT()
                 * @method string ignoredMethod()
                 * @method int duplicateName()
                 * @method int DUPLICATENAME()
                 */
                class Proxy
                {
                }
                PHP
        );

        $this->writeAppFile(
            'ClassDocblockFiltering/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\ClassDocblockFiltering;

                /**
                 * @see \App\ClassDocblockFiltering\Proxy
                 */
                class Facade
                {
                    public static function facadeConflict(): void
                    {
                    }

                    /**
                     * @return array<int, string>
                     */
                    protected static function ignoredFacadeDocumenterMethods(): array
                    {
                        return ['ignoredMethod'];
                    }
                }
                PHP
        );

        $process = $this->runDocumenter(['App\ClassDocblockFiltering\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\ClassDocblockFiltering\Facade');
        $this->assertSame(1, preg_match('/\/\*\*.*?\*\//s', $contents, $docblockMatches));

        $docblock = $docblockMatches[0];

        $this->assertStringContainsString('@method static int explicitStatic()', $docblock);
        $this->assertStringContainsString('@method static string implicitStatic()', $docblock);
        $this->assertStringContainsString(
            '@method static ($value is \stdClass ? int : string) conditionalMethod(mixed $value)',
            $docblock
        );
        $this->assertStringNotContainsString('static static', $docblock);
        $this->assertStringNotContainsString('__magicMethod', $docblock);
        $this->assertStringNotContainsString('FACADECONFLICT', $docblock);
        $this->assertStringNotContainsString('ignoredMethod', $docblock);

        preg_match_all('/\bduplicateName\s*\(/i', $docblock, $duplicateMatches);

        $this->assertCount(
            1,
            $duplicateMatches[0],
            'Expected exactly one @method line for duplicateName, got: ' . implode(', ', $duplicateMatches[0])
        );
    }
}
