<?php

declare(strict_types=1);

// Third-party installs invoke via vendor/bin/facade.php; Composer's bin proxy
// sets $_composer_autoload_path. The monorepo fallback handles direct invocation
// from the components repo (src/facade-documenter/facade.php), where no bin proxy
// is involved because the package is satisfied via Composer's "replace" mechanism.
$autoloadPath = $_composer_autoload_path ?? __DIR__ . '/../../vendor/autoload.php';

if (! is_file($autoloadPath)) {
    fwrite(STDERR, "Could not locate Composer autoloader.\n");
    exit(1);
}

require_once $autoloadPath;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprArrayItemNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprArrayNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFalseNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNullNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprTrueNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

$linting = in_array('--lint', $argv, true);
$verbose = in_array('--verbose', $argv, true);
$lintFailed = false;

set_exception_handler('exceptionHandler');

$filesystem = new Filesystem;

collect($argv)
    ->skip(1)
    ->filter(fn ($arg) => ! str_starts_with($arg, '-'))
    ->map(fn ($class) => new ReflectionClass($class))
    ->each(function ($facade) use ($filesystem, $linting, &$lintFailed) {
        debug("Processing [{$facade->getName()}]...");

        $proxies = resolveProxies($facade);

        if ($proxies->isEmpty()) {
            echo "Skipping [{$facade->getName()}] as no proxies were found." . PHP_EOL;

            return;
        }

        // Build a list of methods that are available on the Facade...

        $ignoredMethods = resolveIgnoredMethods($facade);

        $resolvedMethods = $proxies
            ->each(fn ($fqcn) => debug("  - {$fqcn}"))
            ->map(fn ($fqcn) => new ReflectionClass($fqcn))
            ->flatMap(fn ($class) => [$class, ...resolveDocMixins($class)])
            ->flatMap(resolveMethods(...))
            ->reject(isMagic(...))
            ->reject(isInternal(...))
            ->reject(isDeprecated(...))
            ->reject(fulfillsBuiltinInterface(...))
            ->reject(fn ($method) => conflictsWithFacade($facade, $method))
            ->reject(fn ($method) => $ignoredMethods->containsStrict(strtolower(resolveName($method))))
            // PHP method names are case-insensitive, so collapse different
            // casings of the same method (e.g. Redis "hScan" vs "hscan")
            // down to one entry in the generated @method list.
            ->uniqueStrict(fn ($method) => strtolower(resolveName($method)))
            ->map(normaliseDetails(...));

        // Prepare the @method docblocks...

        $globalClassImports = resolveClassImports($facade)
            ->filter(fn ($fqcn) => ! str_contains(ltrim($fqcn, '\\'), '\\'));

        $methods = $resolvedMethods->map(function ($method) use ($facade, $globalClassImports) {
            if ($method instanceof MethodTagValueNode) {
                // The node renders its own static marker, while every facade method must be static.
                $method = ' * @method ' . ($method->isStatic ? '' : 'static ') . $method;
            } else {
                $parameters = $method['parameters']->map(function ($parameter) use ($facade, $method) {
                    $rest = $parameter['variadic'] ? '...' : '';

                    $default = $parameter['optional']
                        ? ' = ' . resolveDefaultValue(
                            $parameter,
                            $facade->getName(),
                            $method['declaringClass'],
                            $method['name'],
                        )
                        : '';

                    return "{$parameter['type']} {$rest}{$parameter['name']}{$default}";
                });

                $method = " * @method static {$method['returns']} {$method['name']}({$parameters->join(', ')})";
            }

            return shortenImportedGlobalTypes($method, $globalClassImports);
        });

        // Fix: ensure we keep the references to the Carbon library on the Date Facade...

        if ($facade->getName() === Hypervel\Support\Facades\Date::class) {
            $methods->prepend(' *')
                ->prepend(' * @see https://github.com/briannesbitt/Carbon/blob/master/src/Carbon/Factory.php')
                ->prepend(' * @see https://carbon.nesbot.com/docs/');
        }

        // To support generics, we want to preserve any mixins on the class...

        $directMixins = resolveDocTags($facade->getDocComment() ?: '', '@mixin ');

        if ($methods->isEmpty()) {
            echo "Skipping [{$facade->getName()}] as no methods were found." . PHP_EOL;

            return;
        }

        // Generate the docblock...

        $docblock = <<< PHP
        /**
        {$methods->join(PHP_EOL)}
         *
        {$proxies->map(fn ($class) => ' * @see ' . Str::start($class, '\\'))->merge($proxies->isNotEmpty() && $directMixins->isNotEmpty() ? [' *'] : [])->merge($directMixins->map(fn ($class) => " * @mixin {$class}"))->join(PHP_EOL)}
         */
        PHP;

        if (($facade->getDocComment() ?: '') === $docblock) {
            return;
        }

        if ($linting) {
            echo "Did not find expected docblock for [{$facade->getName()}]." . PHP_EOL . PHP_EOL . $docblock . PHP_EOL . PHP_EOL;
            $lintFailed = true;

            return;
        }

        // Update the facade docblock...

        echo "Updating docblock for [{$facade->getName()}]." . PHP_EOL;
        $path = $facade->getFileName();
        $contents = $filesystem->get($path);
        $permissions = $filesystem->chmod($path);

        if ($permissions === false) {
            throw new RuntimeException("Unable to determine the permissions for [{$path}].");
        }

        $filesystem->replace(
            $path,
            str_replace($facade->getDocComment(), $docblock, $contents),
            octdec($permissions),
        );
    });

if ($lintFailed) {
    exit(1);
}

echo 'Done.';
exit(0);

/**
 * Handle the uncaught exceptions.
 */
function exceptionHandler(Throwable $exception)
{
    echo (string) $exception . PHP_EOL;
    exit(1);
}

/**
 * Log the given message when running with --verbose.
 *
 * @param string $message
 */
function debug($message)
{
    global $verbose;

    if ($verbose) {
        echo $message . PHP_EOL;
    }
}

/**
 * Resolve the proxies for the Facade.
 *
 * @param \ReflectionClass $class
 * @return \Hypervel\Support\Collection<class-string>
 */
function resolveProxies($class)
{
    return resolveDocSees($class)
        ->map(fn ($proxy) => determineFqcn($proxy, $class));
}

