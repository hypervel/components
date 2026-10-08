<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses;

use Closure;
use Generator;
use Hypervel\Ai\Responses\Data\Citation as CitationData;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Protocols\AgentUserInteractionProtocol;
use Hypervel\Ai\Streaming\Protocols\StreamProtocol;
use Hypervel\Ai\Streaming\Protocols\VercelDataProtocol;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Http\IterableStreamedResponse;
use Hypervel\Http\Request;
use Hypervel\Support\Collection;
use IteratorAggregate;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Traversable;

class StreamableAgentResponse implements IteratorAggregate, Responsable
{
    public ?string $text = null;

    public ?TextUsage $usage = null;

    /** @var Collection<int, StreamEvent> */
    public Collection $events;

    /** @var Collection<int, CitationData> */
    public Collection $citations;

    public ?string $conversationId = null;

    public ?object $conversationUser = null;

    public ?string $userMessageId = null;

    public ?string $assistantMessageId = null;

    public string $reasoning = '';

    protected array $thenCallbacks = [];

    protected array $catchCallbacks = [];

    protected ?StreamProtocol $protocol = null;

    protected ?StreamedAgentResponse $streamedResponse = null;

    protected bool $hasYielded = false;

    protected Meta $meta;

    /** @var null|Closure(Closure): mixed */
    protected ?Closure $contextRunner = null;

    /**
     * Create a new streamable agent response instance.
     *
     * @param Closure(): iterable<StreamEvent> $generator
     */
    public function __construct(
        public string $invocationId,
        protected Closure $generator,
        ?Meta $meta = null,
    ) {
        $this->meta = $meta ?? new Meta;
        $this->events = new Collection;
        $this->citations = new Collection;
    }

    /**
     * Execute a callback over each event.
     */
    public function each(callable $callback): static
    {
        foreach ($this as $event) {
            if ($this->runInContext(fn (): mixed => $callback($event)) === false) {
                break;
            }
        }

        return $this;
    }

    /**
     * Provide a callback that should be invoked when the stream fails.
     */
    public function catch(callable $callback): static
    {
        $this->catchCallbacks[] = $callback;

        return $this;
    }

    /**
     * Provide a callback that should be invoked when the stream completes.
     */
    public function then(callable $callback): static
    {
        // If the response has already been iterated / streamed, invoke now...
        if ($this->streamedResponse instanceof StreamedAgentResponse) {
            $this->runInContext(fn (): mixed => $callback($this->streamedResponse));

            $this->syncConversationFromStreamedResponse();

            return $this;
        }

        $this->thenCallbacks[] = $callback;

        return $this;
    }

    /**
     * Set the conversation UUID for this response.
     */
    public function withinConversation(?string $conversationId, ?object $conversationUser = null): static
    {
        $this->conversationId = $conversationId;
        $this->conversationUser = $conversationUser;

        return $this;
    }

    /**
     * Adopt state from a completed streamed response.
     */
    public function adoptStateFrom(StreamedAgentResponse $response): static
    {
        $this->meta->provider = $response->meta->provider;
        $this->meta->model = $response->meta->model;
        $this->meta->citations = $response->meta->citations;

        if ($response->conversationId !== null) {
            $this->withinConversation($response->conversationId, $response->conversationUser);
        }

        $this->userMessageId = $response->userMessageId;
        $this->assistantMessageId = $response->assistantMessageId;

        return $this;
    }

    /**
     * Use the captured operation context for stream production and callbacks.
     *
     * @param null|Closure(Closure): mixed $runner
     *
     * @internal
     */
    public function usingContext(?Closure $runner): static
    {
        $this->contextRunner = $runner;

        return $this;
    }

    /**
     * Run work in the captured operation context, when one was supplied.
     */
    protected function runInContext(Closure $callback): mixed
    {
        return $this->contextRunner === null ? $callback() : ($this->contextRunner)($callback);
    }

    /**
     * Stream the response using the given stream protocol.
     */
    public function usingProtocol(StreamProtocol $protocol): static
    {
        $this->protocol = $protocol;

        return $this;
    }

    /**
     * Stream the response using the Vercel AI SDK data stream protocol.
     *
     * The message ID is the assistant message being continued, not the "messageId" sent by useChat.
     */
    public function usingVercelDataProtocol(?string $messageId = null): static
    {
        return $this->usingProtocol(new VercelDataProtocol($messageId));
    }

