<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class MixedNativeNullableTest extends FacadeDocumenterTestCase
{
    /**
     * When the docblock resolves to "mixed", adding "|null" to honour a
     * nullable native signature is redundant — mixed already subsumes null.
     * The merger must skip the null append in this case to avoid emitting
     * redundant "mixed|null" output.
     */
    public function testMixedDocblockWithNullableNativeDoesNotAppendNull()
    {
        $this->writeAppFile(
            'MixedNullable/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\MixedNullable;

                class Proxy
                {
                    /**
                     * @return mixed
                     */
                    public function fetch(): ?string
                    {
                        return null;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'MixedNullable/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\MixedNullable;

                /**
                 * @see \App\MixedNullable\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\MixedNullable\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\MixedNullable\Facade');

        $this->assertStringContainsString('@method static mixed fetch()', $contents);
        $this->assertStringNotContainsString('mixed|null', $contents);
    }
}