/**
 * Determine the fully qualified class name.
 *
 * @param string $class
 * @param \ReflectionClass $source
 * @return class-string
 */
function determineFqcn($class, $source)
{
    $class = (string) $class;

    if (str_starts_with($class, '\\')) {
        $fqcn = ltrim($class, '\\');

        return resolveCanonicalClassName($fqcn) ?? $fqcn;
    }

    if (str_starts_with(strtolower($class), 'namespace\\')) {
        $fqcn = $source->getNamespaceName() . '\\' . substr($class, 10);

        return resolveCanonicalClassName($fqcn) ?? $fqcn;
    }

    [$firstSegment, $remainder] = array_pad(explode('\\', $class, 2), 2, null);

    foreach (resolveClassImports($source) as $alias => $importedFqcn) {
        if (strcasecmp($alias, $firstSegment) !== 0) {
            continue;
        }

        $fqcn = ltrim($importedFqcn, '\\') . ($remainder === null ? '' : '\\' . $remainder);

        return resolveCanonicalClassName($fqcn) ?? $fqcn;
    }

    $fqcn = ltrim($source->getNamespaceName() . '\\' . $class, '\\');

    return resolveCanonicalClassName($fqcn) ?? $fqcn;
}

/**
 * Resolve the canonical name of an existing class.
 */
function resolveCanonicalClassName(string $name): ?string
{
    if (! class_exists($name) && ! interface_exists($name)) {
        return null;
    }

    return (new ReflectionClass($name))->getName();
}

/**
 * Resolve a class name relative to the method that declares it.
 *
 * @param \ReflectionClassDocblockContext|\ReflectionMethodDecorator $method
 */
function resolveRelativeClassName($method, string $name): ?string
{
    return match (strtolower(ltrim($name, '\\'))) {
        'self' => $method->getDeclaringClass()->getName(),
        'static' => $method->sourceClass()->getName(),
        'parent' => ($parent = $method->getDeclaringClass()->getParentClass()) === false
            ? null
            : $parent->getName(),
        default => null,
    };
}

/**
 * Resolve the classes referenced in the @see docblocks.
 *
 * @param \ReflectionClass $class
 * @return \Hypervel\Support\Collection<class-string>
 */
function resolveDocSees($class)
{
    return resolveDocTags($class->getDocComment() ?: '', '@see ')
        ->reject(fn ($tag) => str_starts_with($tag, 'https://'));
}

/**
 * Resolve the classes referenced methods in the @methods docblocks.
 *
 * @param \ReflectionClass $class
 * @return \Hypervel\Support\Collection<\PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode>
 */
function resolveDocMethods($class)
{
    $context = new ReflectionClassDocblockContext($class);

    return collect(parseDocblock($class->getDocComment())->getTags())
        ->filter(fn ($tag) => $tag->value instanceof MethodTagValueNode)
        ->map(function ($tag) use ($context) {
            /** @var MethodTagValueNode $method */
            $method = $tag->value;

            $method->parameters = collect($method->parameters)->map(function ($parameter) use ($context) {
                $parameter->type = new IdentifierTypeNode(
                    $parameter->type ? (resolveDocblockTypes($context, $parameter->type) ?? 'mixed') : 'mixed'
                );

                return $parameter;
            })->toArray();

            $method->returnType = $method->returnType
                ? new IdentifierTypeNode(resolveDocblockTypes($context, $method->returnType) ?? 'mixed')
                : new IdentifierTypeNode('void');

            return $method;
        });
}

/**
 * Resolve the parameters type from the @param docblocks.
 *
 * @param \ReflectionMethodDecorator $method
 * @param \ReflectionParameter $parameter
 * @return null|string
 */
function resolveDocParamType($method, $parameter)
{
    $docblock = parseDocblock($method->getDocComment());

    $paramTypeNode = collect([
        ...$docblock->getParamTagValues('@phpstan-param'),
        ...$docblock->getParamTagValues(),
    ])
        ->firstWhere('parameterName', '$' . $parameter->getName());

    // As we didn't find a param type, we will now recursively check if the prototype has a value specified...

    if ($paramTypeNode === null) {
        try {
            $prototype = new ReflectionMethodDecorator($method->getPrototype(), $method->sourceClass()->getName());

            return resolveDocParamType($prototype, $parameter);
        } catch (Throwable) {
            return null;
        }
    }

    return resolveDocblockTypes($method, $paramTypeNode->type);
}

/**
 * Resolve the return type from the @return docblock.
 *
 * @param \ReflectionMethodDecorator $method
 * @return null|string
 */
function resolveReturnDocType($method)
{
    $docblock = parseDocblock($method->getDocComment());

    $returnTypeNode = array_values($docblock->getReturnTagValues('@phpstan-return'))[0]
        ?? array_values($docblock->getReturnTagValues())[0]
        ?? null;

    if ($returnTypeNode === null) {
        return null;
    }

    return resolveDocblockTypes($method, $returnTypeNode->type);
}

/**
 * Parse the given docblock.
 *
 * @param string $docblock
 * @return \PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode
 */
function parseDocblock($docblock)
{
    $parserConfig = new ParserConfig([]);

    return (new PhpDocParser($parserConfig, new TypeParser($parserConfig, new ConstExprParser($parserConfig)), new ConstExprParser($parserConfig)))->parse(
        new TokenIterator((new Lexer($parserConfig))->tokenize($docblock ?: '/** */'))
    );
}

/**
 * Resolve the types from the docblock.
 *
 * @param \ReflectionClassDocblockContext|\ReflectionMethodDecorator $method
 * @param \PHPStan\PhpDocParser\Ast\Type\TypeNode $typeNode
 * @return null|string
 */
