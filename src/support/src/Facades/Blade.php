<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hyperf\ViewEngine\Compiler\CompilerInterface;

/**
 * @method static void compile(string|null $path = null)
 * @method static string getPath()
 * @method static void setPath(string $path)
 * @method static string compileString(string $value)
 * @method static string stripParentheses(string $expression)
 * @method static void extend(callable $compiler)
 * @method static array getExtensions()
 * @method static void if(string $name, callable $callback)
 * @method static bool check(string $name, array ...$parameters)
 * @method static void component(string $class, string|null $alias = null, string $prefix = '')
 * @method static void components(array $components, string $prefix = '')
 * @method static array getClassComponentAliases()
 * @method static void componentNamespace(string $namespace, string $prefix)
 * @method static array getClassComponentNamespaces()
 * @method static array getComponentAutoload()
 * @method static void setComponentAutoload(array $config)
 * @method static void aliasComponent(string $path, string|null $alias = null)
 * @method static void include(string $path, string|null $alias = null)
 * @method static void aliasInclude(string $path, string|null $alias = null)
 * @method static void directive(string $name, callable $handler)
 * @method static array getCustomDirectives()
 * @method static void precompiler(callable $precompiler)
 * @method static void setEchoFormat(string $format)
 * @method static void withDoubleEncoding()
 * @method static void withoutDoubleEncoding()
 * @method static void withoutComponentTags()
 * @method static string getCompiledPath(string $path)
 * @method static bool isExpired(string $path)
 * @method static string newComponentHash(string $component)
 * @method static string compileClassComponentOpening(string $component, string $alias, string $data, string $hash)
 * @method static string compileEndComponentClass()
 * @method static mixed sanitizeComponentAttribute(mixed $value)
 * @method static string compileEndOnce()
 * @method static string compileEchos(string $value)
 *
 * @see \Hypervel\View\Compilers\BladeCompiler
 */
class Blade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return CompilerInterface::class;
    }
}
