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

class TypePrecedenceTest extends FacadeDocumenterTestCase
{
    /**
     * Render composite types with their original PHPDoc meaning.
     */
    public function testRendersCompositeTypesWithCorrectPrecedence(): void
    {
        $this->writeAppFile(
            'TypePrecedence/Proxy.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\TypePrecedence;

                interface Alpha
                {
                }

                interface Beta
                {
                }

                class Gamma
                {
                }

                class Proxy
                {
                    /** @return (Alpha&Beta)|null */
                    public function docblockIntersectionNullable(): mixed
                    {
                        return null;
                    }

                    public function nativeIntersectionNullable(): (Alpha&Beta)|null
                    {
                        return null;
                    }

                    /** @return (Alpha&Beta)|Gamma */
                    public function groupedFirstUnion(): mixed
                    {
                        return null;
                    }

                    /** @return (Alpha|Beta)[] */
                    public function unionArray(): mixed
                    {
                        return null;
                    }

                    /** @return (Alpha&Beta)[] */
                    public function intersectionArray(): mixed
                    {
                        return null;
                    }

                    /** @return (?Alpha)|null */
                    public function nestedNullableUnion(): mixed
                    {
                        return null;
                    }

                    /** @return (?Alpha)&Beta */
                    public function nullableIntersection(): mixed
                    {
                        return null;
                    }

                    /** @return ?(Alpha&Beta) */
                    public function nullableIntersectionGroup(): mixed
                    {
                        return null;
                    }

                    /** @return ?(Alpha|Beta) */
                    public function nullableUnionGroup(): mixed
                    {
                        return null;
                    }

                    /** @return (?Alpha)[] */
                    public function nullableArray(): mixed
                    {
                        return null;
                    }

                    /** @return ?Alpha */
                    public function nativeNullableMerge(): ?Alpha
                    {
                        return null;
                    }

                    /** @return array<int, Alpha|Beta> */
                    public function nestedGeneric(): mixed
                    {
                        return null;
                    }

                    /**
                     * @param Alpha|Gamma $value
                     * @return ($value is Alpha ? Alpha&Beta : Gamma)|null
                     */
                    public function conditionalUnion(Alpha|Gamma $value): mixed
                    {
                        return $value;
                    }

                    // Keep this untyped because a native return would mask duplicate null members.
                    /**
                     * @template TD of Gamma|null
                     * @return ?TD
                     */
                    public function nullableTemplateBound()
                    {
                        return null;
                    }

                    /**
                     * @template TU of Gamma|null
                     * @return TU[]
                     */
                    public function arrayOfUnionTemplateBound(): mixed
                    {
                        return null;
                    }

                    /**
                     * @template TI of Alpha&Beta
                     * @return TI[]
                     */
                    public function arrayOfIntersectionTemplateBound(): mixed
                    {
                        return null;
                    }

                    /**
                     * @template TI2 of Alpha&Beta
                     * @return TI2|null
                     */
                    public function unionWithIntersectionTemplateBound(): mixed
                    {
                        return null;
                    }

                    /**
                     * @template TC of Alpha|Beta
                     * @return TC&Alpha
                     */
                    public function intersectionWithUnionTemplateBound(): mixed
                    {
                        return null;
                    }
                }
                PHP
        );

        $this->writeAppFile(
            'TypePrecedence/Facade.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\TypePrecedence;

                /**
                 * @see \App\TypePrecedence\Proxy
                 */
                class Facade
                {
                }
                PHP
        );

        $process = $this->runDocumenter(['App\TypePrecedence\Facade']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $contents = $this->appFileContents('App\TypePrecedence\Facade');
        $alpha = '\App\TypePrecedence\Alpha';
        $beta = '\App\TypePrecedence\Beta';
        $gamma = '\App\TypePrecedence\Gamma';

        $expectedMethods = [
            "@method static ({$alpha}&{$beta})|null docblockIntersectionNullable()",
            "@method static ({$alpha}&{$beta})|null nativeIntersectionNullable()",
            "@method static ({$alpha}&{$beta})|{$gamma} groupedFirstUnion()",
            "@method static ({$alpha}|{$beta})[] unionArray()",
            "@method static ({$alpha}&{$beta})[] intersectionArray()",
            "@method static {$alpha}|null nestedNullableUnion()",
            "@method static ({$alpha}|null)&{$beta} nullableIntersection()",
            "@method static ({$alpha}&{$beta})|null nullableIntersectionGroup()",
            "@method static {$alpha}|{$beta}|null nullableUnionGroup()",
            "@method static ({$alpha}|null)[] nullableArray()",
            "@method static {$alpha}|null nativeNullableMerge()",
            "@method static array<int, {$alpha}|{$beta}> nestedGeneric()",
            "@method static (\$value is {$alpha} ? {$alpha}&{$beta} : {$gamma})|null conditionalUnion({$alpha}|{$gamma} \$value)",
            "@method static {$gamma}|null nullableTemplateBound()",
            "@method static ({$gamma}|null)[] arrayOfUnionTemplateBound()",
            "@method static ({$alpha}&{$beta})[] arrayOfIntersectionTemplateBound()",
            "@method static ({$alpha}&{$beta})|null unionWithIntersectionTemplateBound()",
            "@method static ({$alpha}|{$beta})&{$alpha} intersectionWithUnionTemplateBound()",
        ];

        foreach ($expectedMethods as $expectedMethod) {
            $this->assertStringContainsString($expectedMethod, $contents);
        }

        $this->assertSame(1, preg_match('/\/\*\*.*?\*\//s', $contents, $matches));

        $docComment = $matches[0];

        $parserConfig = new ParserConfig([]);
        $constExprParser = new ConstExprParser($parserConfig);
        $parser = new PhpDocParser(
            $parserConfig,
            new TypeParser($parserConfig, $constExprParser),
            $constExprParser,
        );
        $docblock = $parser->parse(new TokenIterator((new Lexer($parserConfig))->tokenize($docComment)));

        foreach ($docblock->getTags() as $tag) {
            $this->assertNotInstanceOf(InvalidTagValueNode::class, $tag->value, (string) $tag);
        }
    }
}