function resolveDocblockTypes($method, $typeNode, int $depth = 1)
{
    try {
        if ($typeNode instanceof UnionTypeNode) {
            return implode('|', resolveDocblockUnionMembers($method, $typeNode, $depth));
        }

        if ($typeNode instanceof IntersectionTypeNode) {
            return collect($typeNode->types)
                ->map(function ($node) use ($method, $depth) {
                    $type = (string) resolveDocblockTypes($method, $node, $depth + 1);

                    return count(splitTopLevelTypes($type)) > 1
                        ? "({$type})"
                        : $type;
                })
                ->uniqueStrict()
                ->implode('&');
        }

        if ($typeNode instanceof GenericTypeNode) {
            // Pseudo-generic wrappers collapse to a plain scalar regardless of
            // their inner type arguments (e.g. class-string<Foo> is still just
            // a string at runtime). Preserving the generic args would emit
            // nonsense like "string<Foo>" or "int<Foo::HEADER_*>" in the final
            // docblock. Real generic classes (e.g. Collection<Foo>) fall
            // through to the default branch which preserves <...>.
            $identifier = $typeNode->type->name;

            if ($identifier === 'class-string') {
                return 'string';
            }

            // 'int' here covers int<min, max> bounded-int generics;
            // int-mask-of lands here in wrapped form too.
            if (in_array($identifier, ['int-mask-of', 'int'], strict: true)) {
                return 'int';
            }

            if ($identifier === 'list') {
                return 'array';
            }

            if (in_array($identifier, ['key-of', 'value-of'], strict: true)) {
                $inner = $typeNode->genericTypes[0] ?? null;

                if ($inner instanceof ConstTypeNode && $inner->constExpr instanceof ConstFetchNode) {
                    return resolveKeyOrValueOf($inner->constExpr, $method, $identifier === 'key-of');
                }

                return 'mixed';
            }

            $baseType = resolveDocblockTypes($method, $typeNode->type, $depth + 1);

            $genericArgs = collect($typeNode->genericTypes)
                ->map(fn ($node) => resolveDocblockTypes($method, $node, $depth + 1))
                ->filter();

            // Use all() === [] instead of isEmpty(); Hypervel's Collection::isEmpty()
            // has a PHPDoc-narrowed signature that phpstan incorrectly flags as
            // always-false after filter().
            if ($genericArgs->all() === []) {
                return $baseType;
            }

            return $baseType . '<' . $genericArgs->implode(', ') . '>';
        }

        if ($typeNode instanceof ThisTypeNode) {
            return '\\' . $method->sourceClass()->getName();
        }

        if ($typeNode instanceof ArrayTypeNode) {
            $type = (string) resolveDocblockTypes($method, $typeNode->type, $depth + 1);

            return count(splitTopLevelTypes($type)) > 1
                || count(splitTopLevelTypes($type, '&')) > 1
                    ? "({$type})[]"
                    : "{$type}[]";
        }

        if ($typeNode instanceof IdentifierTypeNode) {
            if (($relative = resolveRelativeClassName($method, $typeNode->name)) !== null) {
                return '\\' . $relative;
            }

            if (isBuiltIn($typeNode->name)) {
                return (string) $typeNode;
            }

            if (in_array($typeNode->name, ['class-string', 'uppercase-string'], strict: true)) {
                return 'string';
            }

            if ($typeNode->name === 'list') {
                return 'array';
            }

            if (in_array($typeNode->name, ['int-mask-of', 'non-negative-int'], strict: true)) {
                return 'int';
            }

            $determinedFqcn = determineFqcn($typeNode->name, resolveImportSource($method));

            foreach ([$determinedFqcn, $typeNode->name] as $name) {
                if (($canonical = resolveCanonicalClassName($name)) !== null) {
                    return Str::start($canonical, '\\');
                }

                if (isKnownOptionalDependency($name)) {
                    return (string) $name;
                }
            }

            return handleUnknownIdentifierType($method, $typeNode, $depth);
        }

        if ($typeNode instanceof ConditionalTypeNode) {
            $if = resolveDocblockTypes($method, $typeNode->if, $depth + 1);
            $else = resolveDocblockTypes($method, $typeNode->else, $depth + 1);

            return flattenConditionalBranches($if, $else);
        }

        if ($typeNode instanceof NullableTypeNode) {
            return implode('|', resolveDocblockUnionMembers($method, $typeNode, $depth));
        }

        if ($typeNode instanceof CallableTypeNode) {
            return resolveDocblockTypes($method, $typeNode->identifier, $depth + 1);
        }

        if ($typeNode instanceof ConstTypeNode) {
            if ($typeNode->constExpr instanceof ConstFetchNode) {
                return resolveConstFetchType($typeNode->constExpr, $method);
            }

            if ($typeNode->constExpr instanceof ConstExprStringNode) {
                return 'string';
            }

            if ($typeNode->constExpr instanceof ConstExprIntegerNode) {
                return 'int';
            }

            if ($typeNode->constExpr instanceof ConstExprNullNode) {
                return 'null';
            }

            if ($typeNode->constExpr instanceof ConstExprFloatNode) {
                return 'float';
            }

            if ($typeNode->constExpr instanceof ConstExprFalseNode) {
                return 'false';
            }

            if ($typeNode->constExpr instanceof ConstExprTrueNode) {
                return 'true';
            }

            $class = $typeNode->constExpr::class;
            throw new UnresolvableType('resolveDocblockTypes', <<<MESSAGE
                Unknown constant type [{$class}] encountered when evaluating [{$method->sourceClass()->getName()}::{$method->getName()}].
                MESSAGE);
        }

        if ($typeNode instanceof ArrayShapeNode) {
            return 'array';
        }

        if ($typeNode instanceof ConditionalTypeForParameterNode) {
            $if = resolveDocblockTypes($method, $typeNode->if, $depth + 1);
            $else = resolveDocblockTypes($method, $typeNode->else, $depth + 1);

            if (! canPreserveConditionalTarget($method, $typeNode->targetType)) {
                return flattenConditionalBranches($if, $else);
            }

            $target = resolveDocblockTypes($method, $typeNode->targetType, $depth + 1);

            return sprintf(
                '(%s %s %s ? %s : %s)',
                $typeNode->parameterName,
                $typeNode->negated ? 'is not' : 'is',
                $target,
                $if,
                $else,
            );
        }

        $class = $typeNode::class;

        throw new UnresolvableType('resolveDocblockTypes', <<<MESSAGE
            Unknown type node [{$class}] encountered when evaluating [{$method->sourceClass()->getName()}::{$method->getName()}].
            MESSAGE);
    } catch (UnresolvableType $e) {
        if ($depth > 1) {
            throw $e;
        }

        echo $e->getMessage();
        echo PHP_EOL;
        echo 'You can safely ignore this message if there is a native type declaration in place, which will be used as a fallback.';
        echo PHP_EOL;
        echo "You may tweak the {$e->method} function of the facade-documenter if a fix is required.";
        echo PHP_EOL;
        echo PHP_EOL;

        return null;
    }
}

