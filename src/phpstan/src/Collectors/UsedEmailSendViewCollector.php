<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\Contracts\Mail\Mailer as MailerContract;
use Hypervel\PHPStan\Concerns\CollectsMailViews;
use Hypervel\Support\Facades\Mail;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Type\ObjectType;

/** @implements Collector<CallLike, list<string>> */
#[AutowiredService]
class UsedEmailSendViewCollector implements Collector
{
    use CollectsMailViews;

    /**
     * The lowercase mailer methods that take a view, with the name of their view parameter.
     *
     * Upstream reads only send().
     *
     * @var array<string, string>
     */
    private const array VIEW_PARAMETERS = [
        'send' => 'view',
        'sendnow' => 'mailable',
        'plain' => 'view',
        'render' => 'view',
    ];

    /**
     * Get the node type the collector inspects.
     */
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * Collect the views passed to a mailer call.
     *
     * @param CallLike $node
     * @return null|list<string>
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (
            ! $node instanceof StaticCall
            && ! $node instanceof MethodCall
        ) {
            return null;
        }

        $name = $node->name;

        if (! $name instanceof Identifier) {
            return null;
        }

        $parameter = self::VIEW_PARAMETERS[$name->toLowerString()] ?? null;
        $view = $parameter === null ? null : $node->getArg($parameter, 0)?->value;

        if ($view === null) {
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

        return $this->getMailViews($view, $scope);
    }
}
