<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\Mail\Mailable;
use Hypervel\Mail\Mailables\Content;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\View\ViewName;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Type\ObjectType;

use function count;
use function in_array;

/** @implements Collector<MethodCall, string> */
#[AutowiredService]
final class UsedEmailViewCollector implements Collector
{
    private const array VIEW_METHOD_NAMES = ['view', 'html', 'text', 'markdown'];

    /**
     * Get the node type the collector inspects.
     */
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * Collect the view passed to a mailable, mail message or mail content method.
     *
     * @param MethodCall $node
     */
    public function processNode(Node $node, Scope $scope): ?string
    {
        $name = $node->name;

        if (! $name instanceof Identifier) {
            return null;
        }

        if (! in_array($name->name, self::VIEW_METHOD_NAMES, true)) {
            return null;
        }

        if (count($node->getArgs()) === 0) {
            return null;
        }

        $class = $node->var;

        $type = $scope->getType($class);

        $isContent = (new ObjectType(Content::class))->isSuperTypeOf($type)->yes();

        if ($name->name === 'html' && ! $isContent) {
            return null;
        }

        if (
            ! (new ObjectType(Mailable::class))->isSuperTypeOf($type)->yes()
            && ! (new ObjectType(MailMessage::class))->isSuperTypeOf($type)->yes()
            && ! $isContent
        ) {
            return null;
        }

        // Upstream reads the first argument by position, which misses reordered named arguments. The
        // mailable and mail message text() methods name it textView; the others name it view.
        $parameter = $name->name === 'text' && ! $isContent ? 'textView' : 'view';

        $template = $node->getArg($parameter, 0)?->value;

        if (! $template instanceof String_) {
            return null;
        }

        return ViewName::normalize($template->value);
    }
}