/**
 * Resolve and flatten the members of a PHPDoc union.
 *
 * @param \ReflectionClassDocblockContext|\ReflectionMethodDecorator $method
 * @param \PHPStan\PhpDocParser\Ast\Type\TypeNode $typeNode
 * @return list<string>
 */
function resolveDocblockUnionMembers($method, $typeNode, int $depth): array
{
    if ($typeNode instanceof UnionTypeNode) {
        $members = [];

        foreach ($typeNode->types as $node) {
            foreach (resolveDocblockUnionMembers($method, $node, $depth + 1) as $member) {
                if (! in_array($member, $members, true)) {
                    $members[] = $member;
                }
            }
        }

        return in_array('mixed', $members, true) ? ['mixed'] : $members;
    }

    if ($typeNode instanceof NullableTypeNode) {
        $members = resolveDocblockUnionMembers($method, $typeNode->type, $depth + 1);

        if (in_array('mixed', $members, true)) {
            return ['mixed'];
        }

        if (! in_array('null', $members, true)) {
            $members[] = 'null';
        }

        return $members;
    }

    $type = (string) resolveDocblockTypes($method, $typeNode, $depth + 1);
    $members = [];

    foreach (splitTopLevelTypes($type) as $member) {
        $members[] = count(splitTopLevelTypes($member, '&')) > 1 ? "({$member})" : $member;
    }

    return $members;
}

/**
 * Handle unknown identifier types.
 *
 * @param \ReflectionClassDocblockContext|\ReflectionMethodDecorator $method
 * @param \PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode $typeNode
 * @return string
 */
function handleUnknownIdentifierType($method, $typeNode, int $depth = 1)
{
    $docblock = parseDocblock($method->getDocComment());
    $boundTemplateType = collect([
        ...$docblock->getTemplateTagValues('@phpstan-template'),
        ...$docblock->getTemplateTagValues(),
    ])->firstWhere('name', $typeNode->name)?->bound;

    if ($boundTemplateType !== null) {
        $resolvedTemplateType = resolveDocblockTypes($method, $boundTemplateType, $depth);

        if ($resolvedTemplateType !== null) {
            return $resolvedTemplateType;
        }
    }

    return 'mixed';
}

/**
 * Resolve the inferred PHP type of a class-constant fetch such as Foo::BAR
 * or a wildcard like Foo::PREFIX_*.
 *
 * Handles single constants via Reflection and wildcard patterns by iterating
 * matching constants and unioning their inferred value types. Returns 'mixed'
 * when the constant or class cannot be resolved.
 *
 * @param \PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode $node
 * @param \ReflectionClassDocblockContext|\ReflectionMethodDecorator $method
 * @return string
 */
function resolveConstFetchType($node, $method)
{
    $className = resolveClassConstantClass($node->className, $method);

    if ($className === null) {
        return 'mixed';
    }

    try {
        $reflection = new ReflectionClass($className);
    } catch (Throwable) {
        return 'mixed';
    }

    if (str_contains($node->name, '*')) {
        $prefix = rtrim($node->name, '*');

        $types = collect($reflection->getReflectionConstants())
            ->filter(fn ($constant) => str_starts_with($constant->getName(), $prefix))
            ->map(fn ($constant) => inferValueType($constant->getValue()))
            ->uniqueStrict()
            ->values();

        return $types->isEmpty() ? 'mixed' : $types->implode('|');
    }

    if (! $reflection->hasConstant($node->name)) {
        return 'mixed';
    }

    return inferValueType($reflection->getConstant($node->name));
}

/**
 * Resolve key-of<...> / value-of<...> when the inner type is a ConstFetchNode.
 *
 * @param \PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode $node
 * @param \ReflectionClassDocblockContext|\ReflectionMethodDecorator $method
 * @param bool $keyType true to resolve the key type, false to resolve the value type
 * @return string
 */
function resolveKeyOrValueOf($node, $method, $keyType)
{
    $className = resolveClassConstantClass($node->className, $method);

    if ($className === null) {
        return 'mixed';
    }

    try {
        $reflection = new ReflectionClass($className);
    } catch (Throwable) {
        return 'mixed';
    }

    if (! $reflection->hasConstant($node->name)) {
        return 'mixed';
    }

    $value = $reflection->getConstant($node->name);

    if (! is_array($value) || $value === []) {
        return 'mixed';
    }

    $types = collect($keyType ? array_keys($value) : array_values($value))
        ->map(fn ($element) => inferValueType($element))
        ->uniqueStrict()
        ->values();

    return $types->implode('|');
}

/**
 * Resolve the target class for a ConstFetchNode's className reference.
 *
 * Handles "self" / "static" / "parent" aliases and resolves unqualified class
 * references against the method's use-statement imports and namespace. Returns
 * null when the class cannot be found.
 *
 * @param string $className
 * @param \ReflectionClassDocblockContext|\ReflectionMethodDecorator $method
 * @return null|string
 */
function resolveClassConstantClass($className, $method)
{
    $trimmed = ltrim($className, '\\');

    if (($relative = resolveRelativeClassName($method, $trimmed)) !== null) {
        return $relative;
    }

    $determined = ltrim(determineFqcn($className, resolveImportSource($method)), '\\');

    return resolveCanonicalClassName($determined)
        ?? resolveCanonicalClassName($trimmed);
}

/**
 * Infer a PHPDoc-friendly type string from a concrete PHP value.
 *
 * @param mixed $value
 * @return string
 */
function inferValueType($value)
{
    return match (true) {
        is_int($value) => 'int',
        is_float($value) => 'float',
        is_string($value) => 'string',
        is_bool($value) => 'bool',
        is_array($value) => 'array',
        is_object($value) => '\\' . get_class($value),
        is_null($value) => 'null',
        default => 'mixed',
    };
}

/**
 * Determine whether a conditional target can be preserved without broadening.
 *
 * Unknown node types must return false so new PHPDoc syntax falls back to a
 * conservative union instead of producing a misleading conditional.
 *
 * @param \ReflectionClassDocblockContext|\ReflectionMethodDecorator $method
 * @param \PHPStan\PhpDocParser\Ast\Type\TypeNode $typeNode
 * @return bool
 */
