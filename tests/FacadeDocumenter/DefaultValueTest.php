<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

use PHPStan\PhpDocParser\Ast\PhpDoc\InvalidTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

class DefaultValueTest extends FacadeDocumenterTestCase
{
    /**
     * Render string defaults without changing their values.
     */
    public function testStringDefaultsAreRenderedWithoutCorruption(): void
    {
        $this->writeAppFile(
            'DefaultValues/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\DefaultValues;

                class Proxy
                {
                    public function newline(string $value = "\n"): string
                    {
                        return $value;
                    }

                    public function apostrophe(string $value = "don't"): string
                    {
                        return $value;
                    }

                    public function doubleQuote(string $value = 'a"b'): string
                    {
                        return $value;
                    }

                    public function nonAscii(string $value = 'hé'): string
                    {
                        return $value;
                    }

                    public function backslash(string $value = 'a\\b'): string
                    {
                        return $value;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'DefaultValues/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\DefaultValues;

                /**
                 * @see \App\DefaultValues\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\DefaultValues\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\DefaultValues\Facade');

        $this->assertStringContainsString(
            <<<'PHP'
                @method static string newline(string $value = "\n")
                PHP,
            $contents,
        );
        $this->assertStringContainsString(
            <<<'PHP'
                @method static string apostrophe(string $value = 'don\'t')
                PHP,
            $contents,
        );
        $this->assertStringContainsString(
            <<<'PHP'
                @method static string doubleQuote(string $value = 'a"b')
                PHP,
            $contents,
        );
        $this->assertStringContainsString(
            <<<'PHP'
                @method static string nonAscii(string $value = 'hé')
                PHP,
            $contents,
        );
        $this->assertStringContainsString(
            <<<'PHP'
                @method static string backslash(string $value = 'a\\b')
                PHP,
            $contents,
        );
    }

    /**
     * Render scalar, array, and enum defaults as valid PHPDoc expressions.
     */
    public function testStructuredDefaultsAreRenderedExactly(): void
    {
        $this->writeAppFile(
            'DefaultValues/Complex/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\DefaultValues\Complex;

                enum BackedStatus: string
                {
                    case Active = 'active';
                }

                enum Direction
                {
                    case Ascending;
                }

                class Proxy
                {
                    public function listValue(array $value = [1, 'two', true, null]): array
                    {
                        return $value;
                    }

                    public function associative(array $value = ['one' => 1, 2 => 'two']): array
                    {
                        return $value;
                    }

                    public function nested(array $value = ['nested' => [1.0, false], 'enum' => Direction::Ascending]): array
                    {
                        return $value;
                    }

                    public function booleans(bool $enabled = true, bool $disabled = false, mixed $nothing = null): array
                    {
                        return [$enabled, $disabled, $nothing];
                    }

                    public function integer(int $value = 42): int
                    {
                        return $value;
                    }

                    public function floatValue(float $value = 1.0): float
                    {
                        return $value;
                    }

                    public function positiveInfinity(float $value = INF): float
                    {
                        return $value;
                    }

                    public function notANumber(float $value = NAN): float
                    {
                        return $value;
                    }

                    public function negativeInfinity(float $value = -INF): float
                    {
                        return $value;
                    }

                    public function backedEnum(BackedStatus $value = BackedStatus::Active): BackedStatus
                    {
                        return $value;
                    }

                    public function unitEnum(Direction $value = Direction::Ascending): Direction
                    {
                        return $value;
                    }

                    public function permissions(int $mode = 0755): int
                    {
                        return $mode;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'DefaultValues/Complex/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\DefaultValues\Complex;

                /** @see \App\DefaultValues\Complex\Proxy */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\DefaultValues\Complex\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\DefaultValues\Complex\Facade');

        $expectedMethods = [
            "@method static array listValue(array \$value = [1, 'two', true, null])",
            "@method static array associative(array \$value = ['one' => 1, 2 => 'two'])",
            "@method static array nested(array \$value = ['nested' => [1.0, false], 'enum' => \\App\\DefaultValues\\Complex\\Direction::Ascending])",
            '@method static array booleans(bool $enabled = true, bool $disabled = false, mixed $nothing = null)',
            '@method static int integer(int $value = 42)',
            '@method static float floatValue(float $value = 1.0)',
            '@method static float positiveInfinity(float $value = INF)',
            '@method static float notANumber(float $value = NAN)',
            '@method static float negativeInfinity(float $value = -1.0E+999)',
            '@method static \App\DefaultValues\Complex\BackedStatus backedEnum(\App\DefaultValues\Complex\BackedStatus $value = \App\DefaultValues\Complex\BackedStatus::Active)',
            '@method static \App\DefaultValues\Complex\Direction unitEnum(\App\DefaultValues\Complex\Direction $value = \App\DefaultValues\Complex\Direction::Ascending)',
            '@method static int permissions(int $mode = 0755)',
        ];

        foreach ($expectedMethods as $expectedMethod) {
            $this->assertStringContainsString($expectedMethod, $contents);
        }

        $this->assertSame(1, preg_match('/\/\*\*.*?\*\//s', $contents, $matches));

        $parserConfig = new ParserConfig([]);
        $constExprParser = new ConstExprParser($parserConfig);
        $parser = new PhpDocParser(
            $parserConfig,
            new TypeParser($parserConfig, $constExprParser),
            $constExprParser,
        );
        $docblock = $parser->parse(new TokenIterator((new Lexer($parserConfig))->tokenize($matches[0])));

        foreach ($docblock->getTags() as $tag) {
            $this->assertNotInstanceOf(InvalidTagValueNode::class, $tag->value, (string) $tag);
        }
    }

    /**
     * Fail without changing the facade when a direct object default cannot be rendered.
     */
    public function testDirectObjectDefaultFailsWithoutChangingFacade(): void
    {
        $this->writeAppFile(
            'DefaultValues/ObjectDirect/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\DefaultValues\ObjectDirect;

                class Proxy
                {
                    public function direct(\DateTimeImmutable $value = new \DateTimeImmutable('2024-01-01')): \DateTimeImmutable
                    {
                        return $value;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'DefaultValues/ObjectDirect/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\DefaultValues\ObjectDirect;

                /** @see \App\DefaultValues\ObjectDirect\Proxy */
                class Facade
                {
                }
                PHP
        );

        $before = $this->appFileContents('App\DefaultValues\ObjectDirect\Facade');
        $process = $this->runDocumenter(['App\DefaultValues\ObjectDirect\Facade']);
        $output = $process->getErrorOutput() . $process->getOutput();

        $this->assertSame(1, $process->getExitCode(), $output);
        $this->assertSame($before, $this->appFileContents('App\DefaultValues\ObjectDirect\Facade'));
        $this->assertStringContainsString('App\DefaultValues\ObjectDirect\Facade', $output);
        $this->assertStringContainsString('App\DefaultValues\ObjectDirect\Proxy::direct', $output);
        $this->assertStringContainsString('$value', $output);
        $this->assertStringContainsString('DateTimeImmutable', $output);
        $this->assertStringContainsString('ignoredFacadeDocumenterMethods()', $output);
    }

    /**
     * Fail without changing the facade when a nested object default cannot be rendered.
     */
    public function testNestedObjectDefaultFailsWithoutChangingFacade(): void
    {
        $this->writeAppFile(
            'DefaultValues/ObjectNested/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\DefaultValues\ObjectNested;

                class Proxy
                {
                    public function nested(array $value = [new \DateTimeImmutable('2024-01-01')]): array
                    {
                        return $value;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'DefaultValues/ObjectNested/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\DefaultValues\ObjectNested;

                /** @see \App\DefaultValues\ObjectNested\Proxy */
                class Facade
                {
                }
                PHP
        );

        $before = $this->appFileContents('App\DefaultValues\ObjectNested\Facade');
        $process = $this->runDocumenter(['App\DefaultValues\ObjectNested\Facade']);
        $output = $process->getErrorOutput() . $process->getOutput();

        $this->assertSame(1, $process->getExitCode(), $output);
        $this->assertSame($before, $this->appFileContents('App\DefaultValues\ObjectNested\Facade'));
        $this->assertStringContainsString('App\DefaultValues\ObjectNested\Facade', $output);
        $this->assertStringContainsString('App\DefaultValues\ObjectNested\Proxy::nested', $output);
        $this->assertStringContainsString('$value', $output);
        $this->assertStringContainsString('DateTimeImmutable', $output);
        $this->assertStringContainsString('ignoredFacadeDocumenterMethods()', $output);
    }
}