    /**
     * Stream the response using the Agent User Interaction protocol.
     */
    public function usingAgentUserInteractionProtocol(?string $threadId = null, ?string $runId = null): static
    {
        return $this->usingProtocol(new AgentUserInteractionProtocol($threadId, $runId));
    }

    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse(Request $request): Response
    {
        if ($this->protocol instanceof StreamProtocol) {
            return $this->protocol->response($this);
        }

        /** @var IterableStreamedResponse $response */
        $response = response()->stream(function (): Generator {
            foreach ($this as $event) {
                yield 'data: ' . $event . "\n\n";
            }

            yield "data: [DONE]\n\n";
        }, headers: [
            // Without these a proxy may buffer or transcode the body, holding every event back until the run ends...
            'Cache-Control' => 'no-cache, no-transform',
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
        ]);

        return $response->cancelOnDisconnect();
    }

    /**
     * Get an iterator for the object.
     *
     * @return Traversable<int, StreamEvent>
     */
    public function getIterator(): Traversable
    {
        // Use existing events if we've already streamed them once...
        if ($this->streamedResponse !== null) {
            foreach ($this->events as $event) {
                $this->hasYielded = true;

                yield $event;
            }

            return;
        }

        $events = [];
        $start = null;

        // Resolve the stream of the prompt and yield the events...
        try {
            foreach ($this->contextRunner === null ? ($this->generator)() : $this->contextualEvents() as $event) {
                $events[] = $event;

                if ($event instanceof StreamStart) {
                    $start = $event;
                }

                $this->hasYielded = true;

                yield $event;
            }
        } catch (Throwable $exception) {
            // Taken before invoking so a re-iterated stream does not report the same failure twice...
            $callbacks = $this->catchCallbacks;

            $this->catchCallbacks = [];

            $this->runInContext(function () use ($callbacks, $exception): void {
                foreach ($callbacks as $callback) {
                    $callback($exception);
                }
            });

            throw $exception;
        }

        $this->events = new Collection($events);

        if ($start !== null) {
            $this->meta->model = $start->model;
        }

        $this->streamedResponse = new StreamedAgentResponse(
            $this->invocationId,
            $this->events,
            $this->meta,
        );

        $this->text = $this->streamedResponse->text;
        $this->reasoning = $this->streamedResponse->reasoning;
        $this->citations = $this->streamedResponse->meta->citations;
        $this->usage = $this->streamedResponse->usage;

        if ($this->conversationId !== null) {
            $this->streamedResponse->withinConversation(
                $this->conversationId,
                $this->conversationUser
            );
        }

        $this->streamedResponse->withStoredMessages(
            $this->userMessageId,
            $this->assistantMessageId,
        );

        $this->runInContext(function (): void {
            foreach ($this->thenCallbacks as $callback) {
                $callback($this->streamedResponse);
            }
        });

        $this->syncConversationFromStreamedResponse();
    }

    /**
     * Advance and dispose of the producer without leaking its context to consumers.
     *
     * @return Generator<int, StreamEvent>
     */
    protected function contextualEvents(): Generator
    {
        $iterator = (function (): Generator {
            yield from ($this->generator)();
        })();

        // Capture by reference so releasing the iterator also releases its producer here.
        $advance = static function () use (&$iterator): bool {
            $iterator->next();

            return $iterator->valid();
        };

        try {
            $valid = $this->runInContext(fn (): bool => $iterator->valid());

            while ($valid) {
                yield $iterator->current();

                $valid = $this->runInContext($advance);
            }
        } finally {
            $this->runInContext(static function () use (&$iterator): void {
                $iterator = null;
            });
        }
    }

    /**
     * Synchronize the conversation state from the completed streamed response.
     */
    protected function syncConversationFromStreamedResponse(): void
    {
        $this->conversationId = $this->streamedResponse->conversationId;
        $this->conversationUser = $this->streamedResponse->conversationUser;
        $this->userMessageId = $this->streamedResponse->userMessageId;
        $this->assistantMessageId = $this->streamedResponse->assistantMessageId;
    }

    /**
     * Determine whether this response has handed at least one event to a consumer.
     */
    public function hasYielded(): bool
    {
        return $this->hasYielded;
    }
}