function canPreserveConditionalTarget($method, $typeNode)
{
    if ($typeNode instanceof ThisTypeNode) {
        return true;
    }

    if ($typeNode instanceof UnionTypeNode || $typeNode instanceof IntersectionTypeNode) {
        return collect($typeNode->types)
            ->every(fn ($nestedType) => canPreserveConditionalTarget($method, $nestedType));
    }

    if ($typeNode instanceof NullableTypeNode || $typeNode instanceof ArrayTypeNode) {
        return canPreserveConditionalTarget($method, $typeNode->type);
    }

    if ($typeNode instanceof GenericTypeNode) {
        if (in_array($typeNode->type->name, ['class-string', 'int', 'int-mask-of', 'key-of', 'list', 'value-of'], true)) {
            return false;
        }

        return canPreserveConditionalTarget($method, $typeNode->type)
            && collect($typeNode->genericTypes)
                ->every(fn ($nestedType) => canPreserveConditionalTarget($method, $nestedType));
    }

    if ($typeNode instanceof IdentifierTypeNode) {
        if (in_array($typeNode->name, ['class-string', 'int-mask-of', 'list', 'non-negative-int', 'uppercase-string'], true)) {
            return false;
        }

        if (resolveRelativeClassName($method, $typeNode->name) !== null) {
            return true;
        }

        if (isBuiltIn($typeNode->name)) {
            return true;
        }

        $determinedFqcn = determineFqcn($typeNode->name, resolveImportSource($method));

        foreach ([$determinedFqcn, $typeNode->name] as $name) {
            if (resolveCanonicalClassName($name) !== null || isKnownOptionalDependency($name)) {
                return true;
            }
        }

        return false;
    }

    return $typeNode instanceof ConstTypeNode
        && ($typeNode->constExpr instanceof ConstExprNullNode
            || $typeNode->constExpr instanceof ConstExprFalseNode
            || $typeNode->constExpr instanceof ConstExprTrueNode);
}

/**
 * Flatten, collapse, and deduplicate resolved conditional branches.
 *
 * @param string $if
 * @param string $else
 * @return string
 */
function flattenConditionalBranches($if, $else)
{
    $members = collect([
        ...splitTopLevelTypes($if),
        ...splitTopLevelTypes($else),
    ])->uniqueStrict();

    return $members->containsStrict('mixed') ? 'mixed' : $members->implode('|');
}

/**
 * Split a type string on a top-level separator while preserving separators
 * inside angle brackets or parentheses.
 *
 * @return array<int, string>
 */
function splitTopLevelTypes(string $type, string $separator = '|'): array
{
    $parts = [];
    $buffer = '';
    $angleDepth = 0;
    $parenDepth = 0;

    foreach (str_split($type) as $char) {
        if ($char === '<') {
            ++$angleDepth;
        } elseif ($char === '>') {
            --$angleDepth;
        } elseif ($char === '(') {
            ++$parenDepth;
        } elseif ($char === ')') {
            --$parenDepth;
        }

        if ($char === $separator && $angleDepth === 0 && $parenDepth === 0) {
            $parts[] = $buffer;
            $buffer = '';

            continue;
        }

        $buffer .= $char;
    }

    $parts[] = $buffer;

    return array_values(array_filter($parts, fn ($part) => $part !== ''));
}

/**
 * Merge the docblock-resolved type string with the native reflection type,
 * preserving the docblock's precision while honoring native nullability.
 *
 * Prefers the docblock type (richer — may include generics, class-string, etc.)
 * but unions it with "null" when the native signature is nullable and the
 * docblock didn't already express nullability. Skips the null-append when the
 * resolved type is "mixed" (which already subsumes null) to avoid redundant
 * "mixed|null" output.
 *
 * @param null|string $docblockType
 * @param null|string $nativeType
 * @return null|string
 */
function mergeDocblockTypeWithNativeNullability($docblockType, $nativeType)
{
    $resolved = $docblockType ?? $nativeType;

    if ($resolved === null) {
        return null;
    }

    if ($nativeType === null) {
        return $resolved;
    }

    $nativeMembers = splitTopLevelTypes($nativeType);
    $resolvedMembers = splitTopLevelTypes($resolved);

    $nativeIsNullable = in_array('null', $nativeMembers, true);
    $resolvedHasNull = in_array('null', $resolvedMembers, true);
    $resolvedHasMixed = in_array('mixed', $resolvedMembers, true);
    $resolvedIsConditional = count($resolvedMembers) === 1
        && str_starts_with($resolved, '(')
        && str_ends_with($resolved, ')');

    // Preserved parameter conditionals own nullability within their branches.
    if ($nativeIsNullable && ! $resolvedHasNull && ! $resolvedHasMixed && ! $resolvedIsConditional) {
        $resolvedMembers[] = 'null';
    }

    return collect($resolvedMembers)
        ->filter()
        ->uniqueStrict()
        ->implode('|');
}

/**
 * Determine if the type is a built-in.
 *
 * @param string $type
 * @return bool
 */
function isBuiltIn($type)
{
    return in_array($type, [
        'null', 'bool', 'int', 'float', 'string', 'array', 'object',
        'resource', 'never', 'void', 'mixed', 'iterable', 'true', 'false',
        'callable', 'array-key',
    ], true);
}

/**
 * Determine if the type is known optional dependency.
 *
 * @param string $type
 * @return bool
 */
function isKnownOptionalDependency($type)
{
    return in_array(Str::start($type, '\\'), [
        '\Pusher\Pusher',
    ], true);
}

/**
 * Resolve the declared type.
 *
 * @param \ReflectionMethodDecorator $method
 * @param null|\ReflectionType $type
 * @return null|string
 */
function resolveType($method, $type)
{
    if ($type instanceof ReflectionIntersectionType) {
        return collect($type->getTypes())
            ->map(fn ($type) => resolveType($method, $type))
            ->filter()
            ->uniqueStrict()
            ->join('&');
    }

    if ($type instanceof ReflectionUnionType) {
        return collect($type->getTypes())
            ->map(function ($type) use ($method) {
                $resolved = resolveType($method, $type);

                return $type instanceof ReflectionIntersectionType
                    ? "({$resolved})"
                    : $resolved;
            })
            ->filter()
            ->uniqueStrict()
            ->join('|');
    }

    if ($type instanceof ReflectionNamedType && $type->getName() === 'null') {
        return 'null';
    }

    if ($type instanceof ReflectionNamedType) {
        $relative = resolveRelativeClassName($method, $type->getName());
        $base = $relative === null
            ? ($type->isBuiltin() ? '' : '\\') . $type->getName()
            : '\\' . $relative;

        if ($type->allowsNull() && $type->getName() !== 'mixed') {
            return $base . '|null';
        }

        return $base;
    }

    return null;
}

