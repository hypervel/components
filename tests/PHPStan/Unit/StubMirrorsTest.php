<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\Eloquent\Relations\HasOneOrMany;
use Hypervel\Database\Eloquent\Relations\HasOneOrManyThrough;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\Query\Builder as QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * A stub docblock replaces the source's, so these stubs repeat the source docblocks verbatim.
 */
class StubMirrorsTest extends TestCase
{
    private const string STUBS_PATH = __DIR__ . '/../../../src/phpstan/stubs/common/';

    #[Test]
    #[DataProvider('classMirrors')]
    public function itRepeatsTheSourceClassDocblock(string $stub, string $class): void
    {
        $reflection = new ReflectionClass($class);

        $this->assertSame(
            $this->normalize($reflection->getDocComment()),
            $this->normalize($this->findStubDocComment($stub, [T_CLASS, T_INTERFACE], $reflection->getShortName())),
        );
    }

    /**
     * Get the stubs whose class docblock repeats the source's.
     *
     * @return iterable<string, array{string, class-string}>
     */
    public static function classMirrors(): iterable
    {
        yield 'query builder' => ['Database/Query.stub', QueryBuilder::class];
        yield 'model not found exception' => ['Database/Eloquent.stub', ModelNotFoundException::class];
        yield 'arrayable' => ['Contracts/Support.stub', Arrayable::class];
        yield 'eloquent builder' => ['EloquentBuilder.stub', EloquentBuilder::class];
        yield 'has one or many' => ['HasOneOrMany.stub', HasOneOrMany::class];
        yield 'has one or many through' => ['HasOneOrManyThrough.stub', HasOneOrManyThrough::class];
        yield 'relation' => ['Relation.stub', Relation::class];
    }

    #[Test]
    #[DataProvider('methodMirrors')]
    public function itRepeatsTheSourceMethodDocblock(string $stub, string $class, string $method): void
    {
        $this->assertSame(
            $this->normalize((new ReflectionMethod($class, $method))->getDocComment()),
            $this->normalize($this->findStubDocComment($stub, [T_FUNCTION], $method)),
        );
    }

    /**
     * Get the stub methods whose docblock repeats the source's.
     *
     * @return iterable<string, array{string, class-string, string}>
     */
    public static function methodMirrors(): iterable
    {
        foreach (['offsetExists', 'offsetGet', 'offsetSet', 'offsetUnset'] as $method) {
            yield "config {$method}" => ['Config/Repository.stub', ConfigRepository::class, $method];
            yield "cache {$method}" => ['Cache/Repository.stub', CacheRepository::class, $method];
        }
    }

    /**
     * Find the docblock of a stub declaration by its keyword and name.
     *
     * @param list<int> $keywords
     */
    private function findStubDocComment(string $stub, array $keywords, string $name): ?string
    {
        $tokens = token_get_all((string) file_get_contents(self::STUBS_PATH . $stub));
        $docComment = null;

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                $docComment = null;

                continue;
            }

            if ($token[0] === T_DOC_COMMENT) {
                $docComment = $token[1];

                continue;
            }

            if (in_array($token[0], $keywords, true)) {
                $next = $tokens[$index + 2] ?? null;

                if (is_array($next) && $next[0] === T_STRING && $next[1] === $name) {
                    return $docComment;
                }
            }

            if (! in_array($token[0], [T_WHITESPACE, T_PUBLIC, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY], true)) {
                $docComment = null;
            }
        }

        return null;
    }

    /**
     * Collapse a docblock's whitespace, so indentation and line wrapping do not count.
     */
    private function normalize(false|string|null $docComment): string
    {
        return (string) preg_replace('/\s+/', ' ', (string) $docComment);
    }
}
