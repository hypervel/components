<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\Mail\Mailables\Content;
use Hypervel\View\ViewName;
use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;

use function count;
use function in_array;

/** @implements Collector<New_, list<string>> */
#[AutowiredService]
class UsedEmailAlternativeSyntaxViewCollector implements Collector
{
    private const array VIEW_PARAM_NAMES = ['view', 'html', 'text', 'markdown'];

    /**
     * Get the node type the collector inspects.
     */
    public function getNodeType(): string
    {
        return New_::class;
    }

    /**
     * Collect the views passed to a mail content constructor.
     *
     * @param New_ $node
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $node->class;

        if (! $class instanceof Name) {
            return null;
        }

        if (count($node->getArgs()) < 1) {
            return null;
        }

        $class = $scope->resolveName($class);

        if ($class !== Content::class) {
            return null;
        }

        $views = [];

        foreach ($node->getArgs() as $index => $arg) {
            if ($arg->name !== null) {
                $paramName = $arg->name->name;
            } else {
                $paramName = self::VIEW_PARAM_NAMES[$index] ?? null;
            }

            if ($paramName === null || ! in_array($paramName, self::VIEW_PARAM_NAMES, true)) {
                continue;
            }

            if (! $arg->value instanceof String_) {
                continue;
            }

            $views[] = ViewName::normalize($arg->value->value);
        }

        return $views !== [] ? $views : null;
    }
}