/**
 * Resolve the docblock tags.
 *
 * @param string $docblock
 * @param string $tag
 * @return \Hypervel\Support\Collection<string>
 */
function resolveDocTags($docblock, $tag)
{
    return Str::of($docblock)
        ->after('/**')
        ->beforeLast('*/')
        ->explode("\n")
        ->map(fn ($line) => ltrim($line, " \t*"))
        ->filter(fn ($line) => str_starts_with($line, $tag))
        ->map(fn ($line) => Str::of($line)->after($tag)->trim()->toString())
        ->values();
}

/**
 * Resolve method names that should be excluded from a facade docblock.
 *
 * @param \ReflectionClass $facade
 * @return \Hypervel\Support\Collection<int, string>
 */
function resolveIgnoredMethods($facade)
{
    if (! $facade->hasMethod('ignoredFacadeDocumenterMethods')) {
        return collect();
    }

    $method = $facade->getMethod('ignoredFacadeDocumenterMethods');

    return collect($method->invoke(null))
        ->map(fn ($method) => strtolower((string) $method))
        ->values();
}

/**
 * Recursively resolve docblock mixins.
 *
 * @param \ReflectionClass $class
 * @param \Hypervel\Support\Collection<class-string> $encountered
 * @return \Hypervel\Support\Collection<\ReflectionClass>
 */
function resolveDocMixins($class, $encountered = new Collection)
{
    if ($encountered->containsStrict($class->getName())) {
        return collect();
    }

    debug("Resolving mixins for [{$class->getName()}]...");

    $encountered[] = $class->getName();

    return resolveDocTags($class->getDocComment() ?: '', '@mixin ')
        ->map(fn ($mixin) => determineFqcn($mixin, $class))
        ->each(fn ($mixin) => debug("  - {$mixin}"))
        ->map(fn ($mixin) => new ReflectionClass($mixin))
        ->flatMap(fn ($mixin) => [$mixin, ...resolveDocMixins($mixin, $encountered)]);
}

/**
 * Resolve the classes referenced methods in the @methods docblocks.
 *
 * @param \ReflectionMethodDecorator $method
 * @return \Hypervel\Support\Collection<int, string>
 */
function resolveDocParameters($method)
{
    return resolveDocTags($method->getDocComment() ?: '', '@param ')
        ->map(fn ($tag) => Str::squish($tag));
}

/**
 * Determine if the method is magic.
 *
 * @param \PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode|\ReflectionMethodDecorator $method
 * @return bool
 */
function isMagic($method)
{
    return Str::startsWith(resolveName($method), '__');
}

/**
 * Determine if the method is marked as @internal.
 *
 * @param \PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode|\ReflectionMethodDecorator $method
 * @return bool
 */
function isInternal($method)
{
    if ($method instanceof MethodTagValueNode) {
        return false;
    }

    return resolveDocTags($method->getDocComment(), '@internal')->isNotEmpty();
}

/**
 * Determine if the method is deprecated.
 *
 * @param \PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode|\ReflectionMethodDecorator $method
 * @return bool
 */
function isDeprecated($method)
{
    if ($method instanceof MethodTagValueNode) {
        return false;
    }

    return $method->isDeprecated() || resolveDocTags($method->getDocComment(), '@deprecated')->isNotEmpty();
}

/**
 * Determine if the method is for a builtin contract.
 *
 * @param \PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode|\ReflectionMethodDecorator $method
 * @return bool
 */
function fulfillsBuiltinInterface($method)
{
    if ($method instanceof MethodTagValueNode) {
        return false;
    }

    if ($method->sourceClass()->implementsInterface(ArrayAccess::class)) {
        return in_array($method->getName(), ['offsetExists', 'offsetGet', 'offsetSet', 'offsetUnset'], true);
    }

    return false;
}

/**
 * Resolve the methods name.
 *
 * @param \PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode|\ReflectionMethodDecorator $method
 * @return string
 */
function resolveName($method)
{
    return $method instanceof MethodTagValueNode
        ? $method->methodName
        : $method->getName();
}

/**
 * Resolve the classes methods.
 *
 * @param \ReflectionClass $class
 * @return \Hypervel\Support\Collection<\PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode|\ReflectionMethodDecorator>
 */
function resolveMethods($class)
{
    return collect($class->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map(fn ($method) => new ReflectionMethodDecorator($method, $class->getName()))
        ->merge(resolveDocMethods($class));
}

/**
 * Determine if the given method conflicts with a Facade method.
 *
 * @param \ReflectionClass $facade
 * @param \PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode|\ReflectionMethodDecorator $method
 * @return bool
 */
function conflictsWithFacade($facade, $method)
{
    return collect($facade->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC))
        ->map(fn ($method) => strtolower($method->getName()))
        ->containsStrict(strtolower(resolveName($method)));
}

/**
 * Normalise the method details into a easier format to work with.
 *
 * @param \PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode|\ReflectionMethodDecorator $method
 * @return array|\PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode
 */
function normaliseDetails($method)
{
    return $method instanceof MethodTagValueNode ? $method : [
        'name' => $method->getName(),
        'declaringClass' => $method->getDeclaringClass()->getName(),
        'parameters' => resolveParameters($method)
            ->map(fn ($parameter) => [
                'name' => '$' . $parameter->getName(),
                'optional' => $parameter->isOptional() && ! $parameter->isVariadic(),
                'default' => $parameter->isDefaultValueAvailable()
                    ? $parameter->getDefaultValue()
                    : "❌ Unknown default for [{$parameter->getName()}] in [{$parameter->getDeclaringClass()?->getName()}::{$parameter->getDeclaringFunction()->getName()}] ❌",
                'variadic' => $parameter->isVariadic(),
                'type' => mergeDocblockTypeWithNativeNullability(
                    resolveDocParamType($method, $parameter),
                    resolveType($method, $parameter->getType())
                ) ?? 'mixed',
            ]),
        'returns' => mergeDocblockTypeWithNativeNullability(
            resolveReturnDocType($method),
            resolveType($method, $method->getReturnType())
        ) ?? 'void',
    ];
}

