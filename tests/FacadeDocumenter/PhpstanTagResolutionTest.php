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

    public function testUnboundedAndClassCollidingTemplatesResolveToMixed(): void
    {
        $this->writeAppFile(
            'PhpstanTags/UnboundedTemplates/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\PhpstanTags\UnboundedTemplates;

                class Proxy
                {
                    /**
                     * @template TResult
                     * @return TResult
                     */
                    public function standard(): mixed
                    {
                        return null;
                    }

                    /**
                     * @phpstan-template TResult
                     * @phpstan-return TResult
                     */
                    public function phpstan(): mixed
                    {
                        return null;
                    }

                    /**
                     * @template DateTimeImmutable
                     * @return DateTimeImmutable
                     */
                    public function classCollision(): mixed
                    {
                        return null;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'PhpstanTags/UnboundedTemplates/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\PhpstanTags\UnboundedTemplates;

                /** @see \App\PhpstanTags\UnboundedTemplates\Proxy */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\PhpstanTags\UnboundedTemplates\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\PhpstanTags\UnboundedTemplates\Facade');

        $this->assertStringContainsString('@method static mixed classCollision()', $contents);
        $this->assertStringContainsString('@method static mixed phpstan()', $contents);
        $this->assertStringContainsString('@method static mixed standard()', $contents);
        $this->assertStringNotContainsString('\DateTimeImmutable classCollision()', $contents);
    }

    public function testRefinementTypesResolveToRuntimeTypes(): void
    {
        $this->writeAppFile(
            'PhpstanTags/Refinements/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\PhpstanTags\Refinements;

                class Proxy
                {
                    public const int FLAG_A = 1;

                    public const int FLAG_B = 2;

                    /** @return non-empty-string */
                    public function nonEmptyString(): string { return 'value'; }

                    /** @return non-falsy-string */
                    public function nonFalsyString(): mixed { return null; }

                    /** @return truthy-string */
                    public function truthyString(): mixed { return null; }

                    /** @return literal-string */
                    public function literalString(): mixed { return null; }

                    /** @return lowercase-string */
                    public function lowercaseString(): mixed { return null; }

                    /** @return numeric-string */
                    public function numericString(): mixed { return null; }

                    /** @return uppercase-string */
                    public function uppercaseString(): mixed { return null; }

                    /** @return callable-string */
                    public function callableString(): mixed { return null; }

                    /** @return interface-string */
                    public function interfaceString(): mixed { return null; }

                    /** @return enum-string */
                    public function enumString(): mixed { return null; }

                    /** @return trait-string */
                    public function traitString(): mixed { return null; }

                    /** @return positive-int */
                    public function positiveInt(): mixed { return null; }

                    /** @return negative-int */
                    public function negativeInt(): mixed { return null; }

                    /** @return non-positive-int */
                    public function nonPositiveInt(): mixed { return null; }

                    /** @return non-negative-int */
                    public function nonNegativeInt(): mixed { return null; }

                    /** @return non-zero-int */
                    public function nonZeroInt(): mixed { return null; }

                    /** @return int-mask<1, 2, 4> */
                    public function intMask(): mixed { return null; }

                    /** @return int-mask-of<Proxy::FLAG_*> */
                    public function intMaskOf(): mixed { return null; }

                    /** @return list */
                    public function bareList(): mixed { return null; }

                    /** @return non-empty-list */
                    public function bareNonEmptyList(): mixed { return null; }

                    /** @return non-empty-array */
                    public function bareNonEmptyArray(): mixed { return null; }
                }
                PHP
        );

        $this->writeAppFile(
            'PhpstanTags/Refinements/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\PhpstanTags\Refinements;

                /** @see \App\PhpstanTags\Refinements\Proxy */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\PhpstanTags\Refinements\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\PhpstanTags\Refinements\Facade');

        foreach ([
            'nonEmptyString', 'nonFalsyString', 'truthyString', 'literalString',
            'lowercaseString', 'numericString', 'uppercaseString', 'callableString',
            'interfaceString', 'enumString', 'traitString',
        ] as $method) {
            $this->assertStringContainsString("@method static string {$method}()", $contents);
        }

        foreach ([
            'positiveInt', 'negativeInt', 'nonPositiveInt', 'nonNegativeInt',
            'nonZeroInt', 'intMask', 'intMaskOf',
        ] as $method) {
            $this->assertStringContainsString("@method static int {$method}()", $contents);
        }

        foreach (['bareList', 'bareNonEmptyList', 'bareNonEmptyArray'] as $method) {
            $this->assertStringContainsString("@method static array {$method}()", $contents);
        }

        $this->assertStringNotContainsString('mixed nonEmptyString()', $contents);
    }

    public function testRewrittenConditionalTargetsAreFlattened(): void
    {
        $this->writeAppFile(
            'PhpstanTags/RewrittenConditional/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\PhpstanTags\RewrittenConditional;

                class Proxy
                {
                    public const array VALUES = ['one' => 1];

                    /** @phpstan-return ($value is non-empty-string ? string : int) */
                    public function stringRefinement(mixed $value): mixed { return $value; }

                    /** @phpstan-return ($value is positive-int ? int : string) */
                    public function integerRefinement(mixed $value): mixed { return $value; }

                    /** @phpstan-return ($value is non-empty-array ? array : string) */
                    public function arrayRefinement(mixed $value): mixed { return $value; }

                    /** @phpstan-return ($value is class-string<\stdClass> ? object : string) */
                    public function classString(mixed $value): mixed { return $value; }

                    /** @phpstan-return ($value is int<0, max> ? int : string) */
                    public function boundedInt(mixed $value): mixed { return $value; }

                    /** @phpstan-return ($value is int-mask<1, 2> ? int : string) */
                    public function intMask(mixed $value): mixed { return $value; }

                    /** @phpstan-return ($value is int-mask-of<Proxy::VALUES> ? int : string) */
                    public function intMaskOf(mixed $value): mixed { return $value; }

                    /** @phpstan-return ($value is key-of<Proxy::VALUES> ? string : int) */
                    public function keyOf(mixed $value): mixed { return $value; }

                    /** @phpstan-return ($value is value-of<Proxy::VALUES> ? int : string) */
                    public function valueOf(mixed $value): mixed { return $value; }

                    /** @phpstan-return ($value is non-empty-list<string> ? array : string) */
                    public function genericArray(mixed $value): mixed { return $value; }
                }
                PHP
        );

        $this->writeAppFile(
            'PhpstanTags/RewrittenConditional/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\PhpstanTags\RewrittenConditional;

                /** @see \App\PhpstanTags\RewrittenConditional\Proxy */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\PhpstanTags\RewrittenConditional\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\PhpstanTags\RewrittenConditional\Facade');

        foreach ([
            'stringRefinement' => 'string|int',
            'integerRefinement' => 'int|string',
            'arrayRefinement' => 'array|string',
            'classString' => 'object|string',
            'boundedInt' => 'int|string',
            'intMask' => 'int|string',
            'intMaskOf' => 'int|string',
            'keyOf' => 'string|int',
            'valueOf' => 'int|string',
            'genericArray' => 'array|string',
        ] as $method => $returnType) {
            $this->assertStringContainsString(
                '@method static ' . $returnType . ' ' . $method . '(mixed $value)',
                $contents,
            );
        }

        $this->assertStringNotContainsString('$value is', $contents);
    }
}
