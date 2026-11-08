<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\Contracts\Mail\Mailer as MailerContract;
use Hypervel\Support\Facades\Mail;
use Hypervel\View\ViewName;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Type\ObjectType;

use function count;

/** @implements Collector<CallLike, string> */
#[AutowiredService]
class UsedEmailSendViewCollector implements Collector
{
    /**
     * Get the node type the collector inspects.
     */
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * Collect the view passed to a mailer's send() call.
     *
     * @param CallLike $node
     */
    public function processNode(Node $node, Scope $scope): ?string
    {
        if (
            ! $node instanceof StaticCall
            && ! $node instanceof MethodCall
        ) {
            return null;
        }

        $name = $node->name;

        if (! $name instanceof Identifier || $name->name !== 'send') {
            return null;
        }

        if (count($node->getArgs()) < 1) {
            return null;
        }

        if ($node instanceof StaticCall) {
            $class = $node->class;

            if (! $class instanceof Name) {
                return null;
            }

            $class = $scope->resolveName($class);

            if (! (new ObjectType(Mail::class))->isSuperTypeOf(new ObjectType($class))->yes()) {
                return null;
            }
        } else {
            $type = $scope->getType($node->var);

            if (! (new ObjectType(MailerContract::class))->isSuperTypeOf($type)->yes()) {
                return null;
            }
        }

        foreach ($node->getArgs() as $index => $arg) {
            if ($arg->name !== null ? $arg->name->name !== 'view' : $index !== 0) {
                continue;
            }

            return ! $arg->unpack && $arg->value instanceof String_
                ? ViewName::normalize($arg->value->value)
                : null;
        }

        return null;
    }
}
