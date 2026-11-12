<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\PHPStan\Concerns\CollectsTranslationLookups;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ReflectionProvider;

use function in_array;
use function strtolower;

/**
 * @phpstan-import-type TranslationLookup from CollectsTranslationLookups
 *
 * @implements Collector<FuncCall, TranslationLookup>
 */
#[AutowiredService]
class UsedTranslationFunctionCollector implements Collector
{
    use CollectsTranslationLookups;

    /**
     * Create a new collector instance.
     */
    public function __construct(private ReflectionProvider $reflectionProvider)
    {
    }

    /**
     * Get the node type the collector inspects.
     */
    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * Collect the translation looked up through a translation helper.
     *
     * @param FuncCall $node
     *
     * @return null|TranslationLookup
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (! $node->name instanceof Name) {
            return null;
        }

        // Upstream resolves the name as a class name, which misses a namespace's own function
        // that shadows the global helper.
        $function = strtolower((string) $this->reflectionProvider->resolveFunctionName($node->name, $scope));

        if (! in_array($function, ['__', 'trans', 'trans_choice'], true)) {
            return null;
        }

        return $this->translationLookup(
            $node,
            choice: $function === 'trans_choice',
            acceptsFallback: false,
            scope: $scope,
        );
    }
}
