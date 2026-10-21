<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head;

use Hypervel\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ArchTest extends TestCase
{
    public function testStrictTypes(): void
    {
        foreach ($this->sourceFiles() as $path => $source) {
            $this->assertMatchesRegularExpression('/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', $source, $path);
        }
    }

    public function testNoDebugging(): void
    {
        $this->assertNoFunctionCalls(['dd', 'dump', 'var_dump', 'die', 'ray']);
    }

    public function testNoDirectEnvCalls(): void
    {
        $this->assertNoFunctionCalls(['env']);
    }

    /**
     * Read the package's PHP source files.
     *
     * @return iterable<string, string>
     */
    private function sourceFiles(): iterable
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src/head/src'));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname() => file_get_contents($file->getPathname());
            }
        }
    }

    /**
     * Reject calls to prohibited functions without matching comments or strings.
     *
     * @param array<int, string> $functions
     */
    private function assertNoFunctionCalls(array $functions): void
    {
        $violations = [];

        foreach ($this->sourceFiles() as $path => $source) {
            $tokens = array_values(array_filter(token_get_all($source), static fn (array|string $token): bool => ! is_array($token)
                || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));

            foreach ($tokens as $index => $token) {
                if (! is_array($token)) {
                    continue;
                }

                $previous = $tokens[$index - 1] ?? null;

                if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                    continue;
                }

                if ($token[0] === T_EXIT && in_array('die', $functions, true)) {
                    $violations[] = $path . ':' . $token[2];
                } elseif (in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                    && in_array(strtolower(ltrim($token[1], '\\')), $functions, true)
                    && ($tokens[$index + 1] ?? null) === '(') {
                    $violations[] = $path . ':' . $token[2];
                }
            }
        }

        $this->assertSame([], $violations);
    }
}
