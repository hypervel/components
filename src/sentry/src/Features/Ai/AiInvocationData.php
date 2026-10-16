<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Ai;

use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;
use WeakReference;

/**
 * @internal
 */
class AiInvocationData
{
    public ?Span $activeChatSpan = null;

    public ?int $activeStep = null;

    public bool $hasStepMetadata = false;

    /** @var list<Span> Chat and tool spans that can receive a late response conversation ID. */
    public array $spans = [];

    /**
     * Create the state owned by one agent invocation.
     *
     * @param WeakReference<AiTracingScope> $owner
     */
    public function __construct(
        public readonly Span $span,
        public readonly WeakReference $owner,
        public readonly AiInvocationMeta $meta,
        public readonly bool $isStreaming,
        public readonly ?AiConversation $conversation,
        public readonly bool $ownsConversation,
        public readonly bool $isNested,
        public readonly ?AiConversation $previousConversation,
    ) {
    }

    /**
     * Apply a response's conversation ID to this invocation's own spans.
     */
    public function setConversationIdOnSpans(string $conversationId): void
    {
        $data = $this->span->getData();
        $data['gen_ai.conversation.id'] = $conversationId;
        $this->span->setData($data);

        foreach ($this->spans as $span) {
            $data = $span->getData();
            $data['gen_ai.conversation.id'] = $conversationId;
            $span->setData($data);
        }
    }

    /**
     * Finish the current model step through its owning scope.
     */
    public function finishActiveChatSpan(?SpanStatus $status = null): void
    {
        $chatSpan = $this->activeChatSpan;
        if ($chatSpan === null) {
            return;
        }

        $this->activeChatSpan = null;
        $this->activeStep = null;

        AiTracingScope::finishSpan($chatSpan, $this->owner->get(), $status ?? SpanStatus::ok());
    }

    /**
     * Finish the invocation and any active model step.
     */
    public function finish(SpanStatus $status): void
    {
        // Failed-over attempts remain available until the next attempt replaces them.
        if ($this->span->getEndTimestamp() !== null) {
            return;
        }

        $this->finishActiveChatSpan($status);
        $owner = $this->owner->get();
        AiTracingScope::finishSpan($this->span, $owner, $status);

        // Restore the caller's selection only if this nested call still owns the current one.
        if ($this->isNested && $this->ownsConversation && $owner !== null
            && $owner->conversation === $this->conversation) {
            $owner->conversation = $this->previousConversation;
        }
    }
}