/**
 * Resolve the parameters for the method.
 *
 * @param \ReflectionMethodDecorator $method
 * @return \Hypervel\Support\Collection<int, \DynamicParameter|\ReflectionParameter>
 */
function resolveParameters($method)
{
    $dynamicParameters = resolveDocParameters($method)
        ->skip($method->getNumberOfParameters())
        ->mapInto(DynamicParameter::class);

    return collect($method->getParameters())->merge($dynamicParameters);
}

/**
 * Resolve the class whose file should be scanned for use imports when resolving
 * types inside a method's docblock. When a method was inherited from a trait,
 * the trait's own file (not the declaring class's file) holds the relevant
 * `use` statements.
 *
 * @param \ReflectionClassDocblockContext|\ReflectionMethodDecorator $method
 * @return \ReflectionClass
 */
function resolveImportSource($method)
{
    return (new Collection($method->getDeclaringClass()->getTraits()))
        ->first(fn ($trait) => $trait->getFileName() === $method->getFileName())
        ?? $method->getDeclaringClass();
}

/**
 * Resolve the classes imports.
 *
 * @param \ReflectionClass $class
 * @return \Hypervel\Support\Collection<string, class-string>
 */
function resolveClassImports($class)
{
    $source = file_get_contents($class->getFileName());
    $prefix = implode("\n", array_slice(explode("\n", $source), 0, $class->getStartLine() - 1));
    $tokens = PhpToken::tokenize($prefix);
    $imports = [];
    $namespaceName = '';
    $namespaceTopLevelDepth = 0;
    $braceDepth = 0;

    foreach ($tokens as $index => $token) {
        if ($token->id === T_NAMESPACE) {
            $namespaceName = '';
            $imports = [];

            for ($namespaceIndex = $index + 1; isset($tokens[$namespaceIndex]); ++$namespaceIndex) {
                $namespaceToken = $tokens[$namespaceIndex];

                if ($namespaceToken->text === ';') {
                    $namespaceTopLevelDepth = $braceDepth;

                    break;
                }

                if ($namespaceToken->text === '{') {
                    $namespaceTopLevelDepth = $braceDepth + 1;

                    break;
                }

                if (! isIgnorablePhpToken($namespaceToken)) {
                    $namespaceName .= $namespaceToken->text;
                }
            }
        }

        if ($token->id === T_USE) {
            $nextIndex = nextSignificantPhpTokenIndex($tokens, $index + 1);

            if ($nextIndex !== null
                && $tokens[$nextIndex]->text !== '('
                && $braceDepth === $namespaceTopLevelDepth) {
                $statementTokens = [];

                for ($useIndex = $index + 1; isset($tokens[$useIndex]); ++$useIndex) {
                    if ($tokens[$useIndex]->text === ';') {
                        break;
                    }

                    $statementTokens[] = $tokens[$useIndex];
                }

                foreach (parseClassUseStatement($statementTokens) as $alias => $fqcn) {
                    $imports[$alias] = $fqcn;
                }

                continue;
            }
        }

        // Interpolated strings close their special opening token with an ordinary brace.
        if ($token->text === '{'
            || $token->id === T_CURLY_OPEN
            || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
            ++$braceDepth;
        } elseif ($token->text === '}') {
            --$braceDepth;
        }
    }

    return $namespaceName === $class->getNamespaceName()
        ? collect($imports)
        : collect();
}

/**
 * Determine whether a PHP token can be ignored while reading syntax.
 */
function isIgnorablePhpToken(PhpToken $token): bool
{
    return in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
}

/**
 * Return the next significant PHP token index.
 *
 * @param list<\PhpToken> $tokens
 */
function nextSignificantPhpTokenIndex(array $tokens, int $offset): ?int
{
    for ($index = $offset; isset($tokens[$index]); ++$index) {
        if (! isIgnorablePhpToken($tokens[$index])) {
            return $index;
        }
    }

    return null;
}

/**
 * Parse the class imports from one namespace-level use statement.
 *
 * @param list<\PhpToken> $tokens
 * @return array<string, class-string>
 */
function parseClassUseStatement(array $tokens): array
{
    $tokens = array_values(array_filter(
        $tokens,
        fn (PhpToken $token) => ! isIgnorablePhpToken($token),
    ));

    if ($tokens === [] || in_array($tokens[0]->id, [T_FUNCTION, T_CONST], true)) {
        return [];
    }

    $groupStart = null;

    foreach ($tokens as $index => $token) {
        if ($token->text === '{') {
            $groupStart = $index;

            break;
        }
    }

    if ($groupStart === null) {
        return parseClassUseSegments(splitClassUseSegments($tokens));
    }

    $prefix = rtrim(implode('', array_map(
        fn (PhpToken $token) => $token->text,
        array_slice($tokens, 0, $groupStart),
    )), '\\');
    $groupEnd = array_key_last($tokens);

    while ($tokens[$groupEnd]->text !== '}') {
        --$groupEnd;
    }

    return parseClassUseSegments(
        splitClassUseSegments(array_slice($tokens, $groupStart + 1, $groupEnd - $groupStart - 1)),
        $prefix,
    );
}

/**
 * Split use-statement tokens into comma-separated imports.
 *
 * @param list<\PhpToken> $tokens
 * @return list<list<\PhpToken>>
 */
function splitClassUseSegments(array $tokens): array
{
    $segments = [];
    $segment = [];

    foreach ($tokens as $token) {
        if ($token->text === ',') {
            $segments[] = $segment;
            $segment = [];

            continue;
        }

        $segment[] = $token;
    }

    if ($segment !== []) {
        $segments[] = $segment;
    }

    return $segments;
}

/**
 * Convert parsed use segments into an alias map.
 *
 * @param list<list<\PhpToken>> $segments
 * @return array<string, class-string>
 */
