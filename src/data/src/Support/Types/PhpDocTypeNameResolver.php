<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Types;

use PhpToken;
use ReflectionClass;
use RuntimeException;

class PhpDocTypeNameResolver
{
    /** @var array<string, array<string, array<string, class-string>>> */
    protected array $imports = [];

    /**
     * Resolve a PHPDoc type name in its declaring class context.
     *
     * @param ReflectionClass<object> $class
     */
    public function resolve(string $name, ReflectionClass $class): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        if (self::isBuiltIn($name)) {
            return $name;
        }

        $namespace = $class->getNamespaceName();
        $sameNamespace = $namespace === '' ? $name : "{$namespace}\\{$name}";

        [$alias, $suffix] = array_pad(explode('\\', $name, 2), 2, null);
        $import = $this->importsFor($class)[$alias] ?? null;

        if ($import !== null) {
            return $suffix === null ? $import : "{$import}\\{$suffix}";
        }

        return $sameNamespace;
    }

    /**
     * Get the class imports declared by the source file.
     *
     * @param ReflectionClass<object> $class
     * @return array<string, class-string>
     */
    protected function importsFor(ReflectionClass $class): array
    {
        $file = $class->getFileName();

        if ($file === false) {
            return [];
        }

        $imports = $this->imports[$file] ??= $this->parseImports($file);

        return $imports[$class->getNamespaceName()] ?? [];
    }

    /**
     * Parse class imports from a PHP source file.
     *
     * @return array<string, array<string, class-string>>
     */
    protected function parseImports(string $file): array
    {
        $source = file_get_contents($file);

        if ($source === false) {
            throw new RuntimeException("Unable to read PHPDoc source file [{$file}].");
        }

        $tokens = PhpToken::tokenize($source);
        $imports = [];
        $namespace = '';
        $namespaceDepth = 0;
        $braceDepth = 0;

        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];

            if ($token->id === T_NAMESPACE && $braceDepth === 0) {
                [$namespace, $delimiterIndex] = $this->parseNamespace($tokens, $index + 1);
                $delimiter = $tokens[$delimiterIndex]->text;
                $namespaceDepth = $delimiter === '{' ? 1 : 0;
                $braceDepth = $namespaceDepth;
                $index = $delimiterIndex;

                continue;
            }

            if ($token->text === '{') {
                ++$braceDepth;

                continue;
            }

            if ($token->text === '}') {
                --$braceDepth;

                continue;
            }

            if ($token->id !== T_USE || $braceDepth !== $namespaceDepth) {
                continue;
            }

            $next = $this->nextSignificantToken($tokens, $index + 1);

            if ($next === null || $next->text === '(' || $next->id === T_FUNCTION || $next->id === T_CONST) {
                continue;
            }

            [$statement, $delimiterIndex] = $this->collectUseStatement($tokens, $index + 1);
            $imports[$namespace] ??= [];
            $imports[$namespace] += $this->parseUseStatement($statement);
            $index = $delimiterIndex;
        }

        return $imports;
    }

    /**
     * Parse a namespace declaration.
     *
     * @param list<PhpToken> $tokens
     * @return array{string, int}
     */
    protected function parseNamespace(array $tokens, int $index): array
    {
        $namespace = '';

        for ($count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];

            if ($token->text === ';' || $token->text === '{') {
                return [$namespace, $index];
            }

            if (! $token->isIgnorable()) {
                $namespace .= $token->text;
            }
        }

        return [$namespace, $index - 1];
    }

    /**
     * Collect the tokens belonging to one use statement.
     *
     * @param list<PhpToken> $tokens
     * @return array{list<PhpToken>, int}
     */
    protected function collectUseStatement(array $tokens, int $index): array
    {
        $statement = [];

        for ($count = count($tokens); $index < $count; ++$index) {
            if ($tokens[$index]->text === ';') {
                return [$statement, $index];
            }

            if (! $tokens[$index]->isIgnorable()) {
                $statement[] = $tokens[$index];
            }
        }

        return [$statement, $index - 1];
    }

    /**
     * Parse one normal or grouped use statement.
     *
     * @param list<PhpToken> $tokens
     * @return array<string, class-string>
     */
    protected function parseUseStatement(array $tokens): array
    {
        $groupStart = array_find_key($tokens, fn (PhpToken $token): bool => $token->text === '{');

        if ($groupStart === null) {
            return $this->parseImportEntries($tokens);
        }

        $prefix = rtrim($this->joinTokenText(array_slice($tokens, 0, $groupStart)), '\\');
        $entries = array_slice($tokens, $groupStart + 1, -1);

        return $this->parseImportEntries($entries, $prefix);
    }

    /**
     * Parse comma-separated import entries.
     *
     * @param list<PhpToken> $tokens
     * @return array<string, class-string>
     */
    protected function parseImportEntries(array $tokens, string $prefix = ''): array
    {
        $imports = [];
        $entry = [];

        foreach ([...$tokens, new PhpToken(ord(','), ',')] as $token) {
            if ($token->text !== ',') {
                $entry[] = $token;

                continue;
            }

            if ($entry === []) {
                continue;
            }

            $as = array_find_key($entry, fn (PhpToken $entryToken): bool => $entryToken->id === T_AS);
            $nameTokens = $as === null ? $entry : array_slice($entry, 0, $as);
            $name = ltrim($this->joinTokenText($nameTokens), '\\');
            $class = $prefix === '' ? $name : "{$prefix}\\{$name}";
            $alias = $as === null
                ? class_basename($name)
                : $this->joinTokenText(array_slice($entry, $as + 1));

            $imports[$alias] = $class;
            $entry = [];
        }

        return $imports;
    }

    /**
     * Find the next non-ignorable token.
     *
     * @param list<PhpToken> $tokens
     */
    protected function nextSignificantToken(array $tokens, int $index): ?PhpToken
    {
        for ($count = count($tokens); $index < $count; ++$index) {
            if (! $tokens[$index]->isIgnorable()) {
                return $tokens[$index];
            }
        }

        return null;
    }

    /**
     * Join token text without whitespace or comments.
     *
     * @param list<PhpToken> $tokens
     */
    protected function joinTokenText(array $tokens): string
    {
        $value = '';

        foreach ($tokens as $token) {
            if (! $token->isIgnorable()) {
                $value .= $token->text;
            }
        }

        return $value;
    }

    /**
     * Determine if the type is built into PHP or PHPDoc.
     */
    protected static function isBuiltIn(string $type): bool
    {
        return in_array(strtolower($type), [
            'array',
            'array-key',
            'bool',
            'boolean',
            'callable',
            'false',
            'float',
            'int',
            'integer',
            'iterable',
            'mixed',
            'never',
            'null',
            'object',
            'resource',
            'string',
            'true',
            'void',
        ], true);
    }
}
