<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class NullableSelfStaticTest extends FacadeDocumenterTestCase
{
    /**
     * resolveType() used to return self/static names early, short-circuiting
     * the allowsNull() logic that applies to every other ReflectionNamedType.
     * The fix unifies base-name computation and applies |null at the end, so
     * ?self / ?static in a proxy's native signature flow through to the
     * facade's @method line with their nullability intact.
     */
    public function testNullableSelfParamPreservesNullability()
    {
        $this->writeAppFile(
            'NullableSelfStatic/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\NullableSelfStatic;

                class Proxy
                {
                    public static function createFrom(self $from, ?self $to = null): static
                    {
                        return new static();
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'NullableSelfStatic/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\NullableSelfStatic;

                /**
                 * @see \App\NullableSelfStatic\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\NullableSelfStatic\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\NullableSelfStatic\Facade');

        // Second param (?self $to) must emit "|null" on its type. Pre-fix
        // this was silently dropped, producing the invalid
        // "\App\NullableSelfStatic\Proxy $to = null".
        $this->assertStringContainsString(
            '\App\NullableSelfStatic\Proxy $from, \App\NullableSelfStatic\Proxy|null $to = null',
            $contents
        );
    }
}
