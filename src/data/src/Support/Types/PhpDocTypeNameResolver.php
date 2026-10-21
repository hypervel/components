<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Types;

use PhpToken;
use ReflectionClass;
use RuntimeException;

class PhpDocTypeNameResolver
{
    /** @var array<string, array{imports: array<string, array<string, class-string>>, namespaces: array<int, string>}> */
    protected array $sources = [];

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

        $namespace = $this->namespaceFor($class);
        $sameNamespace = $namespace === '' ? $name : "{$namespace}\\{$name}";

        [$alias, $suffix] = array_pad(explode('\\', $name, 2), 2, null);
        $import = $this->importsFor($class, $namespace)[$alias] ?? null;

        if ($import !== null) {
            return $suffix === null ? $import : "{$import}\\{$suffix}";
        }

        return $sameNamespace;
    }

    /**
     * Get the namespace a class is declared in.
     *
     * An anonymous class is named after its parent, so its namespace comes from its position in the source file.
     *
     * @param ReflectionClass<object> $class
     */
    protected function namespaceFor(ReflectionClass $class): string
    {
        $file = $class->getFileName();

        if (! $class->isAnonymous() || $file === false) {
            return $class->getNamespaceName();
        }

        $namespace = '';

        foreach ($this->source($file)['namespaces'] as $line => $declaredNamespace) {
            if ($line > $class->getStartLine()) {
                break;
            }

            $namespace = $declaredNamespace;
        }

        return $namespace;
    }

    /**
     * Get the class imports declared for a namespace by the source file.
     *
     * @param ReflectionClass<object> $class
     * @return array<string, class-string>
     */
    protected function importsFor(ReflectionClass $class, string $namespace): array
    {
        $file = $class->getFileName();

        if ($file === false) {
            return [];
        }

        return $this->source($file)['imports'][$namespace] ?? [];
    }

    /**
     * Get the parsed imports and namespace declarations of a source file.
     *
     * @return array{imports: array<string, array<string, class-string>>, namespaces: array<int, string>}
     */
    protected function source(string $file): array
    {
        return $this->sources[$file] ??= $this->parseSource($file);
    }

    /**
     * Parse class imports and namespace declaration lines from a PHP source file.
     *
     * @return array{imports: array<string, array<string, class-string>>, namespaces: array<int, string>}
     */
    protected function parseSource(string $file): array
    {
        $source = file_get_contents($file);

        if ($source === false) {
            throw new RuntimeException("Unable to read PHPDoc source file [{$file}].");
        }

        $tokens = PhpToken::tokenize($source);
        $imports = [];
        $namespaces = [];
        $namespace = '';
        $namespaceDepth = 0;
        $braceDepth = 0;

        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];

            if ($token->id === T_NAMESPACE && $braceDepth === 0) {
                [$namespace, $delimiterIndex] = $this->parseNamespace($tokens, $index + 1);
                $namespaces[$token->line] = $namespace;
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

        return ['imports' => $imports, 'namespaces' => $namespaces];
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
