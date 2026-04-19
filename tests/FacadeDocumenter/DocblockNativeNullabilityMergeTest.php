<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class DocblockNativeNullabilityMergeTest extends FacadeDocumenterTestCase
{
    /**
     * When the docblock declares a non-nullable precise type and the native
     * signature is nullable, the generator must union the two into a
     * <docblock>|null form. Without this merge the output is self-
     * contradictory: non-nullable type with a = null default.
     */
    public function testNonNullableDocblockTypeMergesWithNullableNativeSignature()
    {
        $this->writeAppFile(
            'NullabilityMerge/Param/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\NullabilityMerge\Param;

                class Proxy
                {
                    /**
                     * @param string[] $locales
                     */
                    public function preferred(?array $locales = null): ?string
                    {
                        return null;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'NullabilityMerge/Param/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\NullabilityMerge\Param;

                /**
                 * @see \App\NullabilityMerge\Param\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\NullabilityMerge\Param\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\NullabilityMerge\Param\Facade');

        // Param type must merge "string[]" with native "|null" nullability.
        // Return type must also carry |null from the native ?string.
        $this->assertStringContainsString(
            '@method static string|null preferred(string[]|null $locales = null)',
            $contents
        );
    }

    /**
     * When the docblock type already includes null (as a union member),
     * the merger must not add a duplicate null.
     */
    public function testAlreadyNullableDocblockTypeIsNotDoubled()
    {
        $this->writeAppFile(
            'NullabilityMerge/AlreadyNull/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\NullabilityMerge\AlreadyNull;

                class Proxy
                {
                    /**
                     * @return string|null
                     */
                    public function fetch(): ?string
                    {
                        return null;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'NullabilityMerge/AlreadyNull/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\NullabilityMerge\AlreadyNull;

                /**
                 * @see \App\NullabilityMerge\AlreadyNull\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\NullabilityMerge\AlreadyNull\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\NullabilityMerge\AlreadyNull\Facade');

        // Must produce "string|null" (not "string|null|null").
        $this->assertStringContainsString('@method static string|null fetch()', $contents);
        $this->assertStringNotContainsString('null|null', $contents);
    }
}
