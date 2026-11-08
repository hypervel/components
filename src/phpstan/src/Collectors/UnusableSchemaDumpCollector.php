<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\PHPStan\Properties\ModelPropertyHelper;
use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;

/**
 * Collects the unusable schema dumps of the connections whose models have undeclared properties
 * read, so they are reported at the dumps themselves once the analysis has finished.
 *
 * PHPStan analyses a nullsafe property fetch as a property fetch, so both are collected.
 *
 * @implements Collector<PropertyFetch, list<array{path: string, line: int, message: string}>>
 */
#[AutowiredService]
class UnusableSchemaDumpCollector implements Collector
{
    /**
     * Create a new collector instance.
     */
    public function __construct(private ModelPropertyHelper $modelPropertyHelper)
    {
    }

    /**
     * Get the node type the collector inspects.
     */
    public function getNodeType(): string
    {
        return PropertyFetch::class;
    }

    /**
     * Collect the unusable schema dumps of the models whose attributes the fetch looks up.
     *
     * @param PropertyFetch $node
     *
     * @return null|list<array{path: string, line: int, message: string}>
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (! $node->name instanceof Identifier) {
            return null;
        }

        $dumps = [];

        foreach ($scope->getType($node->var)->getObjectClassReflections() as $classReflection) {
            // A declared property is read directly, without consulting the schema.
            if (! $classReflection->hasNativeProperty($node->name->name)) {
                $dumps = [...$dumps, ...$this->modelPropertyHelper->getUnusableDumps($classReflection)];
            }
        }

        return $dumps === [] ? null : $dumps;
    }
}
