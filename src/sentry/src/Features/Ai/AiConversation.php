<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Ai;

use Sentry\Tracing\Span;

/**
 * Associate spans with one conversation, including spans started before its ID is known.
 *
 * @internal
 */
class AiConversation
{
    /**
     * The spans started while the new conversation has no ID yet.
     *
     * @var list<Span>
     */
    private array $pendingSpans = [];

    /**
     * Create an association for a conversation in one trace.
     *
     * A new conversation starts without an ID, since hypervel/ai creates it when the first turn ends.
     */
    public function __construct(
        public readonly string $traceId,
        public private(set) ?string $conversationId = null,
    ) {
    }

    /**
     * Resolve a pending conversation without overwriting an inner invocation's ID.
     */
    public function resolve(string $conversationId): void
    {
        $this->conversationId = $conversationId;

        foreach ($this->pendingSpans as $pendingSpan) {
            if (! isset($pendingSpan->getData()['gen_ai.conversation.id'])) {
                self::setConversationId($pendingSpan, $conversationId);
            }
        }

        $this->pendingSpans = [];
    }

    /**
     * Associate a span from this trace with the conversation.
     */
    public function attach(Span $span): void
    {
        if ($this->traceId !== (string) $span->getTraceId()) {
            return;
        }

        if ($this->conversationId === null) {
            $this->pendingSpans[] = $span;

            return;
        }

        self::setConversationId($span, $this->conversationId);
    }

    /**
     * Attach the conversation ID to a span.
     */
    private static function setConversationId(Span $span, string $conversationId): void
    {
        $data = $span->getData();
        $data['gen_ai.conversation.id'] = $conversationId;
        $span->setData($data);
    }
}