function parseClassUseSegments(array $segments, string $prefix = ''): array
{
    $imports = [];

    foreach ($segments as $segment) {
        if ($segment === [] || in_array($segment[0]->id, [T_FUNCTION, T_CONST], true)) {
            continue;
        }

        $aliasIndex = null;

        foreach ($segment as $index => $token) {
            if ($token->id === T_AS) {
                $aliasIndex = $index;

                break;
            }
        }

        $nameTokens = $aliasIndex === null ? $segment : array_slice($segment, 0, $aliasIndex);
        $name = ltrim(implode('', array_map(fn (PhpToken $token) => $token->text, $nameTokens)), '\\');

        if ($name === '') {
            continue;
        }

        $fqcn = ltrim($prefix . ($prefix === '' ? '' : '\\') . $name, '\\');
        $alias = $aliasIndex === null
            ? Str::afterLast($name, '\\')
            : implode('', array_map(
                fn (PhpToken $token) => $token->text,
                array_slice($segment, $aliasIndex + 1),
            ));

        $imports[$alias] = '\\' . $fqcn;
    }

    return $imports;
}

/**
 * Shorten global method types already imported by the facade.
 *
 * PHP-CS-Fixer's global_namespace_import rule manages this exact set, so
 * matching its representation keeps generated metadata stable after formatting.
 *
 * @param string $method
 * @param \Hypervel\Support\Collection<string, class-string> $imports
 * @return string
 */
function shortenImportedGlobalTypes($method, $imports)
{
    return $imports->reduce(
        fn ($method, $fqcn, $alias) => Str::of($method)
            ->replaceMatches('/(?<![A-Za-z0-9_])' . preg_quote($fqcn, '/') . '(?![A-Za-z0-9_\\\])/', $alias)
            ->toString(),
        $method
    );
}

/**
 * Resolve the default value for the parameter.
 */
function resolveDefaultValue(array $parameter, string $facade, string $declaringClass, string $method): string
{
    // Reflection limitation fix for:
    // - Hypervel\Filesystem\Filesystem::ensureDirectoryExists()
    // - Hypervel\Filesystem\Filesystem::makeDirectory()
    if ($parameter['name'] === '$mode' && $parameter['default'] === 493) {
        return '0755';
    }

    $context = "parameter [{$parameter['name']}] on [{$declaringClass}::{$method}] while generating [{$facade}]";

    return (string) resolveDefaultValueNode($parameter['default'], $context);
}

/**
 * Resolve a PHP value into a PHPDoc constant-expression node.
 */
function resolveDefaultValueNode(mixed $value, string $context): ConstExprNode
{
    return match (true) {
        is_string($value) => new ConstExprStringNode(
            $value,
            preg_match('/[\x00-\x1F\x7F]/', $value) === 1
                ? ConstExprStringNode::DOUBLE_QUOTED
                : ConstExprStringNode::SINGLE_QUOTED,
        ),
        is_int($value) => new ConstExprIntegerNode((string) $value),
        is_float($value) && is_nan($value) => new ConstFetchNode('', 'NAN'),
        $value === INF => new ConstFetchNode('', 'INF'),
        // PHPDoc does not accept -INF, while this equivalent literal remains parseable.
        $value === -INF => new ConstExprFloatNode('-1.0E+999'),
        is_float($value) => new ConstExprFloatNode(
            json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
        ),
        $value === true => new ConstExprTrueNode,
        $value === false => new ConstExprFalseNode,
        $value === null => new ConstExprNullNode,
        $value instanceof UnitEnum => new ConstFetchNode('\\' . $value::class, $value->name),
        is_array($value) => resolveDefaultArrayNode($value, $context),
        default => throw new RuntimeException(sprintf(
            'Unable to render the default value for %s: object [%s] cannot be represented in a PHPDoc @method tag. Exclude the method with ignoredFacadeDocumenterMethods().',
            $context,
            get_debug_type($value),
        )),
    };
}

/**
 * Resolve an array into a PHPDoc constant-expression node.
 *
 * @param array<array-key, mixed> $value
 */
function resolveDefaultArrayNode(array $value, string $context): ConstExprArrayNode
{
    $isList = array_is_list($value);
    $items = [];

    foreach ($value as $key => $item) {
        $items[] = new ConstExprArrayItemNode(
            $isList ? null : resolveDefaultValueNode($key, $context),
            resolveDefaultValueNode($item, $context),
        );
    }

    return new ConstExprArrayNode($items);
}

class ReflectionClassDocblockContext
{
    /**
     * Create a class docblock context.
     */
    public function __construct(private ReflectionClass $class)
    {
    }

    /**
     * Return the class that declares the docblock.
     */
    public function getDeclaringClass(): ReflectionClass
    {
        return $this->class;
    }

    /**
     * Return the class docblock.
     */
    public function getDocComment(): false|string
    {
        return $this->class->getDocComment();
    }

    /**
     * Return the source filename.
     */
    public function getFileName(): false|string
    {
        return $this->class->getFileName();
    }

    /**
     * Return the diagnostic context name.
     */
    public function getName(): string
    {
        return $this->class->getName();
    }

    /**
     * Return the source class.
     */
    public function sourceClass(): ReflectionClass
    {
        return $this->class;
    }
}

/**
 * @mixin \ReflectionMethod
 */
class ReflectionMethodDecorator
{
    /**
     * @param \ReflectionMethod $method
     * @param class-string $sourceClass
     */
    public function __construct(private $method, private $sourceClass)
    {
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        return $this->method->{$name}(...$arguments);
    }

    /**
     * @return \ReflectionMethod
     */
    public function toBase()
    {
        return $this->method;
    }

    /**
     * @return \ReflectionClass
     */
    public function sourceClass()
    {
        return new ReflectionClass($this->sourceClass);
    }
}

class DynamicParameter
{
    /**
     * Create a new dynamic parameter.
     */
    public function __construct(private string $definition)
    {
    }

    /**
     * Return the parameter type.
     */
    public function getType(): null
    {
        return null;
    }

    /**
     * Return the parameter name.
     */
    public function getName(): string
    {
        return Str::of($this->definition)
            ->after('$')
            ->before(' ')
            ->toString();
    }

    /**
     * Determine whether the parameter is optional.
     */
    public function isOptional(): bool
    {
        return true;
    }

    /**
     * Determine whether the parameter is variadic.
     */
    public function isVariadic(): bool
    {
        return Str::contains($this->definition, " ...\${$this->getName()}");
    }

    /**
     * Determine whether a default value is available.
     */
    public function isDefaultValueAvailable(): bool
    {
        return true;
    }

    /**
     * Return the default value.
     */
    public function getDefaultValue(): null
    {
        return null;
    }
}

class UnresolvableType extends Exception
{
    public function __construct(public string $method, string $message)
    {
        parent::__construct($message);
    }
}
