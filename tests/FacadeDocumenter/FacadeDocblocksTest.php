<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

use Hypervel\Support\Facades\Facade;
use Hypervel\Tests\TestCase;
use JsonException;
use PHPStan\PhpDocParser\Ast\PhpDoc\InvalidTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Process\Process;

class FacadeDocblocksTest extends TestCase
{
    /**
     * Subprocess tests don't need coroutines.
     */
    protected bool $runTestsInCoroutine = false;

    /**
     * Ensure every first-party facade has an up-to-date generated docblock.
     */
    public function testFacadeDocblocksAreCurrent(): void
    {
        $root = dirname(__DIR__, 2);

        $process = new Process(
            [PHP_BINARY, '-f', $root . '/src/facade-documenter/facade.php', '--', '--lint', ...$this->facades()],
            timeout: 60,
        );
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
    }

    /**
     * Ensure every facade docblock contains valid tags.
     */
    public function testFacadeDocblocksContainValidTags(): void
    {
        $parserConfig = new ParserConfig([]);
        $constExprParser = new ConstExprParser($parserConfig);
        $parser = new PhpDocParser(
            $parserConfig,
            new TypeParser($parserConfig, $constExprParser),
            $constExprParser,
        );
        $lexer = new Lexer($parserConfig);

        foreach ($this->facades() as $facade) {
            $docComment = (new ReflectionClass($facade))->getDocComment();

            $this->assertIsString($docComment, "Facade [{$facade}] has no docblock.");

            $docblock = $parser->parse(new TokenIterator($lexer->tokenize($docComment)));

            foreach ($docblock->getTags() as $tag) {
                $this->assertNotInstanceOf(
                    InvalidTagValueNode::class,
                    $tag->value,
                    "Facade [{$facade}] contains an invalid tag [{$tag}].",
                );
            }
        }
    }

    /**
     * Return every current first-party facade.
     *
     * @return list<class-string>
     *
     * @throws JsonException
     */
    private function facades(): array
    {
        $root = dirname(__DIR__, 2);
        $composer = json_decode(
            file_get_contents($root . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $facades = [];

        foreach ($composer['autoload']['psr-4'] as $namespace => $sourcePaths) {
            foreach ((array) $sourcePaths as $sourcePath) {
                $sourceRoot = $root . '/' . rtrim($sourcePath, '/');

                $this->assertDirectoryExists($sourceRoot);

                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($sourceRoot, RecursiveDirectoryIterator::SKIP_DOTS),
                );

                /** @var SplFileInfo $file */
                foreach ($files as $file) {
                    if (! $file->isFile() || $file->getExtension() !== 'php') {
                        continue;
                    }

                    $relativePath = substr($file->getPathname(), strlen($sourceRoot) + 1, -4);
                    $class = rtrim($namespace, '\\') . '\\' . str_replace('/', '\\', $relativePath);
                    $source = file_get_contents($file->getPathname());

                    $this->assertIsString($source);

                    if (preg_match(
                        '/\bclass\s+' . preg_quote($file->getBasename('.php'), '/') . '\s+extends\b/',
                        $source,
                    ) !== 1) {
                        continue;
                    }

                    $this->assertTrue(class_exists($class), "Unable to autoload class candidate [{$class}].");

                    $reflection = new ReflectionClass($class);

                    if ($reflection->isSubclassOf(Facade::class) && ! $reflection->isAbstract()) {
                        $facades[] = $reflection->getName();
                    }
                }
            }
        }

        $facades = array_values(array_unique($facades));

        sort($facades);

        $this->assertNotEmpty($facades);

        return $facades;
    }
}
