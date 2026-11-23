<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\PHPStan\Collectors\UnusableSchemaDumpCollector;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function array_values;
use function rtrim;
use function sprintf;

/**
 * Reports the schema dumps that model property types could not use, once each, at the dump.
 *
 * A collector supplies them because PHPStan runs a CollectedDataNode rule only when something
 * was collected. Attribute types always read the dumps, so the rule has no switch of its own;
 * disableSchemaScan stops reading them.
 *
 * @implements Rule<CollectedDataNode>
 */
#[AutowiredService]
class UnusableSchemaDumpRule implements Rule
{
    /**
     * Get the node type the rule inspects.
     */
    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * Report each unusable schema dump that model property lookups consulted.
     *
     * @param CollectedDataNode $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        foreach ($node->get(UnusableSchemaDumpCollector::class) as $collected) {
            foreach ($collected as $dumps) {
                foreach ($dumps as $dump) {
                    $errors[$dump['path']] ??= RuleErrorBuilder::message(sprintf('Model property types cannot use this schema dump: %s.', rtrim($dump['message'], '.')))
                        ->tip('Fix the dump or regenerate it with schema:dump. Until then, the columns it defines are unknown.')
                        ->file($dump['path'])
                        ->line($dump['line'])
                        ->identifier('hypervel.schemaDump')
                        ->build();
                }
            }
        }

        return array_values($errors);
    }
}
