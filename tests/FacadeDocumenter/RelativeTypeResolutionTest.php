<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class RelativeTypeResolutionTest extends FacadeDocumenterTestCase
{
    /**
     * resolveType() used to return self/static names early, short-circuiting
     * the allowsNull() logic that applies to every other ReflectionNamedType.
     * The fix unifies base-name computation and applies |null at the end, so
     * ?self / ?static in a proxy's native signature flow through to the
     * facade's @method line with their nullability intact.
     */
    public function testNullableSelfParamPreservesNullability(): void
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

    /**
     * Resolve relative types from their declaring and selected classes.
     */
    public function testRelativeTypesUseTheirLexicalOwners(): void
    {
        $this->writeAppFile(
            'RelativeTypes/GrandProxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\RelativeTypes;

                class GrandProxy
                {
                }
                PHP
        );

        $this->writeAppFile(
            'RelativeTypes/DeclaringProxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\RelativeTypes;

                class DeclaringProxy extends GrandProxy
                {
                    public function nativeSelf(self $value): self
                    {
                        return $value;
                    }

                    public function nativeParent(parent $value): parent
                    {
                        return $value;
                    }

                    public function nativeStatic(): static
                    {
                        return new static;
                    }

                    /**
                     * @param self $self
                     * @param parent $parent
                     * @param static $static
                     * @return self|parent|static
                     */
                    public function documented(mixed $self, mixed $parent, mixed $static): mixed
                    {
                        return $static;
                    }

                    /**
                     * @return ($value is parent ? self : static)
                     */
                    public function conditional(mixed $value): mixed
                    {
                        return $value;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'RelativeTypes/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\RelativeTypes;

                class Proxy extends DeclaringProxy
                {
                }
                PHP
        );

        $this->writeAppFile(
            'RelativeTypes/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\RelativeTypes;

                /**
                 * @see \App\RelativeTypes\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\RelativeTypes\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\RelativeTypes\Facade');
        $declaring = '\App\RelativeTypes\DeclaringProxy';
        $parent = '\App\RelativeTypes\GrandProxy';
        $selected = '\App\RelativeTypes\Proxy';

        $this->assertStringContainsString("@method static {$declaring} nativeSelf({$declaring} \$value)", $contents);
        $this->assertStringContainsString("@method static {$parent} nativeParent({$parent} \$value)", $contents);
        $this->assertStringContainsString("@method static {$selected} nativeStatic()", $contents);
        $this->assertStringContainsString(
            "@method static {$declaring}|{$parent}|{$selected} documented({$declaring} \$self, {$parent} \$parent, {$selected} \$static)",
            $contents
        );
        $this->assertStringContainsString(
            "@method static (\$value is {$parent} ? {$declaring} : {$selected}) conditional(mixed \$value)",
            $contents
        );
    }
}
