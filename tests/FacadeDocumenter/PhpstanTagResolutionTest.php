<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

class PhpstanTagResolutionTest extends FacadeDocumenterTestCase
{
    /**
     * Prefer PHPStan parameter and return tags over standard tags.
     */
    public function testPhpstanTagsTakePrecedence(): void
    {
        $this->writeAppFile(
            'PhpstanTags/Precedence/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\PhpstanTags\Precedence;

                class Proxy
                {
                    /**
                     * @param string $value
                     * @phpstan-param \DateTimeImmutable $value
                     * @return string
                     * @phpstan-return \DateTimeImmutable
                     */
                    public function transform(mixed $value): mixed
                    {
                        return $value;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'PhpstanTags/Precedence/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\PhpstanTags\Precedence;

                /**
                 * @see \App\PhpstanTags\Precedence\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\PhpstanTags\Precedence\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString(
            '@method static \DateTimeImmutable transform(\DateTimeImmutable $value)',
            $this->appFileContents('App\PhpstanTags\Precedence\Facade')
        );
    }

    /**
     * Preserve return types that depend on a parameter value.
     */
    public function testParameterConditionalReturnIsPreserved(): void
    {
        $this->writeAppFile(
            'PhpstanTags/Conditional/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\PhpstanTags\Conditional;

                class Proxy
                {
                    /**
                     * @return mixed
                     * @phpstan-return ($default is null ? \DateTimeImmutable|null : mixed)
                     */
                    public function queued(string $key, mixed $default = null): mixed
                    {
                        return $default;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'PhpstanTags/Conditional/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\PhpstanTags\Conditional;

                /**
                 * @see \App\PhpstanTags\Conditional\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\PhpstanTags\Conditional\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString(
            '@method static ($default is null ? \DateTimeImmutable|null : mixed) queued(string $key, mixed $default = null)',
            $this->appFileContents('App\PhpstanTags\Conditional\Facade')
        );
    }

    /**
     * Keep native nullability inside a preserved parameter conditional.
     */
    public function testParameterConditionalOwnsNativeNullability(): void
    {
        $this->writeAppFile(
            'PhpstanTags/ConditionalNullability/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\PhpstanTags\ConditionalNullability;

                class Proxy
                {
                    /**
                     * @phpstan-return ($value is null ? null : string)
                     */
                    public function resolve(?string $value = null): ?string
                    {
                        return $value;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'PhpstanTags/ConditionalNullability/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\PhpstanTags\ConditionalNullability;

                /**
                 * @see \App\PhpstanTags\ConditionalNullability\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\PhpstanTags\ConditionalNullability\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\PhpstanTags\ConditionalNullability\Facade');

        $this->assertStringContainsString(
            '@method static ($value is null ? null : string) resolve(string|null $value = null)',
            $contents
        );
        $this->assertStringNotContainsString(')|null resolve(', $contents);
    }

    /**
     * Flatten parameter conditionals whose generic target is broadened.
     */
    public function testBroadenedGenericConditionalTargetIsFlattened(): void
    {
        $this->writeAppFile(
            'PhpstanTags/GenericConditional/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\PhpstanTags\GenericConditional;

                class Proxy
                {
                    /**
                     * @template TClass of object
                     *
                     * @param class-string<TClass>|string $concrete
                     * @phpstan-return ($concrete is class-string<TClass> ? TClass : mixed)
                     */
                    public function make(string $concrete): mixed
                    {
                        return $concrete;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'PhpstanTags/GenericConditional/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\PhpstanTags\GenericConditional;

                /**
                 * @see \App\PhpstanTags\GenericConditional\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\PhpstanTags\GenericConditional\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\PhpstanTags\GenericConditional\Facade');

        $this->assertStringContainsString('@method static mixed make(string $concrete)', $contents);
        $this->assertStringNotContainsString('$concrete is string', $contents);
    }

    /**
     * Flatten parameter conditionals whose bare target is broadened.
     */
    public function testBroadenedBareConditionalTargetIsFlattened(): void
    {
        $this->writeAppFile(
            'PhpstanTags/BareConditional/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\PhpstanTags\BareConditional;

                class Proxy
                {
                    /**
                     * @phpstan-return ($concrete is class-string ? object : mixed)
                     */
                    public function make(string $concrete): mixed
                    {
                        return $concrete;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'PhpstanTags/BareConditional/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\PhpstanTags\BareConditional;

                /**
                 * @see \App\PhpstanTags\BareConditional\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\PhpstanTags\BareConditional\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\PhpstanTags\BareConditional\Facade');

        $this->assertStringContainsString('@method static mixed make(string $concrete)', $contents);
        $this->assertStringNotContainsString('$concrete is string', $contents);
    }

    /**
     * Prefer PHPStan template bounds while retaining standard template fallback.
     */
    public function testPhpstanTemplateBoundsTakePrecedence(): void
    {
        $this->writeAppFile(
            'PhpstanTags/Templates/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\PhpstanTags\Templates;

                class Proxy
                {
                    /**
                     * @template TResult of \stdClass
                     * @phpstan-template TResult of \DateTimeImmutable
                     * @return TResult
                     */
                    public function preferred(): mixed
                    {
                        return null;
                    }

                    /**
                     * @template TResult of \stdClass
                     * @return TResult
                     */
                    public function fallback(): mixed
                    {
                        return null;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'PhpstanTags/Templates/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\PhpstanTags\Templates;

                /**
                 * @see \App\PhpstanTags\Templates\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\PhpstanTags\Templates\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\PhpstanTags\Templates\Facade');

        $this->assertStringContainsString('@method static \DateTimeImmutable preferred()', $contents);
        $this->assertStringContainsString('@method static \stdClass fallback()', $contents);
    }
}
