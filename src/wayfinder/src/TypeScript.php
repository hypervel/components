<?php

declare(strict_types=1);

namespace Hypervel\Wayfinder;

use Hypervel\Support\Js;
use Hypervel\Support\Stringable;

class TypeScript
{
    public const array RESERVED_KEYWORDS = [
        'arguments',
        'await',
        'break',
        'case',
        'catch',
        'class',
        'const',
        'continue',
        'debugger',
        'default',
        'delete',
        'do',
        'else',
        'enum',
        'export',
        'extends',
        'eval',
        'false',
        'finally',
        'for',
        'function',
        'if',
        'implements',
        'import',
        'in',
        'instanceof',
        'interface',
        'let',
        'new',
        'null',
        'package',
        'private',
        'protected',
        'public',
        'return',
        'static',
        'super',
        'switch',
        'this',
        'throw',
        'true',
        'try',
        'typeof',
        'var',
        'void',
        'while',
        'with',
        'yield',
    ];

    /**
     * Convert a method name into a TypeScript-safe identifier.
     */
    public static function safeMethod(string $method, string $suffix): string
    {
        $method = str($method)->replaceMatches('/[^\p{L}\p{Nd}_$-]/u', '_');

        if ($method->contains('-')) {
            $method = $method->camel();
        }

        $method = $method->toString();
        $suffix = strtolower($suffix);

        if (in_array($method, self::RESERVED_KEYWORDS, true)) {
            return $method . ucfirst($suffix);
        }

        if (! preg_match('/^[a-zA-Z_$]/', $method)) {
            return $suffix . $method;
        }

        return $method;
    }

    /**
     * Quote a value that is not a valid TypeScript object key identifier.
     */
    public static function quoteIfNeeded(string $name): string
    {
        if (preg_match('/^[\p{L}_$][\p{L}\p{Nd}_$]*$/u', $name)) {
            return $name;
        }

        return Js::from($name)->toHtml();
    }

    /**
     * Normalize whitespace, indentation, and spacing in generated TypeScript.
     */
    public static function cleanUp(string $view): string
    {
        $replacements = [
            PHP_EOL . ' +' => ' +',
            '})' . PHP_EOL . '/**' => '})' . PHP_EOL . PHP_EOL . '/**',
            '}' . PHP_EOL . '/**' => '}' . PHP_EOL . PHP_EOL . '/**',
        ];

        $argumentReplacements = [
            ' ,' => ',',
            '[ ' => '[',
            ' ]' => ']',
            ', }' => ' }',
            '} )' => '})',
            ' )' => ')',
            '( ' => '(',
        ];

        $regexReplacements = [
            '/\=\> \{\n{2,}/' => '=> {' . PHP_EOL,
            '/\n\s*\.replace/' => PHP_EOL . str_repeat(' ', 12) . '.replace',
            '/\n\s*\+ queryParams\(options\)/' => ' + queryParams(options)',
            '/\n{3,}/' => "\n\n",
        ];

        return str($view)
            ->pipe(function (Stringable $str) use ($argumentReplacements): Stringable {
                // Clean up function arguments
                $matches = $str->matchAll('/ = \(([^)]+\))/')
                    ->concat($str->matchAll('/\.url\(\s*args,\s+\{/'))
                    ->concat($str->matchAll('/\.url\(\s*args,\s+options\s*\)/'))
                    ->concat($str->matchAll('/\.url\(\s*options\s*\)/'))
                    ->concat($str->matchAll('/\(\s+\{/'))
                    ->concat($str->matchAll('/\}\s+\)/'));

                foreach ($matches as $match) {
                    $clean = preg_replace('/\s+/', ' ', $match);
                    $clean = str_replace(array_keys($argumentReplacements), array_values($argumentReplacements), $clean);
                    $str = $str->replaceFirst($match, $clean);
                }

                return $str;
            })
            ->pipe(function (Stringable $str): Stringable {
                $depth = 0;

                return str(
                    $str->explode(PHP_EOL)
                        ->map(fn (string $s): string => trim($s))
                        ->map(function (string $s) use (&$depth): string {
                            if ($s === '') {
                                return $s;
                            }

                            if (str_starts_with($s, '}') || str_starts_with($s, ']')) {
                                --$depth;
                            }

                            $line = str_repeat(' ', $depth * 4) . $s;

                            if (str_ends_with($s, '{') || str_ends_with($s, '[')) {
                                ++$depth;
                            }

                            return $line;
                        })
                        ->implode(PHP_EOL)
                );
            })
            ->replaceMatches(array_keys($regexReplacements), array_values($regexReplacements))
            ->replace(array_keys($replacements), array_values($replacements))
            ->toString();
    }
}
