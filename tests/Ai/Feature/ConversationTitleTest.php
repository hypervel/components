<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Agents\ConversationTitleAgent;
use Hypervel\Ai\Ai;
use Hypervel\Ai\Events\AgentPrompted;
use Hypervel\Ai\Models\Conversation;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Str;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingAssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\SupportTitleAgent;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Tests\Ai\Fixtures\fakeGroqResponse;

require_once __DIR__ . '/../Fixtures/helpers.php';

class ConversationTitleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Configure the Groq provider.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.groq.key', 'test-key');
    }

    /**
     * Reject provider requests the test did not fake.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function testAConversationIsTitledByTheTitleAgentOnTheCheapestModelOfItsProvider(): void
    {
        Event::fake();

        Http::fakeSequence()
            ->pushResponse(fakeGroqResponse('Hello there.'))
            ->pushResponse(fakeGroqResponse('Greeting Conversation'));

        $response = (new RememberingAssistantAgent)->forUser((object) ['id' => 1])->prompt('Hello', provider: 'groq');

        $this->assertSame('Greeting Conversation', Conversation::find($response->conversationId)->title);

        Event::assertDispatched(AgentPrompted::class, fn (AgentPrompted $event): bool => $event->prompt->agent instanceof ConversationTitleAgent
            && $event->prompt->prompt === 'Hello'
            && $event->prompt->model === 'openai/gpt-oss-20b'
            && $event->prompt->parentInvocationId === $response->invocationId);
    }

    public function testATitleAgentBoundInTheContainerTitlesTheConversation(): void
    {
        Event::fake();

        $this->app->bind(ConversationTitleAgent::class, SupportTitleAgent::class);

        Http::fakeSequence()
            ->pushResponse(fakeGroqResponse('Hello there.'))
            ->pushResponse(fakeGroqResponse('Billing Refund Request'));

        $this->assertSame('Billing Refund Request', $this->titleOfConversationStartedWith('I want a refund'));

        Event::assertDispatched(AgentPrompted::class, fn (AgentPrompted $event): bool => $event->prompt->agent instanceof SupportTitleAgent
            && $event->prompt->model === 'llama-3.1-8b-instant');
    }

    public function testAFailedTitleRunFallsBackToTheMessage(): void
    {
        Http::fakeSequence()
            ->pushResponse(fakeGroqResponse('Hello there.'))
            ->pushStatus(500);

        $message = str_repeat('word ', 30);

        $this->assertSame(Str::limit($message, 100, preserveWords: true), $this->titleOfConversationStartedWith($message));
    }

    public function testATitleAgentThatCannotBeResolvedFallsBackToTheMessage(): void
    {
        $this->app->bind(ConversationTitleAgent::class, fn (): never => throw new RuntimeException('The title agent could not be built.'));

        Http::fakeSequence()->pushResponse(fakeGroqResponse('Hello there.'));

        $response = (new RememberingAssistantAgent)->forUser((object) ['id' => 1])->prompt('Hello', provider: 'groq');

        $this->assertSame('Hello there.', $response->text);
        $this->assertSame('Hello', Conversation::find($response->conversationId)->title);
    }

    public function testDisabledTitleGenerationShortensTheMessageWithoutATitleRun(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Http::fakeSequence()->pushResponse(fakeGroqResponse('Hello there.'));

        $this->assertSame('word word word word word word word word word...', $this->titleOfConversationStartedWith(str_repeat('word ', 20)));

        Http::assertSentCount(1);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testAFakedConversationFakesItsTitleRunInsteadOfSpendingItsOwnResponses(bool $concurrent): void
    {
        config(['ai.conversations.concurrent_title' => $concurrent]);

        RememberingAssistantAgent::fake(['First answer', 'Second answer']);

        $user = (object) ['id' => 1];

        $first = (new RememberingAssistantAgent)->forUser($user)->prompt('Hello');
        $second = (new RememberingAssistantAgent)->continue($first->conversationId, as: $user)->prompt('Again');

        $this->assertSame('Second answer', $second->text);
        $this->assertSame('Fake response for prompt: Hello', Conversation::find($first->conversationId)->title);

        ConversationTitleAgent::assertPrompted('Hello');
    }

    public function testAFakedTitleAgentTitlesAFakedConversation(): void
    {
        RememberingAssistantAgent::fake(['First answer']);
        ConversationTitleAgent::fake(['Greeting Conversation']);

        $response = (new RememberingAssistantAgent)->forUser((object) ['id' => 1])->prompt('Hello');

        $this->assertSame('First answer', $response->text);
        $this->assertSame('Greeting Conversation', Conversation::find($response->conversationId)->title);
    }

    public function testABoundTitleAgentIsFakedUnderItsOwnClass(): void
    {
        $this->app->bind(ConversationTitleAgent::class, SupportTitleAgent::class);

        RememberingAssistantAgent::fake(['First answer']);
        SupportTitleAgent::fake(['Billing Refund Request']);

        $this->assertSame('Billing Refund Request', $this->titleOfConversationStartedWith('I want a refund'));
    }

    public function testATitleRunsOnTheProviderInstanceOfTheTurn(): void
    {
        // Built in another coroutine, the on-demand provider is not registered under its name in this one...
        [$provider] = parallel([fn (): Provider => Ai::build(['driver' => 'groq', 'key' => 'tenant-key'])]);

        Http::fakeSequence()
            ->pushResponse(fakeGroqResponse('Hello there.'))
            ->pushResponse(fakeGroqResponse('Greeting Conversation'));

        $response = (new RememberingAssistantAgent)->forUser((object) ['id' => 1])->prompt('Hello', provider: $provider);

        $this->assertSame('Greeting Conversation', Conversation::find($response->conversationId)->title);
        $this->assertSame(
            [['Bearer tenant-key'], ['Bearer tenant-key']],
            Http::recorded()->map(fn (array $pair): array => $pair[0]->header('Authorization'))->all(),
        );
    }

    /**
     * Start a conversation with the given message and return its title.
     */
    private function titleOfConversationStartedWith(string $message): string
    {
        $response = (new RememberingAssistantAgent)->forUser((object) ['id' => 1])->prompt($message, provider: 'groq');

        return Conversation::find($response->conversationId)->title;
    }
}
