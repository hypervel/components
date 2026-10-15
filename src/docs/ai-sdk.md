# Hypervel AI SDK

- [Introduction](#introduction)
- [Installation](#installation)
    - [Configuration](#configuration)
    - [Custom Base URLs](#custom-base-urls)
    - [OpenAI-Compatible Providers](#openai-compatible-providers)
    - [On-Demand Providers](#on-demand-providers)
    - [Resolving Provider Configuration](#resolving-provider-configuration)
    - [Amazon Bedrock](#amazon-bedrock)
    - [Provider Support](#provider-support)
- [Agents](#agents)
    - [Prompting](#prompting)
    - [Conversation Context](#conversation-context)
        - [Conversation Partitions](#conversation-partitions)
        - [Custom Conversation Stores](#custom-conversation-stores)
    - [Structured Output](#structured-output)
    - [Attachments](#attachments)
    - [Streaming](#streaming)
        - [Capturing Application Context](#capturing-application-context)
    - [Broadcasting](#broadcasting)
    - [Queueing](#queueing)
    - [Tools](#tools)
    - [Deferred Tool Loading](#deferred-tool-loading)
    - [File Storage Tools](#file-storage-tools)
    - [MCP Tools](#mcp-tools)
    - [Skills](#skills)
    - [Provider Tools](#provider-tools)
    - [Sub-Agents](#sub-agents)
    - [Middleware](#middleware)
    - [Anonymous Agents](#anonymous-agents)
    - [Agent Configuration](#agent-configuration)
    - [Provider Options](#provider-options)
    - [Prompt Caching](#prompt-caching)
- [Human Tool Approval](#human-tool-approval)
    - [Complete Approval Flow](#complete-approval-flow)
- [Images](#images)
- [Audio (TTS)](#audio)
- [Transcription (STT)](#transcription)
- [Text Summarization](#text-summarization)
- [Embeddings](#embeddings)
    - [Multimodal Embeddings](#multimodal-embeddings)
    - [Querying Embeddings](#querying-embeddings)
    - [Caching Embeddings](#caching-embeddings)
        - [Cache Scopes](#embedding-cache-scopes)
- [Reranking](#reranking)
- [Classification](#classification)
    - [Yes or No Decisions](#yes-or-no-decisions)
    - [Choosing From Collections](#choosing-from-collections)
- [Files](#files)
- [Vector Stores](#vector-stores)
    - [Adding Files to Stores](#adding-files-to-stores)
- [Usage](#usage)
- [Failover](#failover)
- [Testing](#testing)
    - [Agents](#testing-agents)
    - [Images](#testing-images)
    - [Audio](#testing-audio)
    - [Transcriptions](#testing-transcriptions)
    - [Embeddings](#testing-embeddings)
    - [Reranking](#testing-reranking)
    - [Classification](#testing-classification)
    - [Files](#testing-files)
    - [Vector Stores](#testing-vector-stores)
- [Events](#events)

<a name="introduction"></a>
## Introduction

The [Hypervel AI SDK](https://github.com/hypervel/ai) provides a unified, expressive API for interacting with AI providers such as OpenAI, Anthropic, Gemini, and more. With the AI SDK, you can build intelligent agents with tools and structured output, generate images, synthesize and transcribe audio, create vector embeddings, and much more — all using a consistent, Laravel-style interface.

<a name="installation"></a>
## Installation

You can install the Hypervel AI SDK via Composer:

```shell
composer require hypervel/ai
```

Next, you should publish the AI SDK configuration and migration files using the `vendor:publish` Artisan command:

```shell
php artisan vendor:publish --provider="Hypervel\Ai\AiServiceProvider"
```

Finally, you should run your application's database migrations. This will create the `agent_conversations` and `agent_conversation_messages` tables that the AI SDK uses to power its conversation storage:

```shell
php artisan migrate
```

<a name="configuration"></a>
### Configuration

You may define your AI provider credentials in your application's `config/ai.php` configuration file or as environment variables in your application's `.env` file:

```ini
ANTHROPIC_API_KEY=
AZURE_OPENAI_API_KEY=
COHERE_API_KEY=
DEEPSEEK_API_KEY=
ELEVENLABS_API_KEY=
GEMINI_API_KEY=
GROQ_API_KEY=
MISTRAL_API_KEY=
OLLAMA_API_KEY=
OPENAI_API_KEY=
OPENAI_COMPATIBLE_API_KEY=
OPENAI_COMPATIBLE_URL=
OPENROUTER_API_KEY=
JINA_API_KEY=
TYPESAFE_API_KEY=
VOYAGEAI_API_KEY=
XAI_API_KEY=
```

The default models used for text, images, audio, transcription, and embeddings may also be configured in your application's `config/ai.php` configuration file.

<a name="http-connections"></a>
#### HTTP Connections

The SDK registers an [HTTP connection](/docs/{{version}}/http-client#connections) for each configured provider, allowing requests to reuse connections without sharing credentials or request headers. You may customize these connections in your application's service provider `boot` method:

```php
use Hypervel\Support\Facades\Http;

public function boot(): void
{
    Http::registerConnection('ai-providers.openai', [
        'connect_timeout' => 10,
    ]);
}
```

Connection names use the provider's configured name, such as `ai-providers.openai`. On-demand providers use the shared `ai-providers` connection. When Bedrock assumes an IAM role, its AWS STS requests also use the shared connection. Continue supplying API keys and provider-specific headers through the AI provider configuration; they remain separate for each request.

Before sending a real provider request, the HTTP client releases idle database sessions so they remain available to other requests while the model responds. Transactions, cursors, and pinned sessions remain held. If a tool relies on session-specific database state, use the database's [session pinning API](/docs/{{version}}/database#releasing-and-pinning-connections). Avoid holding a transaction open while waiting for a provider.

<a name="custom-base-urls"></a>
### Custom Base URLs

By default, the Hypervel AI SDK connects directly to each provider's public API endpoint. However, you may need to route requests through a different endpoint - for example, when using a proxy service to centralize API key management, implement rate limiting, or route traffic through a corporate gateway.

You may configure custom base URLs by adding a `url` parameter to your provider configuration:

```php
'providers' => [
    'openai' => [
        'driver' => 'openai',
        'key' => env('OPENAI_API_KEY'),
        'url' => env('OPENAI_URL'),
    ],

    'anthropic' => [
        'driver' => 'anthropic',
        'key' => env('ANTHROPIC_API_KEY'),
        'url' => env('ANTHROPIC_BASE_URL'),
    ],
],
```

This is useful when routing requests through a proxy service (such as LiteLLM or Azure OpenAI Gateway) or using alternative endpoints.

Custom base URLs are supported for the following providers: OpenAI, Anthropic, Gemini, Groq, Cohere, DeepSeek, xAI, and OpenRouter.

<a name="openai-compatible-providers"></a>
### OpenAI-Compatible Providers

If you are using an OpenAI-compatible API, such as LM Studio, vLLM, Together, Fireworks, or a local gateway, you may configure an `openai-compatible` provider. The `url` option is required, while the `key` option is optional and will be sent as a bearer token when present:

```php
'providers' => [
    'local' => [
        'driver' => 'openai-compatible',
        'url' => env('LOCAL_AI_URL'),
        'key' => env('LOCAL_AI_API_KEY'),
    ],
],
```

Once configured, you may use the named provider like any other provider:

```php
agent()->prompt('What is Hypervel?', provider: 'local', model: 'local-model');
```

You may also configure a default text model for the provider so that you do not need to pass a model explicitly:

```php
'local' => [
    'driver' => 'openai-compatible',
    'url' => env('LOCAL_AI_URL'),
    'key' => env('LOCAL_AI_API_KEY'),
    'models' => [
        'text' => [
            'default' => env('LOCAL_AI_MODEL'),
        ],
    ],
],
```

You may add custom HTTP headers to every outgoing request for the provider by defining a `headers` array in its configuration. This is useful when an endpoint requires an additional identifying or authentication header beyond the bearer token:

```php
'local' => [
    'driver' => 'openai-compatible',
    'url' => env('LOCAL_AI_URL'),
    'key' => env('LOCAL_AI_API_KEY'),
    'headers' => [
        'X-Tenant-Id' => env('LOCAL_AI_TENANT_ID'),
    ],
],
```

OpenAI-compatible providers support text generation, streaming, tools, structured output, image attachments, embeddings, and transcription. If your endpoint requires additional request body fields, provide them using [provider options](#provider-options).

<a name="openai-compatible-embeddings"></a>
#### OpenAI-Compatible Embeddings

Since arbitrary endpoints have no known models, you must configure a default embeddings model to use `embeddings()` with an OpenAI-compatible provider. You may also configure a fixed dimensions value; if omitted, the request is sent without a `dimensions` parameter and the model's native dimensions are used.

```php
'local' => [
    'driver' => 'openai-compatible',
    'url' => env('LOCAL_AI_URL'),
    'key' => env('LOCAL_AI_API_KEY'),
    'models' => [
        'embeddings' => [
            'default' => 'text-embedding-qwen3-embedding-0.6b',
            'dimensions' => 1024, // optional
        ],
    ],
],
```

<a name="openai-compatible-transcriptions"></a>
#### OpenAI-Compatible Transcriptions

Likewise, you must configure a default transcription model to use `Transcription` with an OpenAI-compatible provider. The audio will be uploaded to the endpoint's `/audio/transcriptions` route as a standard multipart request:

```php
'local' => [
    'driver' => 'openai-compatible',
    'url' => env('LOCAL_AI_URL'),
    'key' => env('LOCAL_AI_API_KEY'),
    'models' => [
        'transcription' => [
            'default' => 'whisper-1',
        ],
    ],
],
```

> [!NOTE]
> OpenAI-compatible and Groq providers do not support diarization. Invoking the `diarize` method when using these providers will throw an exception.

<a name="on-demand-providers"></a>
### On-Demand Providers

Sometimes you may need to use provider credentials that are not defined in your application's configuration file, such as API keys that are stored in your database for each tenant of a multi-tenant application. You may use the `Ai::build` method to create a provider from a configuration array. The array should have the same structure as a provider entry in your application's `config/ai.php` configuration file:

```php
use App\Ai\Agents\SalesCoach;
use Hypervel\Ai\Ai;

$response = (new SalesCoach)->prompt('Analyze this sales transcript...', provider: [
    Ai::build([
        'driver' => 'anthropic',
        'key' => $user->anthropic_key,
    ]),
]);
```

On-demand providers may be used anywhere a provider is accepted, including [failover](#failover) lists and when generating images, audio, transcriptions, and embeddings. To specify a model for an on-demand provider, include a `models` array in its configuration:

```php
$response = (new SalesCoach)->prompt('Analyze this sales transcript...', provider: [
    Ai::build(['driver' => 'anthropic', 'key' => $user->anthropic_key]),
    Ai::build([
        'driver' => 'openai',
        'key' => $user->openai_key,
        'models' => ['text' => ['default' => 'gpt-6']],
    ]),
]);
```

When an agent should always use an on-demand provider, you may return the provider from the agent's `provider` method. Since the provider is rebuilt when the agent is used, this approach also works well for [queued](#queueing) agents:

```php
use Hypervel\Ai\Ai;
use Hypervel\Ai\Providers\Provider;

public function provider(): Provider
{
    return Ai::build($this->user->aiConfiguration());
}
```

> [!NOTE]
> You may pass an on-demand provider directly to `provider:`, or include it in a failover array. If you give an on-demand provider a `name` in its configuration array, the name may not match a built-in provider or a provider defined in your `config/ai.php` configuration file.

Bedrock reuses the AWS credentials it resolves for each configured provider. On-demand providers, and configured providers when `Ai::resolveProviderConfigUsing` is registered, resolve credentials again for each new provider. Assuming a role or fetching credentials from a remote source can therefore add a network request; loading static credentials from environment variables or a shared credentials file does not.

<a name="resolving-provider-configuration"></a>
### Resolving Provider Configuration

If your application resolves credentials or model settings for the current account, you may register a provider configuration resolver in a service provider's `boot` method. The resolver receives the provider name and its configured values and returns the configuration for that operation:

```php
use App\Support\AccountContext;
use Hypervel\Ai\Ai;

public function boot(AccountContext $accounts): void
{
    Ai::resolveProviderConfigUsing(
        fn (string $name, array $config): array => $accounts->providerConfig($name, $config),
    );
}
```

In this example, `AccountContext` is an application service that reads the current account without storing request-specific values on a shared instance. Return the complete provider configuration, including its `driver` and any model defaults you want to retain. Explicitly built on-demand providers retain their own configuration.

When this resolver is registered, configured providers, including those with custom creators, are rebuilt on every lookup. Keep the resolver and any custom creators inexpensive and stateless. Avoid repeating database or remote credential lookups when your application already has the values for the current operation.

Register the resolver once during application boot, not while handling a request. The same rule applies to the conversation partition, embedding cache scope, and context capture hooks described below; registering a hook twice throws a `LogicException`. Do not change global configuration to select credentials for a request.

<a name="amazon-bedrock"></a>
### Amazon Bedrock

The Bedrock provider requires the AWS SDK for PHP:

```shell
composer require aws/aws-sdk-php:^3.369.1
```

Configure your region using `AWS_BEDROCK_REGION`. You may authenticate with `AWS_BEARER_TOKEN_BEDROCK`, explicit AWS access credentials, or the AWS SDK's default credential provider chain. The `bedrock` entry in `config/ai.php` also supports assuming a role through its `assume_role` settings. The AWS SDK handles credential discovery and retries; Bedrock requests and explicit role-assumption requests use Hypervel's HTTP client.

> [!WARNING]
> The AWS SDK's event decoder currently has issues with partial checksum reads and the final event at end of stream. Bedrock streaming can fail with a checksum error, omit final usage, or report an incomplete stream instead of a final provider error. See [AWS SDK #3371](https://github.com/aws/aws-sdk-php/issues/3371) and [#3374](https://github.com/aws/aws-sdk-php/issues/3374).

<a name="provider-support"></a>
### Provider Support

The AI SDK supports a variety of providers across its features. The following table summarizes which providers are available for each feature:

<div class="overflow-auto">

| Feature | Providers |
|---|---|
| Text | OpenAI, OpenAI Compatible, Anthropic, Gemini, Azure, Bedrock, Cohere, Groq, xAI, DeepSeek, Mistral, Ollama, OpenRouter |
| Images | OpenAI, Gemini, xAI, Azure, Bedrock, OpenRouter |
| TTS | OpenAI, ElevenLabs, Gemini, Mistral, OpenRouter |
| STT | OpenAI, OpenAI Compatible, ElevenLabs, Groq, Mistral, Gemini, OpenRouter |
| Embeddings | OpenAI, OpenAI Compatible, Gemini, Azure, Bedrock, Cohere, Mistral, Jina, VoyageAI, Ollama, OpenRouter |
| Reranking | Cohere, Jina, VoyageAI, Bedrock, OpenRouter |
| Classification | TypeSafe, OpenAI, OpenRouter |
| Files | OpenAI, Anthropic, Gemini, Azure, OpenRouter |
| Vector Stores | OpenAI, Gemini, Azure |

</div>

The `Hypervel\Ai\Enums\Lab` enum may be used to reference providers throughout your code instead of using plain strings:

```php
use Hypervel\Ai\Enums\Lab;

Lab::Anthropic;
Lab::OpenAI;
Lab::OpenAiCompatible;
Lab::Gemini;
// ...
```

<a name="agents"></a>
## Agents

Agents are the fundamental building block for interacting with AI providers in the Hypervel AI SDK. Each agent is a dedicated PHP class that encapsulates the instructions, conversation context, tools, and output schema needed to interact with a large language model. Think of an agent as a specialized assistant — a sales coach, a document analyzer, a support bot — that you configure once and prompt as needed throughout your application.

You can create an agent via the `make:agent` Artisan command:

```shell
php artisan make:agent SalesCoach

php artisan make:agent SalesCoach --structured
```

Within the generated agent class, you can define the system prompt / instructions, message context, available tools, and output schema (if applicable):

```php
<?php

namespace App\Ai\Agents;

use App\Ai\Tools\RetrievePreviousTranscripts;
use App\Models\History;
use App\Models\User;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Conversational;
use Hypervel\Ai\Contracts\HasStructuredOutput;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Promptable;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Stringable;

class SalesCoach implements Agent, Conversational, HasTools, HasStructuredOutput
{
    use Promptable;

    public function __construct(public User $user) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return 'You are a sales coach, analyzing transcripts and providing feedback and an overall sales strength score.';
    }

    /**
     * Get the list of messages comprising the conversation so far.
     */
    public function messages(): iterable
    {
        return History::where('user_id', $this->user->id)
            ->latest()
            ->limit(50)
            ->get()
            ->reverse()
            ->map(function ($message) {
                return new Message($message->role, $message->content);
            })->all();
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            new RetrievePreviousTranscripts,
        ];
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'feedback' => $schema->string()->required(),
            'score' => $schema->integer()->min(1)->max(10)->required(),
        ];
    }
}
```

<a name="prompting"></a>
### Prompting

To prompt an agent, first create an instance using the `make` method or standard instantiation, then call `prompt`:

```php
$response = (new SalesCoach)
    ->prompt('Analyze this sales transcript...');

return (string) $response;
```

The `make` method resolves your agent from the container, allowing automatic dependency injection. You may also pass arguments to the agent's constructor:

```php
$agent = SalesCoach::make(user: $user);
```

Agent instances are fresh when resolved from the container. Keep each configured instance with the request or operation that owns it; do not bind an agent carrying user data as a singleton.

By passing additional arguments to the `prompt` method, you may override the default provider, model, or HTTP timeout when prompting:

```php
$response = (new SalesCoach)->prompt(
    'Analyze this sales transcript...',
    provider: Lab::Anthropic,
    model: 'claude-sonnet-5',
    timeout: 120,
);
```

<a name="raw-http-responses"></a>
#### Raw HTTP Responses

Every response returned from a text-generating agent exposes the raw HTTP response from the underlying provider API call via a `raw` property. This gives you access to provider-specific information that isn't part of the AI SDK's generic response - rate-limit headers, request IDs, or other exact payload fields:

```php
$response = (new SalesCoach)->prompt('Analyze this sales transcript...');

$response->raw; // Hypervel\Http\Client\Response|null

$response->raw->header('X-RateLimit-Remaining-Requests');
$response->raw->json('id');
```

In a tool-call loop, each step retains the raw response of its own request:

```php
foreach ($response->steps as $step) {
    $step->raw?->header('X-RateLimit-Remaining-Requests');
}
```

> **Note:** The `raw` property is `null` when streaming a response, when using the Bedrock provider, and on faked responses unless one is provided explicitly via `withRawResponse`.

<a name="conversation-context"></a>
### Conversation Context

If your agent implements the `Conversational` interface, you may use the `messages` method to return the previous conversation context, if applicable:

```php
/**
 * Get the list of messages comprising the conversation so far.
 */
public function messages(): iterable
{
    return $this->user->history()
        ->latest()
        ->limit(50)
        ->get()
        ->reverse()
        ->map(fn ($message) => new Message(
            $message->role, $message->content,
        ))->all();
}
```

If your agent does not implement the `Conversational` interface, you may use the `withMessages` method to provide the conversation history for a single run, such as a history posted by your application's frontend:

```php
use Hypervel\Ai\Messages\Message;

$response = (new SalesCoach)
    ->withMessages([
        new Message('user', 'Analyze this sales transcript...'),
        new Message('assistant', 'The rep never asked for the close.'),
    ])
    ->prompt('What should they say next time?');
```

Agents that implement the `Conversational` interface load their own history, so combining the two approaches will throw a `LogicException`.

Conversation history is read once when you call `prompt` or `stream`, and reused across provider attempts. A stream retains that history and its selected conversation even if you switch the agent to another conversation before consuming it. Completing that stream does not overwrite the agent's newer selection.

<a name="remembering-conversations"></a>
#### Remembering Conversations

> **Warning:** Before using the `RemembersConversations` trait, you should publish and run the AI SDK migrations using the `vendor:publish` Artisan command. These migrations will create the necessary database tables to store conversations.

If you would like Hypervel to automatically store and retrieve conversation history for your agent, you may use the `RemembersConversations` trait. This trait provides a simple way to persist conversation messages to the database without manually implementing the `Conversational` interface:

```php
<?php

namespace App\Ai\Agents;

use Hypervel\Ai\Concerns\RemembersConversations;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Conversational;
use Hypervel\Ai\Promptable;

class SalesCoach implements Agent, Conversational
{
    use Promptable, RemembersConversations;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a sales coach...';
    }
}
```

When using the `RemembersConversations` trait, do not manually define a `messages` method in your agent class. If a `messages` method is present, it will take precedence over the trait's implementation and conversation history will not be loaded from the database.

To start a new conversation for a user, call the `forUser` method before prompting:

```php
$response = (new SalesCoach)->forUser($user)->prompt('Hello!');

$conversationId = $response->conversationId;
```

The conversation ID is returned on the response and can be stored for future reference. If you would like to retrieve all of a user's conversations using Eloquent, you may add the `HasConversations` trait to your user model:

```php
<?php

namespace App\Models;

use Hypervel\Ai\Concerns\HasConversations;
use Hypervel\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasConversations;
}
```

Once the trait has been added to your model, you may retrieve and query the user's conversations via the `conversations` relationship:

```php
$conversations = $user->conversations()
    ->latest('updated_at')
    ->paginate(20);
```

To continue an existing conversation, use the `continue` method:

```php
$response = (new SalesCoach)
    ->continue($conversationId, as: $user)
    ->prompt('Tell me more about that.');
```

The `continueOrStart` method may be used to continue the given conversation, or start a new conversation if the given ID is `null`:

```php
$response = (new SalesCoach)
    ->continueOrStart($conversationId, as: $user)
    ->prompt('Hello!');
```

When using the `RemembersConversations` trait, previous messages are automatically loaded and included in the conversation context when prompting. New messages (both user and assistant) are automatically stored after each interaction. Each response also contains the IDs of the conversation and messages that were stored:

```php
$response->conversationId;
$response->userMessageId;
$response->assistantMessageId;
```

The database conversation store saves each turn in a short transaction, so a storage failure won't leave only half of the turn in your history. Provider requests and title generation finish before this transaction begins.

The store uses your default database connection unless `ai.conversations.connection` names another connection. You may customize the table names through `ai.conversations.tables`; set these values before running the published migrations.

<a name="conversation-titles"></a>
#### Conversation Titles

The SDK generates a title when it creates a conversation. To use the opening message as the title instead, set `conversations.generate_title` to `false` in your `config/ai.php` configuration file.

By default, title generation runs after the agent responds. You may generate titles alongside the agent's response by enabling `conversations.concurrent_title`:

```php
'conversations' => [
    'generate_title' => true,
    'concurrent_title' => true,
],
```

When starting a new conversation, concurrent title generation runs alongside the main response. For streamed responses, it starts when the stream is consumed. The SDK waits for the title before saving the turn and cancels unfinished title requests when the operation is abandoned. Enabling this option may incur a title request even if the agent fails, including a separate title request for each failover attempt.

Concurrent titles run in a separate coroutine with a copy of the caller's `Context` values. They use the caller's current default database connection, such as one selected with `DB::usingConnection()`. If your provider configuration or other hooks depend on additional application context, register [context capture](#capturing-application-context) so that work sees the same values.

Agent fakes retain their usual response order by generating titles serially. When using HTTP fakes with concurrent titles, match requests by their content instead of relying on the order of a response sequence. The title and main requests can use the same provider endpoint.

<a name="conversation-participants"></a>
#### Conversation Participants

Although users are the most common conversation participants, conversations may belong to any Eloquent model. Use the `forParticipant` method to start a conversation for another type of model:

```php
$response = (new SalesCoach)
    ->forParticipant($team)
    ->prompt('Review our latest sales results.');
```

The participant's morph class and primary key are stored with the conversation. Therefore, models of different types that have the same primary key, such as `User` ID `1` and `Team` ID `1`, have separate conversation histories. The `forUser` method is an alias for `forParticipant`.

You may continue the participant's most recent conversation with the agent using the `continueLastConversation` method. Conversations are scoped to the agent, so only conversations that the agent participated in will be continued:

```php
$response = (new SalesCoach)
    ->continueLastConversation($team)
    ->prompt('Tell me more about that.');
```

When continuing a specific conversation, pass the participant to the `continue` method:

```php
$response = (new SalesCoach)
    ->continue($conversationId, as: $team)
    ->prompt('Tell me more about that.');
```

The `HasConversations` trait may be added to any Eloquent model that participates in conversations. The resulting `conversations` relationship is a polymorphic relationship scoped to that model's type and primary key. You may also access the participant that owns a conversation through its inverse relationship:

```php
$conversations = $team->conversations;

$participant = $conversation->participant;
```

If your application uses multiple participant model types, you should consider defining an [Eloquent morph map](/docs/{{version}}/eloquent-relationships#custom-polymorphic-types) so that stored participant types are not coupled to your model class names.

> [!WARNING]
> The `continue` and `continueOrStart` methods do not verify that the given participant owns the conversation. Your application should authorize access to the conversation before continuing it.

<a name="inspecting-stored-conversations"></a>
#### Inspecting Stored Conversations

When displaying a conversation to your users, you often need details such as message IDs, timestamps, and tool calls. You may resolve the conversation store from the service container to read the stored messages without querying the AI SDK's tables directly:

```php
use Hypervel\Ai\Contracts\ConversationStore;

$store = app(ConversationStore::class);
```

Messages are paginated newest first using a cursor and are returned as `StoredMessage` instances, which contain each message's ID, timestamps, usage, metadata, and attachments:

```php
$messages = $store->paginateConversationMessages($conversationId, perPage: 25);

foreach ($messages as $message) {
    $message->id;
    $message->role;
    $message->content;
    $message->createdAt;
    $message->usage;
    $message->status;
}
```

Each turn, which consists of a user prompt and the assistant's reply, is stored as a list of steps. A step is a single request to the provider, so a turn in which the model calls tools will contain several steps. Each tool result is recorded on the tool call that produced it. The `toolCalls`, `providerToolCalls`, and `toolResults` methods flatten these steps in order, so you do not need to traverse them yourself:

```php
$message->steps;

$message->toolCalls();
$message->providerToolCalls();
$message->toolResults();
```

A tool call contains a `result` once it has been executed. Tool calls that contain an `approval_reason` but no `result` are still awaiting a [tool approval](#human-tool-approval).

The `status` property contains a `Hypervel\Ai\Enums\MessageStatus` instance. A turn that failed partway through is stored as `Failed` along with the steps it had already completed, so tool calls that ran before the failure remain in the history. When the conversation continues, any tool call without a recorded result is sent to the model marked as interrupted, since Hypervel cannot determine whether it ran.

Before continuing a conversation via an ID provided by your application's frontend, you should verify that the conversation was stored for the given participant:

```php
abort_unless($store->conversationBelongsTo(
    $conversationId, $user->getMorphClass(), $user->getKey()
), 403);
```

If the most recent turn is paused awaiting [tool approval](#human-tool-approval), you may render its pending tool calls after a page reload without resuming the run:

```php
foreach ($store->pendingApprovalsFor($conversationId) as $approval) {
    // $approval->id, $approval->tool, $approval->arguments, $approval->reason...
}
```

These methods are defined by the `PaginatesConversations`, `VerifiesConversationOwnership`, and `ResolvesPendingApprovals` contracts. The included database store implements all three, while a custom store may implement only the contracts it needs.

<a name="restricting-conversation-writes"></a>
#### Restricting Conversation Writes

You may extend `DatabaseConversationStore` and override its `ensureWriteAllowed` method to restrict writes while still allowing history to be read. The store calls this method before saving conversations, messages, and tool approval results. For example, your application may define a `write-conversations` gate and check it in a custom store:

```php
namespace App\Ai\Storage;

use Hypervel\Ai\Storage\DatabaseConversationStore;
use Hypervel\Contracts\Auth\Access\Gate;

class AuthorizedConversationStore extends DatabaseConversationStore
{
    public function __construct(protected Gate $gate, ?string $connection = null)
    {
        parent::__construct($connection);
    }

    /**
     * Authorize conversation writes.
     */
    protected function ensureWriteAllowed(): void
    {
        $this->gate->authorize('write-conversations');
    }
}
```

Bind your store to `Hypervel\Ai\Contracts\ConversationStore` in an application service provider, passing the configured `ai.conversations.connection` to its constructor. This hook applies to writes through the store, including approval claims; direct model and query builder writes need their own authorization.

<a name="conversation-partitions"></a>
#### Conversation Partitions

Applications that keep several accounts' conversations in the same tables may register a partition column and resolver during boot:

```php
use Hypervel\Ai\Ai;
use Hypervel\Support\Facades\Context;

public function boot(): void
{
    Ai::resolveConversationPartitionUsing(
        'account_id',
        fn (): int|string|null => Context::get('account_id'),
    );
}
```

Add the account ID to [Context](/docs/{{version}}/context) in your application's middleware before using conversations. Context carries it into queued jobs, including model restoration. The resolver may return an integer or string. Once partitioning is configured, a null or empty value throws instead of reading unpartitioned data. The database store and conversation models scope their reads and writes to that value. Partitioning does not replace authorization between users within the same account.

The published migration is unpartitioned. Before running it, add your partition column to both tables, using the same type as your account key. Prepend it to each conversation and message lookup index. For UUID accounts, the relevant additions to the conversation table are:

```php
$table->uuid('account_id');
$table->unique(['account_id', 'id']);
$table->index(['account_id', 'participant_type', 'participant_id', 'updated_at'], 'participant_updated_at_index');
```

In the messages table, replace the single-column conversation foreign key with a composite foreign key so a message cannot reference another account's conversation:

```php
$table->uuid('account_id');
$table->uuid('conversation_id');
$table->foreign(['account_id', 'conversation_id'])
    ->references(['account_id', 'id'])
    ->on($conversationsTable)
    ->cascadeOnDelete();

$table->index(['account_id', 'conversation_id', 'id'], 'conversation_messages_index');
$table->index(['account_id', 'participant_type', 'participant_id', 'agent', 'id'], 'participant_index');
$table->index(['account_id', 'conversation_id', 'status', 'id'], 'conversation_status_index');
$table->index(['account_id', 'conversation_id', 'has_replay_blocks', 'id'], 'conversation_replay_index');
```

Replace the original indexes with these partitioned versions. Keep the remaining columns from the published migration. For existing data, assign and verify ownership before making the partition columns required and adding the composite constraint.

Model `save` and `saveOrIgnore` calls stamp the current partition. Raw or bulk inserts, including `fillAndInsert` methods, bypass these checks and must supply the correct partition values themselves. A loaded model cannot be moved to another partition through an ordinary save.

<a name="custom-conversation-stores"></a>
#### Custom Conversation Stores

To use another storage backend, implement `Hypervel\Ai\Contracts\ConversationStore` and bind it in a service provider. Stores may also implement `StoresConversationTurns` to save a complete turn atomically. Its `storeTurn` method receives the conversation, participant, prompt, response, and optional failure, and returns a `StoredTurn` containing the conversation ID and nullable user and assistant message IDs. A non-null title requests a new conversation; approval resumption adds no new user message. The SDK applies the returned IDs only after storage succeeds.

Without this capability, the SDK calls the store's conversation and message methods sequentially. The store owns any transaction guarantees; the SDK does not wrap custom backends in SQL transactions.

Resuming stored approvals additionally requires `ClaimsPendingApprovals`. Its `claimPendingApprovals` method must atomically claim the paused turn matching the exact pending tool-call IDs, returning an `ApprovalClaim` or null if unavailable. Its `recordApprovalResult` method must verify that claim and durably merge each outcome before the next approved tool runs. During completion or failure, use `AgentPrompt::approvalClaim()` to settle the same owned turn without losing recorded results, then clear the claim. Never automatically reclaim work just because it is old. See [human tool approval](#human-tool-approval) for interruption and retry behavior.

Stores without `ClaimsPendingApprovals` still support ordinary conversations, but stored approval resumption throws before executing tools. When resuming from caller-supplied history without a store, your application must prevent duplicate submissions itself.

<a name="migrating-conversation-data"></a>
#### Migrating Conversation Data

Existing Laravel conversation tables need a schema migration before they can be used with Hypervel. Compare your schema with the published migration: conversation and message IDs use UUID column types, participant IDs follow the application's configured morph key type, and content and opaque JSON payloads use `longText`. Add the nullable UUID `approval_claim` and boolean `has_replay_blocks` columns, and update the lookup indexes. Backfill `has_replay_blocks` for messages whose stored steps contain replay blocks so cleanup can find existing data.

Messages have a cascading foreign key to their conversation. Resolve orphaned messages before adding this constraint; deleting a conversation then deletes its messages as well. If you enable partitioning, migrate ownership and use the composite constraint described above. Back up existing data and validate the migration against your database before deploying it.

<a name="structured-output"></a>
### Structured Output

If you would like your agent to return structured output, implement the `HasStructuredOutput` interface, which requires that your agent define a `schema` method using Hypervel's [JSON schema builder](/docs/{{version}}/json-schema):

```php
<?php

namespace App\Ai\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasStructuredOutput;
use Hypervel\Ai\Promptable;
use Hypervel\Contracts\JsonSchema\JsonSchema;

class SalesCoach implements Agent, HasStructuredOutput
{
    use Promptable;

    // ...

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'score' => $schema->integer()->required(),
        ];
    }
}
```

When prompting an agent that returns structured output, you can access the returned `StructuredAgentResponse` like an array:

```php
$response = (new SalesCoach)->prompt('Analyze this sales transcript...');

return $response['score'];
```

<a name="structured-output-nested-objects"></a>
#### Nested Objects

To define nested structured output, use the `object` method with a closure:

```php
<?php

namespace App\Ai\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasStructuredOutput;
use Hypervel\Ai\Promptable;
use Hypervel\Contracts\JsonSchema\JsonSchema;

class SalesCoach implements Agent, HasStructuredOutput
{
    use Promptable;

    // ...

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'score' => $schema->integer()->required(),
            'metadata' => $schema->object(fn ($schema) => [
                'confidence' => $schema->string()->enum(['low', 'medium', 'high'])->required(),
                'language' => $schema->string()->required(),
            ])->required(),
        ];
    }
}
```

<a name="structured-output-arrays-of-objects"></a>
#### Arrays of Objects

If your agent should return a list of structured items, combine the `array` and `object` methods:

```php
public function schema(JsonSchema $schema): array
{
    return [
        'feedback' => $schema->array()
            ->items(
                $schema->object(fn ($schema) => [
                    'comment' => $schema->string()->required(),
                    'score' => $schema->integer()->required(),
                ])
            )
            ->required(),
    ];
}
```

If a value may match one of several schemas, use the `anyOf` method:

```php
public function schema(JsonSchema $schema): array
{
    return [
        'content' => $schema->anyOf([
            $schema->object(fn ($schema) => [
                'type' => $schema->string()->enum(['article'])->required(),
                'title' => $schema->string()->required(),
            ]),
            $schema->object(fn ($schema) => [
                'type' => $schema->string()->enum(['image'])->required(),
                'url' => $schema->string()->required(),
            ]),
        ])->required(),
    ];
}
```

<a name="attachments"></a>
### Attachments

When prompting, you may also pass attachments with the prompt to allow the model to inspect images and documents:

```php
use App\Ai\Agents\SalesCoach;
use Hypervel\Ai\Files;

$response = (new SalesCoach)->prompt(
    'Analyze the attached sales transcript...',
    attachments: [
        Files\Document::fromStorage('transcript.pdf'), // Attach a document from a filesystem disk...
        Files\Document::fromPath('/home/hypervel/transcript.md'), // Attach a document from a local path...
        $request->file('transcript'), // Attach an uploaded file...
    ]
);
```

Likewise, the `Hypervel\Ai\Files\Image` class may be used to attach images to a prompt:

```php
use App\Ai\Agents\ImageAnalyzer;
use Hypervel\Ai\Files;

$response = (new ImageAnalyzer)->prompt(
    'What is in this image?',
    attachments: [
        Files\Image::fromStorage('photo.jpg'), // Attach an image from a filesystem disk...
        Files\Image::fromPath('/home/hypervel/photo.jpg'), // Attach an image from a local path...
        $request->file('photo'), // Attach an uploaded file...
    ]
);
```

<a name="remote-attachments"></a>
#### Remote Attachments

You may create an attachment from a remote URL using the file's `fromUrl` method:

```php
use Hypervel\Ai\Files\Document;

$document = Document::fromUrl('https://example.com/report.pdf');
```

When the SDK downloads a remote file, it applies the HTTP client's [destination checks](/docs/{{version}}/http-client#restricting-destinations) to the URL and each redirect. Direct connections are pinned to the checked addresses. Private and reserved addresses are rejected unless the hostname or IP address is listed in `ai.remote_files.allowed_hosts`. These explicitly trusted hosts skip DNS and address checks, so they may also be used when only your proxy can resolve them. URLs containing embedded credentials, such as `https://user:password@example.com/file`, are rejected. Signed URLs with credentials in their query string remain supported.

Downloads inherit your application's outgoing proxy configuration; no separate AI proxy setting is needed. When a proxy resolves the destination hostname, it must enforce the [proxy destination filtering requirements](/docs/{{version}}/http-client#proxies). The default DNS resolver has a 10-second limit per untrusted hostname lookup, separate from the HTTP client's connection and request timeouts.

Downloads are limited to 32 MiB of decoded content by default. You may set a different positive byte limit, or `null` to disable the limit, in your `config/ai.php` file:

```php
'remote_files' => [
    'allowed_hosts' => ['files.internal.example'],
    'max_size' => 64 * 1024 * 1024,
],
```

The limit also applies to allowed hosts and compressed responses. A blocked destination or oversized download throws an `InvalidArgumentException`. The limit applies to each file; concurrent downloads and preparing files for a provider can use additional memory.

If a file requires HTTP authentication, download it using the HTTP client's authentication methods and pass the downloaded content or local path to the SDK. The attachment's `withHeaders` method configures the upload to the AI provider, not the remote download.

For applications that need a custom DNS resolver, register `Hypervel\Ai\Files\UntrustedUrl::resolveUsing()` during application boot. The callback receives a hostname and returns its IP addresses; those addresses still undergo destination validation.

Faked downloads still run destination checks and enforce the size limit. To keep tests independent of external DNS, register a resolver that returns a public address:

```php
use Hypervel\Ai\Files\UntrustedUrl;

UntrustedUrl::resolveUsing(fn (string $host): array => ['93.184.216.34']);
```

<a name="streaming"></a>
### Streaming

You may stream an agent's response by invoking the `stream` method. The returned `StreamableAgentResponse` may be returned from a route to automatically send a streaming response (SSE) to the client:

```php
use App\Ai\Agents\SalesCoach;

Route::get('/coach', function () {
    return (new SalesCoach)->stream('Analyze this sales transcript...');
});
```

The `then` method may be used to provide a closure that will be invoked when the entire response has been streamed to the client:

```php
use App\Ai\Agents\SalesCoach;
use Hypervel\Ai\Responses\StreamedAgentResponse;

Route::get('/coach', function () {
    return (new SalesCoach)
        ->stream('Analyze this sales transcript...')
        ->then(function (StreamedAgentResponse $response) {
            // $response->text, $response->events, $response->usage...
        });
});
```

Alternatively, you may iterate through the streamed events manually:

```php
$stream = (new SalesCoach)->stream('Analyze this sales transcript...');

foreach ($stream as $event) {
    // ...
}
```

The response also contains the model's reasoning and any sources it cited. Both are stored with the assistant message when using the `RemembersConversations` trait:

```php
use Hypervel\Ai\Responses\StreamedAgentResponse;

(new SalesCoach)
    ->stream('Analyze this sales transcript...')
    ->then(function (StreamedAgentResponse $response) {
        $response->reasoning; // '' unless the model returned reasoning text...
        $response->meta->citations;
    });
```

Reasoning is also available on responses returned by the `prompt` method.

Streams start their provider work when consumed. A completed stream retains its events for replay and aggregation, so memory use grows with the response. The native HTTP transport receives incremental data and bounds its own unread buffer; see [HTTP streaming](/docs/{{version}}/http-client#streaming-responses) for timeout and fallback behavior.

When returned as an HTTP response, the SDK enables [disconnect cancellation](/docs/{{version}}/responses#streamed-responses) for its SSE and protocol responses. A supported whole-connection close cancels owned provider work. Individual HTTP/2 stream resets are not reported immediately by the current runtime; detection waits for a failed write, completion, or timeout.

<a name="capturing-application-context"></a>
#### Capturing Application Context

A stream may be created under one account and consumed later in a different coroutine. If your application supplies account or other operation-specific context, register `Ai::captureContextUsing` during boot. The callback captures the current values and returns a closure that temporarily restores them around SDK work:

```php
use Closure;
use Hypervel\Ai\Ai;
use Hypervel\Support\Facades\Context;

Ai::captureContextUsing(function (): Closure {
    $accountId = Context::get('account_id');

    return fn (Closure $callback): mixed => Context::scope($callback, ['account_id' => $accountId]);
});
```

This example captures only an account ID. An application using other account-specific services must restore their state through its own context API too. The returned closure must support nested calls and inexpensive re-entry. Return a closure even when no account is active, so later consumption does not inherit another account.

The SDK uses this closure while advancing and closing the stream and running its `each`, `then`, and `catch` callbacks, including callbacks added after completion. It restores the consumer's context before yielding each event, so your `foreach` body retains its own context. Opt-in concurrent tools and titles also use the captured context. Queued jobs use their normal queue context lifecycle; these live closures are not serialized into jobs or queued events.

<a name="streaming-using-the-vercel-ai-sdk-protocol"></a>
<a name="stream-protocols"></a>
#### Stream Protocols

By default, streamed responses use the AI SDK's own event format. However, you may use a frontend streaming protocol instead, which allows you to pair your agent with an existing chat interface rather than building your own.

You may stream the events using the [Vercel AI SDK stream protocol](https://ai-sdk.dev/docs/ai-sdk-ui/stream-protocol) by invoking the `usingVercelDataProtocol` method on the streamable response:

```php
use App\Ai\Agents\SalesCoach;

Route::get('/coach', function () {
    return (new SalesCoach)
        ->stream('Analyze this sales transcript...')
        ->usingVercelDataProtocol();
});
```

You may pass a message ID if your application's frontend assigns its own:

```php
->usingVercelDataProtocol((string) $request->string('messageId'));
```

Alternatively, the `usingAgentUserInteractionProtocol` method may be used to stream using the [Agent User Interaction (AG-UI) protocol](https://docs.ag-ui.com):

```php
Route::post('/coach', function (Request $request) {
    return (new SalesCoach)
        ->forUser($request->user())
        ->stream((string) $request->string('prompt'))
        ->usingAgentUserInteractionProtocol();
});
```

The `threadId` and `runId` arguments are optional and default to the conversation ID and the invocation ID:

```php
->usingAgentUserInteractionProtocol(
    threadId: $request->input('threadId'),
    runId: $request->input('runId'),
);
```

To use a protocol that the AI SDK does not implement, you may pass your own `Hypervel\Ai\Streaming\Protocols\StreamProtocol` implementation to the `usingProtocol` method:

```php
use App\Ai\Protocols\CustomProtocol;

return (new SalesCoach)
    ->stream('Analyze this sales transcript...')
    ->usingProtocol(new CustomProtocol);
```

<a name="chat-requests"></a>
<a name="frontend-integration"></a>
#### Frontend Integration

Chat interfaces built with libraries such as Vercel's `useChat` or CopilotKit already render messages, tool calls, and approval prompts, so your application only needs to handle the requests they send. Each request contains the conversation history, the newest user message, and any tool approval responses.

The `Vercel::chat` and `AgentUserInteraction::chat` methods convert such a request into an object that may be passed directly to an agent's `stream` method:

```php
use Hypervel\Ai\Vercel\Vercel;

Route::post('/chat', function (Request $request) {
    $chat = Vercel::chat($request);

    return (new SupportAgent)
        ->withMessages($chat->history())
        ->stream($chat)
        ->usingProtocol($chat->protocol());
});
```

If the request contains [approval decisions](#human-tool-approval), the agent will resume using those decisions. Otherwise, the agent is prompted with the request's newest user message and attachments. The `protocol` method returns the protocol used by the client.

> [!NOTE]
> Agents that implement the `Conversational` interface load their own history, so the `withMessages` method may be omitted.

> [!WARNING]
> History supplied through `withMessages($chat->history())` comes from the client, including tool names and arguments. Approval is not authorization: each tool must authorize its action and arguments. If pending calls must come from trusted stored history, use a remembering agent and [resume its stored conversation](#human-tool-approval) instead of supplying client history.

The `AgentUserInteraction::chat` method provides the same API for AG-UI clients, in addition to the request's thread and run IDs:

```php
use Hypervel\Ai\AgentUserInteraction\AgentUserInteraction;

$chat = AgentUserInteraction::chat($request);

$chat->threadId();
$chat->runId();
```

You may also convert stored messages back into the format a client expects, allowing the client to display a previous conversation, such as after a page reload:

```php
$messages = $conversation->messages()->oldest()->get();

return ['messages' => Vercel::toUiMessages($messages)];
```

The `AgentUserInteraction::toClientState` method performs the same conversion for AG-UI clients, in addition to returning any pending approval interrupts:

```php
return AgentUserInteraction::toClientState($messages);
```

<a name="broadcasting"></a>
### Broadcasting

You may broadcast streamed events in a few different ways. First, you can simply invoke the `broadcast` or `broadcastNow` method on a streamed event:

```php
use App\Ai\Agents\SalesCoach;
use Hypervel\Broadcasting\Channel;

$stream = (new SalesCoach)->stream('Analyze this sales transcript...');

foreach ($stream as $event) {
    $event->broadcast(new Channel('channel-name'));
}
```

Or, you can invoke an agent's `broadcastOnQueue` method to queue the agent operation and broadcast the streamed events as they are available:

```php
(new SalesCoach)->broadcastOnQueue(
    'Analyze this sales transcript...',
    new Channel('channel-name'),
);
```

To generate and broadcast the response in the current request, call the agent's `broadcast` method:

```php
(new SalesCoach)->broadcast('Analyze this sales transcript...', new Channel('channel-name'));
```

Agent and stream-event `broadcast` methods send events immediately by default. Pass `now: false` to queue delivery instead. `broadcastOnQueue` queues the entire agent operation.

<a name="broadcast-batching"></a>
#### Broadcast Batching

Agent broadcasts combine adjacent text and reasoning deltas by default, reducing broadcast requests and queued jobs. Generation continues while one sender delivers the batches, and the agent's `broadcast` call waits for that sender before returning. Direct HTTP/SSE streams and calls to an individual stream event's `broadcast` method are unchanged.

The `broadcasting` section of `config/ai.php` controls batching:

```php
'broadcasting' => [
    'batching' => [
        'enabled' => true,
        'milliseconds' => 50,
        'max_bytes' => 4096,
    ],
],
```

The window starts when the first delta arrives and flushes even while the provider is quiet. A delta may wait up to the window before it is sent; lower values reduce latency, while higher values can send fewer events. A zero-millisecond window combines only deltas already waiting; setting `enabled` to `false` restores individual-event delivery. Transport and queue backlogs can add delivery time beyond the configured window. The byte limit counts JSON-encoded delta content, leaving room for the event envelope; a single oversized event still passes through unchanged.

Batches keep the first delta's ID and timestamp. Structural events, such as tool calls and completion, remain ordered with the preceding batches. The original events remain available on the response for replay. Events you broadcast yourself during generation, including from completion callbacks, may arrive before pending deltas; disable batching if your client depends on that order.

Batching is skipped outside coroutines, inside database transactions and inside `Event::defer()` so delivery retains the caller's transaction and event-deferral behavior.

<a name="skipping-oversized-events"></a>
#### Skipping Oversized Events

Some broadcasting platforms limit WebSocket messages to around 10KB. Data-heavy stream events, like large tool results, can exceed this limit and cause broadcasting to fail. You may exclude specific event types from broadcasting using the `WithoutBroadcasting` attribute:

```php
<?php

namespace App\Ai\Agents;

use Hypervel\Ai\Attributes\WithoutBroadcasting;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Streaming\Events\ToolCall;
use Hypervel\Ai\Streaming\Events\ToolResult;

#[WithoutBroadcasting(ToolCall::class, ToolResult::class)]
class SearchAgent implements Agent, HasTools
{
    use Promptable;

    // ...
}
```

The excluded events are never broadcast, but they are still persisted to the `agent_conversation_messages` table, so your frontend can load the full tool data after the stream completes. This works for both queued (`broadcastOnQueue`) and synchronous (`broadcast` / `broadcastNow`) broadcasting.

<a name="queueing"></a>
### Queueing

Using an agent's `queue` method, you may prompt the agent, but allow it to process the response in the background, keeping your application feeling fast and responsive. The `then` and `catch` methods may be used to register closures that will be invoked when a response is available or if an exception occurs:

```php
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Http\Request;
use Throwable;

Route::post('/coach', function (Request $request) {
    (new SalesCoach)
        ->queue($request->input('transcript'))
        ->then(function (AgentResponse $response) {
            // ...
        })
        ->catch(function (?Throwable $exception) {
            // ...
        });

    return back();
});
```

Choose and run a [queue connection](/docs/{{version}}/queues) appropriate for the work. A durable queue keeps generation separate from the HTTP request and provides the queue's retry behavior; it does not make external tool side effects safe to repeat. Failure callbacks accept a nullable exception because a job may be failed manually without one.

<a name="tools"></a>
### Tools

Tools may be used to give agents additional functionality that they can utilize while responding to prompts. Tools can be created using the `make:tool` Artisan command:

```shell
php artisan make:tool RandomNumberGenerator
```

The generated tool will be placed in your application's `app/Ai/Tools` directory. Each tool contains a `handle` method that will be invoked by the agent when it needs to utilize the tool:

```php
<?php

namespace App\Ai\Tools;

use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Stringable;

class RandomNumberGenerator implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'This tool may be used to generate cryptographically secure random numbers.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        return (string) random_int($request['min'], $request['max']);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'min' => $schema->integer()->min(0)->required(),
            'max' => $schema->integer()->required(),
        ];
    }
}
```

Once you have defined your tool, you may return it from the `tools` method of any of your agents:

```php
use App\Ai\Tools\RandomNumberGenerator;

/**
 * Get the tools available to the agent.
 *
 * @return Tool[]
 */
public function tools(): iterable
{
    return [
        new RandomNumberGenerator,
    ];
}
```

<a name="runtime-tool-overrides"></a>
#### Runtime Tool Overrides

The `withTools` method may be used to replace the tools declared by an agent instance. This is useful for per-tenant or feature-flagged tool sets:

```php
$response = (new SupportAgent)
    ->withTools([new LookupOrder])
    ->prompt('Where is order 12345?');
```

You may also pass a closure, which receives the agent's declared tools, allowing you to append to or filter them:

```php
$response = (new SupportAgent)
    ->withTools(fn (array $tools) => [...$tools, new LookupOrder])
    ->prompt('Where is order 12345?');
```

<a name="concurrent-tools"></a>
#### Concurrent Tools

Tools run one at a time by default. If your tools can run independently, you may use the `ConcurrentTools` attribute to execute several tool calls at once:

```php
use Hypervel\Ai\Attributes\ConcurrentTools;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Promptable;

#[ConcurrentTools(max: 3)]
class ResearchAgent implements Agent, HasTools
{
    use Promptable;

    // ...
}
```

The limit applies to each group of tool calls within a generation step. Results retain the model's original call order, while sub-agent output can arrive as each sub-agent produces it. If a tool fails or the stream is abandoned, the remaining tool executions are canceled and awaited before the run ends.

Concurrent tools use separate coroutines and database connections; they do not share the caller's database transaction. Each tool must be safe to run alongside the others. Resuming approved tool calls remains sequential so each outcome is stored before the next approved tool executes.

Tool coroutines receive a copy of the caller's `Context` values and use the caller's current default database connection, such as one selected with `DB::usingConnection()`. They do not inherit the request or authenticated user. Pass needed values, such as the user, to your tools' constructors, and register [context capture](#capturing-application-context) when provider, partition, or cache-scope resolvers depend on additional application context.

<a name="validating-tool-arguments"></a>
#### Validating Tool Arguments

Although your tool's schema constrains the arguments a model may provide, you may validate the incoming arguments using the request's `validate` method:

```php
public function handle(Request $request): Stringable|string
{
    $validated = $request->validate([
        'city' => 'required|string',
        'days' => 'required|integer|max:7',
    ]);

    return $this->forecast($validated['city'], $validated['days']);
}
```

When validation fails, the validation messages are returned to the model as the tool's result, allowing it to correct the arguments and call the tool again.

<a name="repairing-tool-calls"></a>
#### Repairing Tool Calls

Use the `RepairToolCalls` attribute to let an agent recover when a model calls an unknown local tool. Hypervel returns the failed call to the model with the names of the available local tools, allowing it to correct the call:

```php
use Hypervel\Ai\Attributes\RepairToolCalls;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Promptable;

#[RepairToolCalls]
class SupportAgent implements Agent, HasTools
{
    use Promptable;

    // ...
}
```

When Hypervel derives the maximum number of steps automatically, this attribute adds one step for the repaired call. Explicit `MaxSteps` limits are unchanged.

<a name="similarity-search"></a>
#### Similarity Search

The `SimilaritySearch` tool allows agents to search for documents similar to a given query using vector embeddings stored in your database. This is useful for retrieval-augmented generation (RAG) when you want to give agents access to search your application's data.

The simplest way to create a similarity search tool is using the `usingModel` method with an Eloquent model that has vector embeddings:

```php
use App\Models\Document;
use Hypervel\Ai\Tools\SimilaritySearch;

public function tools(): iterable
{
    return [
        SimilaritySearch::usingModel(Document::class, 'embedding'),
    ];
}
```

The first argument is the Eloquent model class, and the second argument is the column containing the vector embeddings.

You may also provide a minimum similarity threshold between `0.0` and `1.0` and a closure to customize the query:

```php
SimilaritySearch::usingModel(
    model: Document::class,
    column: 'embedding',
    minSimilarity: 0.7,
    limit: 10,
    query: fn ($query) => $query->where('published', true),
),
```

For more control, you may create a similarity search tool with a custom closure that returns the search results:

```php
use App\Models\Document;
use Hypervel\Ai\Tools\SimilaritySearch;

public function tools(): iterable
{
    return [
        new SimilaritySearch(using: function (string $query) {
            return Document::query()
                ->where('user_id', $this->user->id)
                ->whereVectorSimilarTo('embedding', $query)
                ->limit(10)
                ->get();
        }),
    ];
}
```

You may customize the tool's description using the `withDescription` method:

```php
SimilaritySearch::usingModel(Document::class, 'embedding')
    ->withDescription('Search the knowledge base for relevant articles.'),
```

<a name="deferred-tool-loading"></a>
### Deferred Tool Loading

By default, every tool an agent exposes is sent to the provider with each request. When an agent provides a large number of tools, this consumes tokens and may reduce the accuracy of the model's tool selection. Using the `ToolSearch` provider tool with OpenAI, Azure, or Anthropic, you may defer tool definitions so that the provider only loads them when they are needed:

```php
use App\Ai\Tools\RefundOrder;
use App\Ai\Tools\SearchInvoices;
use App\Ai\Tools\Weather;
use Hypervel\Ai\Providers\Tools\ToolSearch;

public function tools(): iterable
{
    return [
        new Weather,
        new ToolSearch(tools: [
            new SearchInvoices,
            new RefundOrder,
        ]),
    ];
}
```

The wrapped tools do not require any modification. The provider will search for and load them when they are relevant to the prompt, after which the agent may call them like any other tool.

When using Anthropic, the `strategy` argument may be used to determine how the provider should search for deferred tools. The supported strategies are `regex` (default) and `bm25`:

```php
new ToolSearch(tools: [new SearchInvoices], strategy: 'bm25'),
```

When using Anthropic, additional provider-specific options may be passed to the search tool using the `withProviderOptions` method:

```php
(new ToolSearch(tools: [new SearchInvoices]))
    ->withProviderOptions(['cache_control' => ['type' => 'ephemeral']]),
```

> [!WARNING]
> Providers that do not support tool search will throw an exception rather than silently discarding the deferred tools. In addition, Anthropic requires that at least one tool is provided outside of the `ToolSearch` wrapper.

<a name="file-storage-tools"></a>
### File Storage Tools

The `FileStorage` tool factory allows you to give agents access to a Hypervel [filesystem disk](/docs/{{version}}/filesystem). The `all` method returns tools that allow the agent to list, read, inspect, generate URLs for, write, delete, and copy files on the given disk:

```php
use Hypervel\Ai\Tools\FileStorage;

public function tools(): iterable
{
    return FileStorage::all('local');
}
```

If your agent should only be able to inspect files, use the `readOnly` method:

```php
return FileStorage::readOnly('local');
```

These methods return a `Hypervel\Support\Collection`, allowing you to further filter the tools that are provided to the agent:

```php
use Hypervel\Ai\Tools\Filesystem\DeleteFile;

return FileStorage::all('s3')
    ->reject(fn ($tool) => $tool instanceof DeleteFile);
```

<a name="mcp-tools"></a>
### MCP Tools

The SDK includes adapters for tools exposed by [Model Context Protocol](https://modelcontextprotocol.io) servers. These adapters require the separate Hypervel MCP package; ordinary agents and local tools do not depend on it.

> [!NOTE]
> The Hypervel MCP package is not yet available. These examples show the integration once it is installed.

Because an MCP client's `tools` method returns a collection, spread it into your agent's `tools` array using the `...` operator:

```php
use App\Ai\Tools\RandomNumberGenerator;
use Hypervel\Mcp\Client;

/**
 * Get the tools available to the agent.
 *
 * @return Tool[]
 */
public function tools(): iterable
{
    return [
        ...Client::web('https://mcp.example.com')
            ->withToken($token)
            ->tools(),

        new RandomNumberGenerator,
    ];
}
```

The AI SDK automatically wraps each MCP tool so the agent can call it like any other tool. You may also use a named MCP client:

```php
use Hypervel\Mcp\Facades\Mcp;

public function tools(): iterable
{
    return [
        ...Mcp::client('github')->tools(),
    ];
}
```

Or connect to a local MCP server:

```php
use Hypervel\Mcp\Client;

public function tools(): iterable
{
    return [
        ...Client::local('php', ['artisan', 'mcp:start'])->tools(),
    ];
}
```

<a name="skills"></a>
### Skills

[Agent Skills](https://agentskills.io) are folders of instructions and supporting files that teach an agent how to perform a specific task. Because skills follow an open standard, the same skill may be shared between your application's agents and the coding agents you use to build your application.

Each skill is a directory within your application's `resources/skills` directory that contains a `SKILL.md` file. Any other files in the directory, such as reference documents, are bundled with the skill:

```text
resources/skills/
└── refund-policy/
    ├── SKILL.md
    └── references/EDGE-CASES.md
```

The `SKILL.md` file begins with YAML frontmatter containing the skill's `name` and a `description` of when the skill should be used, followed by the skill's instructions:

```markdown
---
name: refund-policy
description: Use when a customer asks for a refund or disputes a charge.
---

# Refund Policy

Customers may request a full refund within 30 days of purchase...
```

If the `name` is omitted, the name of the skill's directory will be used. Skills without a `description` are ignored.

To give an agent access to your skills, implement the `HasSkills` interface and return your skill sources from the `skills` method:

```php
use Hypervel\Ai\Contracts\HasSkills;
use Hypervel\Ai\Skills\Skill;

class SupportAgent implements Agent, HasSkills
{
    use Promptable;

    public function skills(): iterable
    {
        return [
            resource_path('skills'),
            base_path('.agents/skills'),
            new Skill('house-style', 'Use when you write copy for a customer.', view('skills.house-style')),
            fn () => $this->user->team->skills->map(
                fn ($skill) => new Skill($skill->name, $skill->description, $skill->instructions)
            ),
        ];
    }
}
```

Sources may be directories, `Skill` instances, or closures returning skills, which are only invoked once the agent needs them. When two skills share a name, the source listed first takes precedence.

Hypervel caches skill definitions from the directories listed in `ai.skills.cached_paths`, which defaults to `[resource_path('skills')]`. To cache other fixed directories, such as `base_path('.agents/skills')`, add them to this list. [Reload your services](/docs/{{version}}/deployment#reloading-services) after changing these definitions. If your application loads skills during boot, restart the full service instead of only reloading its workers. Other directories, closures, and directly supplied skills are not cached across tool instances, and bundled files are read when requested.

For skills that change while your application is running, use an uncached directory or a closure. You may also disable discovery caching by setting `ai.skills.cached_paths` to an empty array in your configuration.

During development, the [file watcher](/docs/{{version}}/watcher) already watches `resources/skills/**` and restarts the server when those files change. If you add other directories to `cached_paths`, add them to its watched paths too; consult the watcher's [driver guidance](/docs/{{version}}/watcher#choosing-a-driver) when choosing a driver.

The agent receives a `LoadSkill` tool that lists each skill's name and description, loading a skill's full instructions and bundled text files only when a prompt calls for it. Binary files and files larger than 256 KB are not returned.

<a name="provider-tools"></a>
### Provider Tools

Provider tools are special tools implemented natively by AI providers, offering capabilities like web searching, URL fetching, and file searching. Unlike regular tools, provider tools are executed by the provider itself rather than your application.

Provider tools can be returned by your agent's `tools` method.

<a name="web-search"></a>
#### Web Search

The `WebSearch` provider tool allows agents to search the web for real-time information. This is useful for answering questions about current events, recent data, or topics that may have changed since the model's training cutoff.

**Supported providers:** Anthropic, OpenAI, Azure, Gemini, Groq, xAI, OpenRouter

```php
use Hypervel\Ai\Providers\Tools\WebSearch;

public function tools(): iterable
{
    return [
        new WebSearch,
    ];
}
```

You may configure the web search tool to limit the number of searches or restrict results to specific domains:

```php
(new WebSearch)->max(5)->allow(['hypervel.org', 'php.net']),
```

To refine search results based on user location, use the `location` method:

```php
(new WebSearch)->location(
    city: 'New York',
    region: 'NY',
    country: 'US'
);
```

<a name="web-fetch"></a>
#### Web Fetch

The `WebFetch` provider tool allows agents to fetch and read the contents of web pages. This is useful when you need the agent to analyze specific URLs or retrieve detailed information from known web pages.

**Supported providers:** Anthropic, Gemini, OpenRouter

```php
use Hypervel\Ai\Providers\Tools\WebFetch;

public function tools(): iterable
{
    return [
        new WebFetch,
    ];
}
```

You may configure the web fetch tool to limit the number of fetches or restrict to specific domains:

```php
(new WebFetch)->max(3)->allow(['hypervel.org']),
```

<a name="file-search"></a>
#### File Search

The `FileSearch` provider tool allows agents to search through [files](#files) stored in [vector stores](#vector-stores). This enables retrieval-augmented generation (RAG) by allowing the agent to search your uploaded documents for relevant information.

**Supported providers:** OpenAI, Azure, Gemini, xAI

```php
use Hypervel\Ai\Providers\Tools\FileSearch;

public function tools(): iterable
{
    return [
        new FileSearch(stores: ['store_id']),
    ];
}
```

You may provide multiple vector store IDs to search across multiple stores:

```php
new FileSearch(stores: ['store_1', 'store_2']);
```

If your files have [metadata](#adding-files-to-stores), you may filter the search results by providing a `where` argument. For simple equality filters, pass an array:

```php
new FileSearch(stores: ['store_id'], where: [
    'author' => 'Taylor Otwell',
    'year' => 2026,
]);
```

Azure OpenAI does not support file search metadata filters.

For more complex filters, you may pass a closure that receives a `FileSearchQuery` instance:

```php
use Hypervel\Ai\Providers\Tools\FileSearchQuery;

new FileSearch(stores: ['store_id'], where: fn (FileSearchQuery $query) =>
    $query->where('author', 'Taylor Otwell')
        ->whereNot('status', 'draft')
        ->whereIn('category', ['news', 'updates'])
);
```

<a name="code-execution"></a>
#### Code Execution

The `CodeExecution` provider tool allows agents to run code in a sandbox hosted by the AI provider. This is useful for performing calculations and analyzing data.

**Supported providers:** Anthropic, OpenAI, Azure, Gemini, Groq, xAI

```php
use Hypervel\Ai\Providers\Tools\CodeExecution;

public function tools(): iterable
{
    return [new CodeExecution];
}
```

When using OpenAI or Azure, you may make [stored files](#files) available to the sandbox via provider options:

```php
(new CodeExecution)->withProviderOptions([
    'container' => ['type' => 'auto', 'file_ids' => ['file_123']],
]);
```

<a name="sub-agents"></a>
### Sub-Agents

Agents may also be returned from another agent's `tools` method. When an agent is returned as a tool, the parent agent may delegate a specific task to the sub-agent and use the sub-agent's response while answering the original prompt. This is useful when a general-purpose agent needs access to specialized agents with their own instructions, tools, model configuration, or provider preferences.

For example, a customer support agent could delegate refund eligibility questions to a dedicated refunds agent:

```php
<?php

namespace App\Ai\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Promptable;

class CustomerSupportAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You help customers with account, order, and billing questions. Delegate refund policy questions to the refunds specialist.';
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            new RefundsAgent,
        ];
    }
}
```

To customize how the sub-agent is exposed to the parent agent, implement the `CanActAsTool` interface on the sub-agent and define a tool-facing name and description:

```php
use Hypervel\Ai\Attributes\Provider;
use Hypervel\Ai\Contracts\CanActAsTool;
use Hypervel\Ai\Enums\Lab;

#[Provider(Lab::Anthropic)]
class RefundsAgent implements Agent, CanActAsTool, HasTools
{
    /**
     * Get the agent's tool name.
     */
    public function name(): string
    {
        return 'refunds_specialist';
    }

    /**
     * Get the agent's tool description.
     */
    public function description(): string
    {
        return 'Determine whether an order is eligible for a refund and explain the next step.';
    }

    // ...
}
```

If a sub-agent does not implement `CanActAsTool`, Hypervel will use the agent's class basename as the tool name and a generic description that asks the parent agent to pass a clear, self-contained task description. Each sub-agent invocation runs in isolation and does not receive the parent agent's conversation history.

When the parent agent is [streaming](#streaming), its sub-agents stream as well. As a sub-agent's text grows, the parent periodically emits `ToolResult` events containing the text produced so far. These events are marked as preliminary and are followed by the tool call's final result. Short responses may only produce the final result. You may skip preliminary events when iterating manually:

```php
use Hypervel\Ai\Streaming\Events\ToolResult;

foreach ($stream as $event) {
    if ($event instanceof ToolResult && $event->preliminary) {
        continue;
    }

    // ...
}
```

Response values such as `text`, `usage`, and `toolResults` ignore preliminary events. The [Vercel protocol](#stream-protocols) renders them as native streaming tool output, so `useChat` displays the progress without any custom code, while the AG-UI protocol reports them as activity snapshots. The completed response's text, reasoning, citations, and usage include those of the sub-agent.

<a name="middleware"></a>
### Middleware

Agents support middleware, allowing you to intercept and modify each generation step before it is sent to the provider. Middleware is invoked once per step, so a run that takes three steps will invoke it three times. Middleware can be created using the `make:agent-middleware` Artisan command:

```shell
php artisan make:agent-middleware LogPrompts
```

The generated middleware will be placed in your application's `app/Ai/Middleware` directory. To add middleware to an agent, implement the `HasMiddleware` interface and define a `middleware` method that returns an array of middleware classes:

```php
<?php

namespace App\Ai\Agents;

use App\Ai\Middleware\LogPrompts;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasMiddleware;
use Hypervel\Ai\Promptable;

class SalesCoach implements Agent, HasMiddleware
{
    use Promptable;

    // ...

    /**
     * Get the agent's middleware.
     */
    public function middleware(): array
    {
        return [
            new LogPrompts,
        ];
    }
}
```

Each middleware class should define a `handle` method that receives a `PendingStep` and a `Closure` that passes the step to the next middleware:

```php
<?php

namespace App\Ai\Middleware;

use Closure;
use Hypervel\Ai\PendingStep;
use Hypervel\Support\Facades\Log;

class LogPrompts
{
    /**
     * Handle the pending generation step.
     */
    public function handle(PendingStep $step, Closure $next)
    {
        Log::info('Prompting agent', ['model' => $step->model]);

        return $next($step);
    }
}
```

Middleware resolved from class names follows the normal container lifetime rules; shared middleware should be stateless. Middleware wraps each model step, not individual tool calls or child-agent runs. It can stop further model work by throwing, but does not interrupt a provider request that is already running.

In addition to the `provider`, `model`, `instructions`, `messages`, and `tools` that are about to be sent, the step exposes the steps that have already completed, their combined usage, and the progress of the run:

```php
$step->steps;
$step->usage;

$step->number;
$step->isFirstStep();
$step->isFinalStep;
```

The `withModel`, `withInstructions`, `withMessages`, `withTools`, `onlyTools`, `withoutTools`, `withToolChoice`, `withMaxTokens`, and `withProviderOptions` methods each return a copy of the step. For example, you may remove an expensive tool once the agent has used it:

```php
public function handle(PendingStep $step, Closure $next)
{
    if (! $step->isFirstStep()) {
        $step = $step->withoutTools('SearchDocumentation');
    }

    return $next($step);
}
```

Or, you may keep a long tool calling loop within the context window by summarizing the middle of the conversation:

```php
use App\Ai\Agents\Summarizer;
use Hypervel\Ai\Messages\UserMessage;

public function handle(PendingStep $step, Closure $next)
{
    if (count($step->messages) > 40) {
        $summary = (new Summarizer)->prompt(
            collect(array_slice($step->messages, 1, -10))->map->content->implode("\n"),
        )->text;

        $step = $step->withMessages([
            $step->messages[0],
            new UserMessage("Summary of the conversation so far: {$summary}"),
            ...array_slice($step->messages, -10),
        ]);
    }

    return $next($step);
}
```

Messages passed to the `withMessages` method only change what is sent for the current step. Later steps and the stored conversation continue to use the full, unsummarized history.

You may use the `then` method to execute code once the model has answered the step, before its tool calls are executed. This works for both synchronous and streaming responses:

```php
use Hypervel\Ai\Gateway\StepResponse;

public function handle(PendingStep $step, Closure $next)
{
    return $next($step)->then(function (StepResponse $response) {
        Log::info('Agent responded', ['text' => $response->text]);
    });
}
```

Middleware must return the result of `$next`, or its own `StepResponse` to answer the step without invoking the model, such as when serving a cached response. Returning any other value will throw a `LogicException`.

You may also attach middleware when invoking an agent, without changing its class. The `withMiddleware` method appends middleware that runs before the agent's declared middleware, in the order supplied:

```php
use App\Ai\Agents\SalesCoach;
use App\Ai\Middleware\EnforceGenerationBudget;

$response = SalesCoach::make()
    ->withMiddleware([EnforceGenerationBudget::class])
    ->prompt('Review this sales call.');
```

You may pass middleware class names, instances, or closures. Middleware added this way remains on the agent instance for subsequent calls. A stream captures the added middleware when it is created, so adding more middleware afterwards does not change that stream.

Queued agents and queued AI event listeners require serializable middleware, captured values, and tool instances stored on the agent. Queued prompt listeners also serialize all tools resolved through `withTools`, including declared tools retained by its closure form. For tools that contain closures, such as `SimilaritySearch`, declare them in `tools` and omit `withTools` when using queued prompt listeners.

<a name="anonymous-agents"></a>
### Anonymous Agents

Sometimes you may want to quickly interact with a model without creating a dedicated agent class. You can create an ad-hoc, anonymous agent using the `agent` function:

```php
use function Hypervel\Ai\agent;

$response = agent(
    instructions: 'You are an expert at software development.',
    messages: [],
    tools: [],
)->prompt('Tell me about Hypervel');
```

Anonymous agents may also produce structured output:

```php
use Hypervel\Contracts\JsonSchema\JsonSchema;

use function Hypervel\Ai\agent;

$response = agent(
    schema: fn (JsonSchema $schema) => [
        'number' => $schema->integer()->required(),
    ],
)->prompt('Generate a random number less than 100');
```

<a name="agent-configuration"></a>
### Agent Configuration

You may configure text generation options for an agent using PHP attributes. The following attributes are available:

- `MaxSteps`: The maximum number of steps the agent may take when using tools.
- `MaxTokens`: The maximum number of tokens the model may generate.
- `Model`: The model the agent should use.
- `Provider`: The AI provider (or providers for failover) to use for the agent.
- `Temperature`: The sampling temperature to use for generation (0.0 to 1.0).
- `Timeout`: The HTTP timeout in seconds for agent requests (default: 60).
- `TopP`: The nucleus sampling probability to use for generation (0.0 to 1.0).
- `UseCheapestModel`: Use the provider's cheapest text model for cost optimization.
- `UseSmartestModel`: Use the provider's most capable text model for complex tasks.

```php
<?php

namespace App\Ai\Agents;

use Hypervel\Ai\Attributes\MaxSteps;
use Hypervel\Ai\Attributes\MaxTokens;
use Hypervel\Ai\Attributes\Model;
use Hypervel\Ai\Attributes\Provider;
use Hypervel\Ai\Attributes\Temperature;
use Hypervel\Ai\Attributes\Timeout;
use Hypervel\Ai\Attributes\TopP;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Promptable;

#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-5')]
#[MaxSteps(10)]
#[MaxTokens(4096)]
#[Temperature(0.7)]
#[Timeout(120)]
#[TopP(0.9)]
class SalesCoach implements Agent
{
    use Promptable;

    // ...
}
```

The `UseCheapestModel` and `UseSmartestModel` attributes allow you to automatically select the most cost-effective or most capable model for a given provider without specifying a model name. This is useful when you want to optimize for cost or capability across different providers:

```php
use Hypervel\Ai\Attributes\UseCheapestModel;
use Hypervel\Ai\Attributes\UseSmartestModel;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Promptable;

#[UseCheapestModel]
class SimpleSummarizer implements Agent
{
    use Promptable;

    // Will use the cheapest model (e.g., Haiku)...
}

#[UseSmartestModel]
class ComplexReasoner implements Agent
{
    use Promptable;

    // Will use the most capable model (e.g., Opus)...
}
```

> [!NOTE]
> The underlying model selected by `UseCheapestModel` and `UseSmartestModel` may change between releases of the Hypervel AI SDK as providers release new models. Switching models can introduce behavioral changes, deprecated parameters, and significant cost differences. If you need a stable, predictable model and pricing, specify the model explicitly using the `Model` attribute.

<a name="provider-options"></a>
### Provider Options

If your agent needs to pass provider-specific options (such as OpenAI reasoning effort or penalty settings), implement the `HasProviderOptions` contract and define a `providerOptions` method:

```php
<?php

namespace App\Ai\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Promptable;

class SalesCoach implements Agent, HasProviderOptions
{
    use Promptable;

    // ...

    /**
     * Get provider-specific generation options.
     */
    public function providerOptions(Lab|string $provider): array
    {
        return match ($provider) {
            Lab::OpenAI => [
                'reasoning' => ['effort' => 'low'],
                'frequency_penalty' => 0.5,
                'presence_penalty' => 0.3,
            ],
            Lab::Anthropic => [
                'thinking' => ['budget_tokens' => 1024],
                'cache_control' => ['type' => 'ephemeral'],
            ],
            default => [],
        };
    }
}
```

The `providerOptions` method receives the provider currently being used (`Lab` enum or string), allowing you to return different options per provider. This is especially useful when using [failover](#failover), since each fallback provider can receive its own configuration.

The Anthropic example above also enables [prompt caching](#prompt-caching) via `cache_control`.

The [image](#images), [audio](#audio), [transcription](#transcription), [embedding](#embeddings), and [reranking](#reranking) builders accept provider options as well:

```php
use Hypervel\Ai\Audio;

$audio = Audio::of('I love coding with Hypervel.')
    ->withProviderOptions(['speed' => 1.25])
    ->generate();
```

You may also pass a closure instead of an array, which will receive the provider currently being used.

<a name="custom-http-headers"></a>
#### Custom HTTP Headers

Headers configured for a provider within your application's `config/ai.php` configuration file are sent with every request that provider makes. To send headers on a per-request basis, such as metadata used by an AI gateway, you may use the `withHeaders` method, which is available on the image, audio, transcription, embedding, and reranking builders, as well as on [file uploads](#files):

```php
use Hypervel\Ai\Embeddings;

$embeddings = Embeddings::for($chunks)
    ->withHeaders(['cf-aig-metadata' => json_encode(['team' => $team->id])])
    ->withProviderOptions(['dimensions' => 1024])
    ->generate();
```

Headers may also be given as a closure, which receives the provider currently being used. Per-request headers are not included in the request body and do not affect [embedding cache keys](#caching-embeddings).

<a name="prompt-caching"></a>
### Prompt Caching

Most providers cache repeated prompt prefixes automatically and bill the cached portion at a discount. OpenAI, Gemini, Groq, DeepSeek, and xAI require no configuration, and you may inspect the savings via the response's usage:

```php
$response->usage->cacheReadInputTokens;
$response->usage->cacheWriteInputTokens;
```

Both of these counts are subsets of the input token total, which is discussed further in the [usage documentation](#usage).

The `anthropic` and `bedrock` providers only cache when asked. The `CacheInstructions` and `CacheToolDefinitions` attributes place a cache breakpoint at the end of your agent's instructions and tool definitions, so every conversation reads that prefix from the cache instead of writing it again:

```php
use Hypervel\Ai\Attributes\CacheInstructions;
use Hypervel\Ai\Attributes\CacheToolDefinitions;

#[CacheInstructions]
#[CacheToolDefinitions]
class SalesCoach implements Agent
{
    use Promptable;

    // ...
}
```

If your instructions change on every request, such as when they embed the current date, use `CacheToolDefinitions` alone. Caching a prefix that changes on every request creates a new cache entry each time, so you pay to write it to the cache without ever reusing it.

Providers that do not support these attributes ignore them, so an agent may safely declare them while using [failover](#failover).

Cached prefixes are retained for five minutes by default. Anthropic may retain them for an hour if you pass a TTL to the attribute:

```php
#[CacheInstructions('1h')]
#[CacheToolDefinitions('1h')]
```

Alternatively, Anthropic's automatic caching may be enabled via a top-level `cache_control` [provider option](#provider-options). This places a single breakpoint after the last block of the request, so the breakpoint advances as the conversation grows and each turn reads the previous turns from the cache. Both mechanisms may be combined.

> [!WARNING]
> Because providers build prompts in the order tools, instructions, and messages, caching instructions for an hour also requires caching tool definitions for an hour. Mixing the two throws an `InvalidArgumentException`.

<a name="human-tool-approval"></a>
## Human Tool Approval

> [!WARNING]
> Tool approval requires the paused turn's history to be available when the run is resumed. You should either use a `Conversational` agent, such as one using the `RemembersConversations` trait, or provide the history from your application's frontend using the [`withMessages` method](#conversation-context). Agents that do neither will throw an `ApprovalNotResumableException` when a tool pauses.

Tools that perform sensitive or irreversible actions may require human approval before they are executed. To make a tool approvable, implement the `Approvable` contract and use the `InteractsWithApprovals` trait. Approvable tools require approval by default:

```php
<?php

namespace App\Ai\Tools;

use Hypervel\Ai\Concerns\InteractsWithApprovals;
use Hypervel\Ai\Contracts\Approvable;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Support\Facades\Storage;
use Stringable;

class DeleteFile implements Approvable, Tool
{
    use InteractsWithApprovals;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Delete a file from storage.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        Storage::delete($request['path']);

        return "Deleted [{$request['path']}].";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->required(),
        ];
    }
}
```

To determine whether approval is needed based on the tool call's arguments, define a `needsApproval` method on the tool. This method may return a boolean or an `Approval` instance that includes a reason for the approval request:

```php
use Hypervel\Ai\Approvals\Approval;

/**
 * Determine whether the tool needs approval for the given request.
 */
protected function needsApproval(Request $request): Approval|bool
{
    return str_starts_with($request['path'], 'temporary/')
        ? false
        : Approval::required('This will permanently delete a file.');
}
```

You may override a tool's approval requirement when returning it from an agent's `tools` method:

```php
public function tools(): iterable
{
    return [
        (new SendNotification)->withoutApproval(),
        (new DeleteFile)->requireApproval('Deletion review required.'),
    ];
}
```

When an approvable tool is called, the agent pauses before executing it. You may inspect the response's pending approvals, which contain each tool call's ID, tool name, arguments, and approval reason:

```php
$response = (new FileAssistant)
    ->forUser($user)
    ->prompt('Delete the old invoice.');

if ($response->hasPendingApprovals()) {
    foreach ($response->pendingApprovals as $approval) {
        // $approval->id
        // $approval->tool
        // $approval->arguments
        // $approval->reason
    }
}
```

To resume the agent, continue the conversation and provide a `Decisions` instance containing a decision for each pending tool call. Decisions may approve the call, reject it, or edit its arguments before execution:

```php
use Hypervel\Ai\Approvals\Decision;
use Hypervel\Ai\Approvals\Decisions;

$response = (new FileAssistant)
    ->continue($conversationId, as: $user)
    ->prompt(Decisions::from([
        'call_abc' => Decision::approve(),
        'call_ghi' => Decision::reject('The invoice must be retained.'),
    ]));
```

> [!IMPORTANT]
> Paused turns are matched by their conversation and pending tool calls, not by the participant that paused them. Therefore, your application should authorize access to the conversation before resuming it, as demonstrated in the [complete approval flow](#complete-approval-flow), or verify access using the conversation store's `conversationBelongsTo` method.

The boolean values `true` and `false` may be used as shorthand for approval and rejection. Every pending tool call must receive a decision. Unknown, missing, or previously resolved tool call IDs will cause an `ApprovalMismatchException` to be thrown. You may provide a default for calls without an explicit decision using the `approveRemaining` or `rejectRemaining` methods:

```php
$decisions = Decisions::from([
    'call_abc' => true,
])->rejectRemaining('Not approved.');

$response = (new FileAssistant)
    ->continue($conversationId, as: $user)
    ->prompt($decisions);
```

A rejection with a result, such as `Decision::reject('Not approved.')`, is returned to the model so it may continue responding. A rejection without a result stops the generation loop after recording the rejection.

Tool approval is supported by the `prompt`, `stream`, `queue`, `broadcast`, `broadcastNow`, and `broadcastOnQueue` methods.

When resuming a stored conversation, Hypervel claims the paused turn before running approved tools so another request cannot run the same approval concurrently. Each tool outcome is saved in a short database transaction before the next tool runs. This adds a write for each outcome instead of saving all outcomes together, but preserves recorded results if the process stops later. Tool execution takes place outside these transactions.

If the process stops after an external action but before saving its result, that action's outcome remains unknown. Claimed turns are not automatically retried. Use tool-call IDs as idempotency keys when the external service supports them, and review unresolved actions before retrying them manually.

To find claims that may need attention, inspect messages with a non-null `approval_claim`, ordered by `updated_at`. This timestamp records claim acquisition and subsequent result writes; an old timestamp alone does not prove that execution has stopped:

```php
use Hypervel\Ai\Models\ConversationMessage;

$claims = ConversationMessage::whereNotNull('approval_claim')
    ->oldest('updated_at')
    ->limit(100)
    ->get(['id', 'conversation_id', 'updated_at']);
```

During streaming and broadcasting, a pause is represented by a `tool_approval_request` event. When using the [Vercel AI SDK stream protocol](#stream-protocols), approval requests and results are emitted using the protocol's native tool approval parts, and the Agent User Interaction protocol reports them as interrupts.

Clients that use either protocol post their decisions along with the rest of the conversation, so a [chat request](#frontend-integration) may be passed directly to the agent. In this example, `FileAssistant` uses `RemembersConversations` and loads the pending calls from the server. Authorize access to the conversation before continuing it:

```php
$chat = Vercel::chat($request);

Gate::authorize('view', $conversation);

return (new FileAssistant)
    ->continue($conversation->id, as: $request->user())
    ->stream($chat)
    ->usingProtocol($chat->protocol());
```

When a paused turn is resumed, the resumed steps are merged into that turn, so each turn is stored as a single assistant message. The response's `assistantMessageId` contains the ID of the paused message, and that message's usage includes both the pause and the resume.

Stored history prevents the client from replacing pending tool calls, but tools must still authorize their actions and arguments. When using caller-supplied history instead, treat its tool calls and arguments as untrusted input.

For queued agents, the resulting response is passed to the `then` callback, and Hypervel also dispatches a `ToolApprovalRequested` event.

Hypervel stores the result of an approved tool before asking the model to continue. If generation then fails, the approval has already been resolved. Continue the conversation with a normal text prompt instead of submitting the same approval decisions again.

<a name="complete-approval-flow"></a>
### Complete Approval Flow

The following route demonstrates a complete approval flow, accepting either a new text prompt or approval decisions from the chat screen. This example assumes the application's `User` model uses the `HasConversations` trait:

```php
use App\Ai\Agents\FileAssistant;
use Hypervel\Ai\Approvals\Decision;
use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Models\Conversation;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Gate;
use Hypervel\Support\Facades\Route;
use Hypervel\Validation\Rule;

Route::post('/chat/{conversation}', function (Request $request, Conversation $conversation) {
    Gate::authorize('view', $conversation);

    $validated = $request->validate([
        'message' => ['nullable', 'string', 'required_without:decisions', 'prohibits:decisions'],
        'decisions' => ['nullable', 'array', 'required_without:message', 'prohibits:message'],
        'decisions.*.action' => ['required_with:decisions', Rule::in(['approve', 'reject'])],
        'decisions.*.result' => ['nullable', 'string'],
    ]);

    $prompt = isset($validated['decisions'])
        ? Decisions::from(collect($validated['decisions'])->map(
            fn (array $decision) => match ($decision['action']) {
                'approve' => Decision::approve(),
                'reject' => Decision::reject($decision['result'] ?? null),
            }
        )->all())
        : $validated['message'];

    $response = (new FileAssistant)
        ->continue($conversation->id, as: $request->user())
        ->prompt($prompt);

    return [
        'conversation_id' => $response->conversationId,
        'status' => $response->hasPendingApprovals() ? 'awaiting_approval' : 'complete',
        'message' => $response->text,
        'approvals' => $response->pendingApprovals,
    ];
})->middleware('auth');
```

When the response status is `awaiting_approval`, the chat screen should render the pending approvals and submit the user's choices to the same endpoint using the tool call ID as each decision's key. Otherwise, the screen may submit a plain `message` value:

```json
{
    "decisions": {
        "call_abc": {
            "action": "approve"
        },
        "call_def": {
            "action": "reject",
            "result": "The invoice must be retained."
        }
    }
}
```

<a name="images"></a>
## Images

The `Hypervel\Ai\Image` class may be used to generate images using the `openai`, `gemini`, `xai`, `azure`, `bedrock`, or `openrouter` providers:

```php
use Hypervel\Ai\Image;

$image = Image::of('A donut sitting on the kitchen counter')->generate();

$rawContent = (string) $image;
```

The `square`, `portrait`, and `landscape` methods may be used to control the aspect ratio of the image, while the `quality` method may be used to guide the model on final image quality (`high`, `medium`, `low`). The `timeout` method may be used to specify the HTTP timeout in seconds:

```php
use Hypervel\Ai\Image;

$image = Image::of('A donut sitting on the kitchen counter')
    ->quality('high')
    ->landscape()
    ->timeout(120)
    ->generate();
```

You may attach reference images using the `attachments` method:

```php
use Hypervel\Ai\Files;
use Hypervel\Ai\Image;

$image = Image::of('Update this photo of me to be in the style of an impressionist painting.')
    ->attachments([
        Files\Image::fromStorage('photo.jpg'),
        // Files\Image::fromPath('/home/hypervel/photo.jpg'),
        // Files\Image::fromUrl('https://example.com/photo.jpg'),
        // $request->file('photo'),
    ])
    ->landscape()
    ->generate();
```

The Bedrock provider supports text-to-image generation only and does not accept reference images.

Some providers may generate multiple images in a single request. OpenAI, Azure, and xAI accept an `n` [provider option](#provider-options), and the response will contain every image that was returned:

```php
$response = Image::of('A donut sitting on the kitchen counter')
    ->withProviderOptions(['n' => 4])
    ->generate();

count($response);           // 4
$response->images;          // A collection of generated images...
$response->firstImage();    // The first generated image...
```

Generated images may be easily stored on the default disk configured in your application's `config/filesystems.php` configuration file:

```php
$image = Image::of('A donut sitting on the kitchen counter');

$path = $image->store();
$path = $image->storeAs('image.jpg');
$path = $image->storePublicly();
$path = $image->storePubliclyAs('image.jpg');
```

Image generation may also be queued:

```php
use Hypervel\Ai\Image;
use Hypervel\Ai\Responses\ImageResponse;

Image::of('A donut sitting on the kitchen counter')
    ->portrait()
    ->queue()
    ->then(function (ImageResponse $image) {
        $path = $image->store();

        // ...
    });
```

<a name="audio"></a>
## Audio

The `Hypervel\Ai\Audio` class may be used to generate audio from the given text:

```php
use Hypervel\Ai\Audio;

$audio = Audio::of('I love coding with Hypervel.')->generate();

$rawContent = (string) $audio;
```

You may also generate audio from a string using the `toAudio` method available via Hypervel's `Stringable` class:

```php
use Hypervel\Support\Str;

$audio = Str::of('I love coding with Hypervel.')->toAudio();
```

The `male`, `female`, and `voice` methods may be used to determine the voice of the generated audio:

```php
$audio = Audio::of('I love coding with Hypervel.')
    ->female()
    ->generate();

$audio = Audio::of('I love coding with Hypervel.')
    ->voice('voice-id-or-name')
    ->generate();
```

Similarly, the `instructions` method may be used to dynamically coach the model on how the generated audio should sound:

```php
$audio = Audio::of('I love coding with Hypervel.')
    ->female()
    ->instructions('Said like a pirate')
    ->generate();
```

Generated audio may be easily stored on the default disk configured in your application's `config/filesystems.php` configuration file:

```php
$audio = Audio::of('I love coding with Hypervel.')->generate();

$path = $audio->store();
$path = $audio->storeAs('audio.mp3');
$path = $audio->storePublicly();
$path = $audio->storePubliclyAs('audio.mp3');
```

Audio generation may also be queued:

```php
use Hypervel\Ai\Audio;
use Hypervel\Ai\Responses\AudioResponse;

Audio::of('I love coding with Hypervel.')
    ->queue()
    ->then(function (AudioResponse $audio) {
        $path = $audio->store();

        // ...
    });
```

<a name="transcription"></a>
## Transcriptions

The `Hypervel\Ai\Transcription` class may be used to generate a transcript of the given audio:

```php
use Hypervel\Ai\Transcription;

$transcript = Transcription::fromPath('/home/hypervel/audio.mp3')->generate();
$transcript = Transcription::fromStorage('audio.mp3')->generate();
$transcript = Transcription::fromUpload($request->file('audio'))->generate();

return (string) $transcript;
```

The `diarize` method may be used to indicate you would like the response to include the diarized transcript in addition to the raw text transcript, allowing you to access the segmented transcript by speaker:

```php
$transcript = Transcription::fromStorage('audio.mp3')
    ->diarize()
    ->generate();
```

Transcription generation may also be queued:

```php
use Hypervel\Ai\Transcription;
use Hypervel\Ai\Responses\TranscriptionResponse;

Transcription::fromStorage('audio.mp3')
    ->queue()
    ->then(function (TranscriptionResponse $transcript) {
        // ...
    });
```

<a name="text-summarization"></a>
## Text Summarization

You may summarize text using the `summarize` method available via Hypervel's `Stringable` class. By default, the summary will contain no more than three sentences and will be generated using the configured provider's cheapest text model:

```php
use Hypervel\Support\Str;

$summary = Str::of($article)->summarize();
```

You may specify the maximum number of sentences, provider, model, and timeout used to generate the summary. The `Str` class also offers a static version of the method:

```php
use Hypervel\Ai\Enums\Lab;

$summary = Str::of($article)->summarize(
    sentences: 4,
    provider: Lab::Anthropic,
    model: 'claude-sonnet-5',
    timeout: 30,
);

$summary = Str::summarize($article, sentences: 4);
```

<a name="embeddings"></a>
## Embeddings

You may easily generate vector embeddings for any given string using the `toEmbeddings` method available via Hypervel's `Stringable` class:

```php
use Hypervel\Support\Str;

$embeddings = Str::of('Napa Valley has great wine.')->toEmbeddings();
```

Alternatively, you may use the `Embeddings` class to generate embeddings for multiple inputs at once:

```php
use Hypervel\Ai\Embeddings;

$response = Embeddings::for([
    'Napa Valley has great wine.',
    'Hypervel is a PHP framework.',
])->generate();

$response->embeddings; // [[0.123, 0.456, ...], [0.789, 0.012, ...]]
```

You may specify the dimensions and provider for the embeddings:

```php
$response = Embeddings::for(['Napa Valley has great wine.'])
    ->dimensions(1536)
    ->generate(Lab::OpenAI, 'text-embedding-3-small');
```

<a name="multimodal-embeddings"></a>
### Multimodal Embeddings

In addition to strings, the `Embeddings::for` method accepts image, audio, document, and video inputs, allowing you to generate embeddings for non-text content. Gemini supports image, audio, document, and video embeddings, while VoyageAI supports image and video embeddings:

```php
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Files\Video;

$response = Embeddings::for([
    'A vineyard at sunset.',
    Image::fromStorage('vineyard.jpg'),
    Video::fromPath('/home/hypervel/tour.mp4'),
])->generate(Lab::Gemini);
```

Multimodal inputs use the same [file classes used for attachments](#attachments). These files may be created from a local path, a filesystem disk, a remote URL, or Base64-encoded content. Images, audio, documents, and videos may also be created from uploaded files, while documents may be created from raw string content:

```php
use Hypervel\Ai\Files\Audio;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Files\Video;

Image::fromPath('/home/hypervel/photo.jpg');
Image::fromStorage('photo.jpg');
Image::fromUpload($request->file('photo'));

Audio::fromPath('/home/hypervel/clip.mp3');
Audio::fromStorage('clip.mp3');
Audio::fromUpload($request->file('clip'));

Video::fromPath('/home/hypervel/video.mp4');
Video::fromStorage('video.mp4');
Video::fromUpload($request->file('video'));

Document::fromUrl('https://example.com/report.pdf');
Document::fromString('Hypervel is a PHP framework.', 'text/plain');
Document::fromUpload($request->file('report'));
```

> [!NOTE]
> VoyageAI does not allow remote URL media and Base64-encoded media to be mixed in a single request. Local, stored, and uploaded files are sent as Base64-encoded content, and text inputs may be combined with either media source. Consult your provider's documentation to determine which multimodal models and inputs are available.

<a name="querying-embeddings"></a>
### Querying Embeddings

Once you have generated embeddings, you will typically store them in a `vector` column in your database for later querying. Hypervel provides native support for vector columns on PostgreSQL via the `pgvector` extension and MariaDB. To get started, define a `vector` column in your migration, specifying the number of dimensions:

```php
Schema::ensureVectorExtensionExists();

Schema::create('documents', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->text('content');
    $table->vector('embedding', dimensions: 1536);
    $table->timestamps();
});
```

You may also add a vector index to speed up similarity searches. When calling `index` on a vector column, Hypervel will automatically create an HNSW index with cosine distance:

```php
$table->vector('embedding', dimensions: 1536)->index();
```

On your Eloquent model, you should cast the vector column using the `AsVector` cast:

```php
use Hypervel\Database\Eloquent\Casts\AsVector;

protected function casts(): array
{
    return [
        'embedding' => AsVector::class,
    ];
}
```

To query for similar records, use the `whereVectorSimilarTo` method. This method filters results by a minimum cosine similarity (between `0.0` and `1.0`, where `1.0` is identical) and orders the results by similarity:

```php
use App\Models\Document;

$documents = Document::query()
    ->whereVectorSimilarTo('embedding', $queryEmbedding, minSimilarity: 0.4)
    ->limit(10)
    ->get();
```

The `$queryEmbedding` may be an array of floats or a plain string. When a string is given, Hypervel will automatically generate embeddings for it:

```php
$documents = Document::query()
    ->whereVectorSimilarTo('embedding', 'best wineries in Napa Valley')
    ->limit(10)
    ->get();
```

If you need more control, you may use the lower-level `whereVectorDistanceLessThan`, `selectVectorDistance`, and `orderByVectorDistance` methods independently:

```php
$documents = Document::query()
    ->select('*')
    ->selectVectorDistance('embedding', $queryEmbedding, as: 'distance')
    ->whereVectorDistanceLessThan('embedding', $queryEmbedding, maxDistance: 0.3)
    ->orderByVectorDistance('embedding', $queryEmbedding)
    ->limit(10)
    ->get();
```

If you would like to give an agent the ability to perform similarity searches as a tool, check out the [Similarity Search](#similarity-search) tool documentation. For index and query behavior, see [vector similarity clauses](/docs/{{version}}/queries#vector-similarity-clauses). [Scout's database engine](/docs/{{version}}/scout#database-engine) also supports semantic and hybrid search through the SDK.

> [!NOTE]
> Vector queries are currently supported on PostgreSQL connections using the `pgvector` extension, or on MariaDB 11.7 or later using its native vector support.

<a name="caching-embeddings"></a>
### Caching Embeddings

Embedding generation can be cached to avoid redundant API calls for identical inputs. To enable caching, set the `ai.caching.embeddings.cache` configuration option to `true`:

```php
'caching' => [
    'embeddings' => [
        'cache' => true,
        'store' => env('CACHE_STORE', 'database'),
        'individually' => true,
        // ...
    ],
],
```

When caching is enabled, embeddings are cached for 30 days by default. Set `ai.caching.embeddings.seconds` to change the default duration. The cache key includes the provider's name, credentials, configuration, model, dimensions, provider options, and input content. Providers using different accounts or endpoints do not share cached embeddings.

Headers in the provider's configuration are included in the cache key. Headers passed to `withHeaders` apply only to the request and do not change the key. If a header selects an account, include it in the provider's configuration, including when using on-demand providers or a provider configuration resolver. Alternatively, use an application cache scope that separates those accounts.

By default, each input's embedding is cached under its own key, so a later request may hit the cache for inputs it has seen before even when the set of inputs or their order has changed. To instead cache the entire set of inputs under a single key, set the `ai.caching.embeddings.individually` configuration option to `false`.

You may also enable caching for a specific request using the `cache` method, even when global caching is disabled:

```php
$response = Embeddings::for(['Napa Valley has great wine.'])
    ->cache()
    ->generate();
```

You may specify a custom cache duration in seconds:

```php
$response = Embeddings::for(['Napa Valley has great wine.'])
    ->cache(seconds: 3600) // Cache for 1 hour
    ->generate();
```

The `toEmbeddings` Stringable method also accepts a `cache` argument:

```php
// Cache with default duration...
$embeddings = Str::of('Napa Valley has great wine.')->toEmbeddings(cache: true);

// Cache for a specific duration...
$embeddings = Str::of('Napa Valley has great wine.')->toEmbeddings(cache: 3600);
```

Cached embeddings are stored as JSON. If you want to reduce Redis storage or transfer sizes, configure compression on your [Redis connection](/docs/{{version}}/redis#phpredis-serialization).

<a name="embedding-cache-scopes"></a>
#### Cache Scopes

To separate cached embeddings by account even when accounts share provider credentials, register a scope resolver once during boot:

```php
use Hypervel\Ai\Ai;
use Hypervel\Support\Facades\Context;

Ai::resolveEmbeddingsCacheScopeUsing(function (): ?string {
    $accountId = Context::get('account_id');

    return $accountId === null ? null : 'account:' . $accountId;
});
```

The scope is resolved for each generation attempt that uses caching. Returning null allows accounts using the same provider configuration to share cached embeddings; an empty string is invalid. If your application requires an account for every operation, throw when it is missing instead of returning null. Scope values must remain stable across workers that share a cache. Credentials are hashed, not written into cache keys.

<a name="reranking"></a>
## Reranking

Reranking allows you to reorder a list of documents based on their relevance to a given query. This is useful for improving search results by using semantic understanding:

The `Hypervel\Ai\Reranking` class may be used to rerank documents:

```php
use Hypervel\Ai\Reranking;

$response = Reranking::of([
    'Django is a Python web framework.',
    'Hypervel is a PHP web application framework.',
    'React is a JavaScript library for building user interfaces.',
])->rerank('PHP frameworks');

// Access the top result...
$response->first()->document; // "Hypervel is a PHP web application framework."
$response->first()->score;    // 0.95
$response->first()->index;    // 1 (original position)
```

The `limit` method may be used to restrict the number of results returned, while the `timeout` method may be used to specify the HTTP timeout in seconds, which defaults to 30:

```php
$response = Reranking::of($documents)
    ->limit(5)
    ->timeout(60)
    ->rerank('search query');
```

<a name="reranking-collections"></a>
### Reranking Collections

For convenience, Hypervel collections may be reranked using the `rerank` macro. The first argument specifies which field(s) to use for reranking, and the second argument is the query:

```php
// Rerank by a single field...
$posts = Post::all()
    ->rerank('body', 'Hypervel tutorials');

// Rerank by multiple fields (sent as JSON)...
$reranked = $posts->rerank(['title', 'body'], 'Hypervel tutorials');

// Rerank using a closure to build the document...
$reranked = $posts->rerank(
    fn ($post) => $post->title.': '.$post->body,
    'Hypervel tutorials'
);
```

You may also limit the number of results and specify a provider:

```php
$reranked = $posts->rerank(
    by: 'content',
    query: 'Hypervel tutorials',
    limit: 10,
    provider: Lab::Cohere,
    timeout: 60,
);
```

<a name="classification"></a>
## Classification

> [!WARNING]
> Classification is currently experimental and its API may change in future minor releases of the AI SDK.

Classification allows you to ask a fixed set of questions about a given string or array of data and receive a typed answer, backed by a probability, for each question instead of free-form text. This is useful for routing, moderation, and scoring, where you need to compare an answer against a threshold or make assertions about it in your tests.

The `Hypervel\Ai\Classification` class may be used to classify content. Each question is given a key, and the corresponding answer may be retrieved from the response using that key:

```php
use Hypervel\Ai\Classification;
use Hypervel\Ai\Classification\Boolean;
use Hypervel\Ai\Classification\Choice;
use Hypervel\Ai\Classification\Score;

$result = Classification::of($supportRequest)
    ->questions([
        'urgent' => new Boolean('Does this request need an immediate response?', [
            'true' => 'Explicitly time-sensitive',
            'false' => 'No urgency expressed',
        ]),
        'department' => new Choice('Which team should handle this request?', [
            'billing' => 'Payments, invoices, and refunds',
            'technical' => 'Bugs, outages, and integrations',
            'sales' => 'Pricing, plans, and upgrades',
        ]),
        'frustration' => new Score('How frustrated is the customer?', [
            'Calm',
            'Frustrated',
            'Very angry',
        ]),
    ])
    ->classify();
```

`Boolean` questions return the probability that the answer is "true". The `isTrue` method may be used to determine whether that probability meets a given threshold, which defaults to `0.5`:

```php
$result['urgent']->probability;             // 0.94
$result['urgent']->isTrue(threshold: 0.8);  // true
```

`Choice` questions return one of the given options along with the probability of each option. The `confidence` property indicates how certain the provider is across the full set of probabilities, and will be `null` when the provider is unable to measure it:

```php
$result['department']->choice;                      // 'technical'
$result['department']->probabilityOf('technical');  // 0.87
$result['department']->probabilities;               // ['billing' => 0.08, 'technical' => 0.87, 'sales' => 0.05]
$result['department']->confidence;                  // 0.82
```

`Score` questions return a position on the ordered levels that were provided. The `score` property is probability-weighted and may fall between two levels, while the `level` and `label` methods describe the most probable level:

```php
$result['frustration']->score;          // 1.24, the probability-weighted level
$result['frustration']->level();        // 1, the most probable level
$result['frustration']->label();        // 'Frustrated'
$result['frustration']->normalized();   // 0.62, the score as a fraction of the highest level
$result['frustration']->probabilities;  // [0.12, 0.52, 0.36]
```

The criteria given to a `Boolean` question, the option descriptions given to a `Choice` question, and the levels given to a `Score` question may each be an array when a single sentence is not sufficient. `Choice` questions require at least two options, while `Score` questions require at least two levels.

The response may be iterated, counted, and accessed as an array. In addition, the `answer` method may be used to retrieve a single answer, while the `collect` method returns all of the answers as a [collection](/docs/{{version}}/collections):

```php
$result->answer('urgent');
$result->collect();

$result->usage;
$result->meta->provider;
```

<a name="yes-or-no-decisions"></a>
### Yes or No Decisions

For a single yes or no decision, you may use the `decide` method available via Hypervel's `Stringable` class, which returns a boolean instead of a full response. You may describe what a "yes" and a "no" mean, and specify the probability the answer must reach, which defaults to `0.5`:

```php
use Hypervel\Support\Str;

if (Str::of($message)->decide('Is this spam?')) {
    // ...
}

$spam = Str::of($message)->decide('Is this spam?', criteria: [
    'true' => 'Unsolicited bulk mail.',
    'false' => 'A genuine message from a customer.',
], threshold: 0.9);
```

<a name="choosing-from-collections"></a>
### Choosing From Collections

To quickly choose a single item from a list of options, you may use the `decide` method available on Hypervel's `Collection` class. The method accepts a question and the text to classify, and returns the chosen item from the collection.

Collections of strings and enums may be used directly, while other items should be named using the `by` argument. You may also provide a field, array of fields, or closure via the `describe` argument to give the model more detail about each option:

```php
$department = collect(['billing', 'technical', 'sales'])
    ->decide('Which team should handle this request?', $ticket->body);

$priority = collect(Priority::cases())
    ->decide('How urgent is this request?', $ticket->body);

$department = Department::all()->decide(
    'Which department should handle this request?',
    $ticket->body,
    by: 'name',
    describe: 'description',
);
```

The description defined via the `describe` argument serves as the criteria the model uses to determine whether the text matches that option, which is helpful when an option's name alone is ambiguous. When a closure is provided to the `describe` argument, it receives each item and should return a string or array describing it:

```php
$priority = collect(Priority::cases())->decide(
    'How urgent is this request?',
    $ticket->body,
    describe: fn (Priority $priority) => $priority->description(),
);
```

When a collection of strings is keyed by strings, its keys are used as the options and its values as their descriptions, and the chosen key is returned. If a `threshold` is given and the probability of the chosen option is below it, `null` is returned:

```php
$department = collect([
    'billing' => 'Payments, invoices, and refunds',
    'technical' => 'Bugs, outages, and integrations',
])->decide('Which team should handle this request?', $ticket->body, threshold: 0.6) ?? 'triage';
```

<a name="files"></a>
## Files

The `Hypervel\Ai\Files` class or the individual file classes may be used to store files with your AI provider for later use in conversations. This is useful for large documents or files you want to reference multiple times without re-uploading:

```php
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\Image;

// Store a file from a local path...
$response = Document::fromPath('/home/hypervel/document.pdf')->put();
$response = Image::fromPath('/home/hypervel/photo.jpg')->put();

// Store a file that is stored on a filesystem disk...
$response = Document::fromStorage('document.pdf', disk: 'local')->put();
$response = Image::fromStorage('photo.jpg', disk: 'local')->put();

// Store a file that is stored on a remote URL...
$response = Document::fromUrl('https://example.com/document.pdf')->put();
$response = Image::fromUrl('https://example.com/photo.jpg')->put();

return $response->id;
```

You may also store raw content or uploaded files:

```php
use Hypervel\Ai\Files;
use Hypervel\Ai\Files\Document;

// Store raw content...
$stored = Document::fromString('Hello, World!', 'text/plain')->put();

// Store an uploaded file...
$stored = Document::fromUpload($request->file('document'))->put();
```

Once a file has been stored, you may reference the file when generating text via agents instead of re-uploading the file:

```php
use App\Ai\Agents\SalesCoach;
use Hypervel\Ai\Files;

$response = (new SalesCoach)->prompt(
    'Analyze the attached sales transcript...',
    attachments: [
        Files\Document::fromId('file-id') // Attach a stored document...
    ]
);
```

To retrieve a previously stored file, use the `get` method on a file instance:

```php
use Hypervel\Ai\Files\Document;

$file = Document::fromId('file-id')->get();

$file->id;
$file->mimeType();
```

To delete a file from the provider, use the `delete` method:

```php
Document::fromId('file-id')->delete();
```

By default, the `Files` class uses the default AI provider configured in your application's `config/ai.php` configuration file. For most operations, you may specify a different provider using the `provider` argument:

```php
$response = Document::fromPath(
    '/home/hypervel/document.pdf'
)->put(provider: Lab::Anthropic);
```

You may pass provider-specific upload options using the `withProviderOptions` method. For example, you may set OpenAI's file `purpose`:

```php
use Hypervel\Ai\Files\Document;

$response = Document::fromPath('/home/hypervel/knowledge.txt')
    ->withProviderOptions(['purpose' => 'assistants'])
    ->put();
```

To scope options per provider, pass a closure that receives the current provider:

```php
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Files\Document;

$response = Document::fromPath('/home/hypervel/training.jsonl')
    ->withProviderOptions(fn (Lab|string $provider) => match ($provider) {
        Lab::OpenAI => ['purpose' => 'fine-tune'],
        default => [],
    })
    ->put();
```

<a name="using-stored-files-in-conversations"></a>
### Using Stored Files in Conversations

Once a file has been stored with a provider, you may reference it in agent conversations using the `fromId` method on the `Document` or `Image` classes:

```php
use App\Ai\Agents\DocumentAnalyzer;
use Hypervel\Ai\Files;
use Hypervel\Ai\Files\Document;

$stored = Document::fromPath('/path/to/report.pdf')->put();

$response = (new DocumentAnalyzer)->prompt(
    'Summarize this document.',
    attachments: [
        Document::fromId($stored->id),
    ],
);
```

Similarly, stored images may be referenced using the `Image` class:

```php
use Hypervel\Ai\Files;
use Hypervel\Ai\Files\Image;

$stored = Image::fromPath('/path/to/photo.jpg')->put();

$response = (new ImageAnalyzer)->prompt(
    'What is in this image?',
    attachments: [
        Image::fromId($stored->id),
    ],
);
```

<a name="vector-stores"></a>
## Vector Stores

Vector stores allow you to create searchable collections of files that can be used for retrieval-augmented generation (RAG). The `Hypervel\Ai\Stores` class provides methods for creating, retrieving, and deleting vector stores:

```php
use Hypervel\Ai\Stores;

// Create a new vector store...
$store = Stores::create('Knowledge Base');

// Create a store with additional options...
$store = Stores::create(
    name: 'Knowledge Base',
    description: 'Documentation and reference materials.',
    expiresWhenIdleFor: days(30),
);

return $store->id;
```

To retrieve an existing vector store by its ID, use the `get` method:

```php
use Hypervel\Ai\Stores;

$store = Stores::get('store_id');

$store->id;
$store->name;
$store->fileCounts;
$store->ready;
```

To delete a vector store, use the `delete` method on the `Stores` class or the store instance:

```php
use Hypervel\Ai\Stores;

// Delete by ID...
Stores::delete('store_id');

// Or delete via a store instance...
$store = Stores::get('store_id');

$store->delete();
```

<a name="adding-files-to-stores"></a>
### Adding Files to Stores

Once you have a vector store, you may add [files](#files) to it using the `add` method. Files added to a store are automatically indexed for semantic searching using the [file search provider tool](#file-search):

```php
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Stores;

$store = Stores::get('store_id');

// Add a file that has already been stored with the provider...
$document = $store->add('file_id');
$document = $store->add(Document::fromId('file_id'));

// Or, store and add a file in one step...
$document = $store->add(Document::fromPath('/path/to/document.pdf'));
$document = $store->add(Document::fromStorage('manual.pdf'));
$document = $store->add($request->file('document'));

$document->id;
$document->fileId;
```

> **Note:** Typically, when adding previously stored files to vector stores, the returned document ID will match the file's previously assigned ID; however, some vector storage providers may return a new, different "document ID". Therefore, it's recommended that you always store both IDs in your database for future reference.

When adding a file to a Gemini store, Hypervel waits for the import to finish so that the document is searchable once the call returns. A `Hypervel\Ai\Exceptions\AiException` will be thrown if the import fails or exceeds five minutes, so you may wish to add Gemini files from a [queued job](/docs/{{version}}/queues).

You may attach metadata to files when adding them to a store. This metadata can later be used to filter search results when using the [file search provider tool](#file-search):

```php
$store->add(Document::fromPath('/path/to/document.pdf'), metadata: [
    'author' => 'Taylor Otwell',
    'department' => 'Engineering',
    'year' => 2026,
]);
```

To remove a file from a store, use the `remove` method:

```php
$store->remove('file_id');
```

Removing a file from a vector store does not remove it from the provider's [file storage](#files). To remove a file from the vector store and delete it permanently from file storage, use the `deleteFile` argument:

```php
$store->remove('file_abc123', deleteFile: true);
```

<a name="usage"></a>
## Usage

Every response contains a `usage` property containing the token counts reported by the provider. The input and output counts are totals, so tokens counted as cached or reasoning tokens are also included in the total they belong to:

```php
$response = (new SalesCoach)->prompt('Analyze this sales transcript...');

$response->usage->inputTokens;
$response->usage->outputTokens;
$response->usage->totalTokens();
```

Text generation returns a `Hypervel\Ai\Responses\Data\TextUsage` instance, which breaks these totals down further. Each of these values will be `null`, rather than `0`, when the provider does not report it:

```php
$response->usage->cacheReadInputTokens; // Subset of the input tokens read from a prompt cache...
$response->usage->cacheWriteInputTokens; // Subset of the input tokens written to a prompt cache...
$response->usage->reasoningTokens; // Subset of the output tokens spent on reasoning...

$response->usage->uncachedInputTokens(); // Input tokens that were neither read from nor written to the cache...
```

Cache reads, cache writes, and uncached input are billed at different rates, so you should price these three counts separately instead of using the input total alone.

The remaining capabilities return a usage object containing the counts specific to them:

<div class="overflow-auto">

| Capability | Usage object | Adds |
|---|---|---|
| Text, classification | `TextUsage` | Cache read, cache write, and reasoning tokens |
| Images | `ImageUsage` | `imageInputTokens` and `imageOutputTokens` |
| Transcription | `TranscriptionUsage` | `audioSeconds`, the duration of the transcribed audio |
| Reranking | `RerankingUsage` | `searchUnits`, which some providers bill instead of tokens |
| Audio, embeddings | `Usage` | |

</div>

Not every provider reports every count, and counts that a provider does not report will be `null`:

```php
use Hypervel\Ai\Image;
use Hypervel\Ai\Transcription;

Image::of('A donut sitting on the kitchen counter')->generate()->usage->imageOutputTokens;

Transcription::fromPath('/home/hypervel/meeting.mp3')->generate()->usage->audioSeconds;
```

<a name="failover"></a>
## Failover

When prompting or generating other media, you may provide an array of providers / models to automatically failover to a backup provider / model if a service interruption or rate limit is encountered on the primary provider:

```php
use App\Ai\Agents\SalesCoach;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Image;

$response = (new SalesCoach)->prompt(
    'Analyze this sales transcript...',
    provider: [Lab::OpenAI, Lab::Anthropic],
);

$image = Image::of('A donut sitting on the kitchen counter')
    ->generate(provider: [Lab::Gemini, Lab::xAI]);
```

Failover only occurs when a `FailoverableException` is thrown — such as a rate limit (`RateLimitedException`), an overloaded or unavailable provider (`ProviderOverloadedException`), or insufficient credits (`InsufficientCreditsException`). Ordinary errors, like a validation or bad request error, will not trigger failover.

An agent does not fail over after a tool starts, since restarting the run could repeat its side effects. Streams also stop failing over once an event has reached the consumer. The original exception is reported, and remembering agents retain the steps and tool results completed before the failure.

Providers are tried in the order you supply them. Each entry is retained, including provider objects that share a name. Listing the same provider twice allows a second attempt if the first fails with a failoverable exception.

When you pass a plain list of providers, such as `[Lab::OpenAI, Lab::Anthropic]`, each provider uses its default model. To specify a particular model for each provider in the failover chain, pass an associative array keyed by the provider, using the `Lab` enum's `value` as the key (enum cases cannot be used directly as PHP array keys):

```php
use Hypervel\Ai\Enums\Lab;

$response = (new SalesCoach)->prompt(
    'Analyze this sales transcript...',
    provider: [
        Lab::Gemini->value => 'gemini-3-flash-preview',
        Lab::DeepSeek->value => 'deepseek-v4-pro',
    ],
);
```

If your agent overrides the protected `getProvidersAndModels()` method, return an ordered list of provider/model pairs:

```php
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Providers\Provider;

protected function getProvidersAndModels(Provider|Lab|array|string|null $provider, ?string $model): array
{
    return [
        [$this->accountProvider(), 'model-a'],
        ['openai', 'gpt-4o'],
    ];
}
```

Unlike Laravel's name-keyed map, these pairs retain each provider object and its credentials. Calls to `prompt()` and `stream()` continue to accept the provider lists and associative arrays shown above.

<a name="testing"></a>
## Testing

Configure AI fakes in your tests, not while serving requests. They replace worker-shared provider behavior. Hypervel's test cleanup resets them between tests.

When faking queued image, audio, transcription, or embeddings generation, any `then` callback registered on the queued generation will be invoked with the faked response, allowing you to test the logic contained within the callback. If you would prefer that these callbacks are not invoked, you may fake the queue using `Queue::fake()` as well.

<a name="testing-agents"></a>
### Agents

To fake an agent's responses during tests, call the `fake` method on the agent class. You may optionally provide an array of responses or a closure:

```php
use App\Ai\Agents\SalesCoach;
use Hypervel\Ai\Prompts\AgentPrompt;

// Automatically generate a fixed response for every prompt...
SalesCoach::fake();

// Provide a list of prompt responses...
SalesCoach::fake([
    'First response',
    'Second response',
]);

// Dynamically handle prompt responses based on the incoming prompt...
SalesCoach::fake(function (AgentPrompt $prompt) {
    return 'Response for: '.$prompt->prompt;
});
```

When faking an agent that returns structured output, you may provide arrays as responses. The agent will return a structured response containing the given data:

```php
SalesCoach::fake([
    ['score' => 87],
]);
```

You may also fake a response that is awaiting tool approval:

```php
use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Responses\AgentResponse;

FileAssistant::fake([
    AgentResponse::fakeWithPendingApprovals([
        new PendingApproval(
            id: 'call_abc',
            tool: 'DeleteFile',
            arguments: ['path' => 'invoice.pdf'],
            reason: 'This will permanently delete a file.',
        ),
    ]),
]);

$response = (new FileAssistant)->prompt('Delete the invoice.');

$response->hasPendingApprovals(); // true
```

In addition, you can fake a response that includes reasoning. The fake emits reasoning events, so the reasoning is reported on streamed runs as well:

```php
use Hypervel\Ai\Responses\AgentResponse;

SalesCoach::fake([
    AgentResponse::fakeWithReasoning('They asked about pricing.', 'Plans start at $10.'),
]);

$response = (new SalesCoach)->stream('What does it cost?');

foreach ($response as $event) {
    // ...
}

$response->reasoning; // 'They asked about pricing.'
```

> **Note:** When `Agent::fake()` is invoked on an agent that returns structured output and fake output was not explicitly provided, Hypervel will automatically generate fake data that matches your agent's defined output schema.

After prompting the agent, you may make assertions about the prompts that were received:

```php
use Hypervel\Ai\Prompts\AgentPrompt;

SalesCoach::assertPrompted('Analyze this...');

SalesCoach::assertPrompted(function (AgentPrompt $prompt) {
    return $prompt->contains('Analyze');
});

SalesCoach::assertPromptedTimes(3);

SalesCoach::assertNotPrompted('Missing prompt');

SalesCoach::assertNeverPrompted();
```

When asserting an approval continuation, you may inspect the prompt's approval decisions:

```php
use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Prompts\AgentPrompt;

FileAssistant::fake();

(new FileAssistant)->prompt(Decisions::from([
    'call_abc' => true,
]));

FileAssistant::assertPrompted(function (AgentPrompt $prompt) {
    return $prompt->hasApprovalDecisions()
        && $prompt->approvalDecisions->get('call_abc')->isApproved();
});
```

For queued agent invocations, use the queued assertion methods:

```php
use Hypervel\Ai\QueuedAgentPrompt;

SalesCoach::assertQueued('Analyze this...');

SalesCoach::assertQueued(function (QueuedAgentPrompt $prompt) {
    return $prompt->contains('Analyze');
});

SalesCoach::assertNotQueued('Missing prompt');

SalesCoach::assertNeverQueued();
```

To ensure all agent invocations have a corresponding fake response, you may use `preventStrayPrompts`. If an agent is invoked without a defined fake response, an exception will be thrown:

```php
SalesCoach::fake()->preventStrayPrompts();
```

<a name="testing-images"></a>
### Images

Image generations may be faked by invoking the `fake` method on the `Image` class. Once image has been faked, various assertions may be performed against the recorded image generation prompts:

```php
use Hypervel\Ai\Image;
use Hypervel\Ai\Prompts\ImagePrompt;
use Hypervel\Ai\Prompts\QueuedImagePrompt;

// Automatically generate a fixed response for every prompt...
Image::fake();

// Provide a list of prompt responses...
Image::fake([
    base64_encode($firstImage),
    base64_encode($secondImage),
]);

// Dynamically handle prompt responses based on the incoming prompt...
Image::fake(function (ImagePrompt $prompt) {
    return base64_encode('...');
});
```

After generating images, you may make assertions about the prompts that were received:

```php
Image::assertGenerated(function (ImagePrompt $prompt) {
    return $prompt->contains('sunset') && $prompt->isLandscape();
});

Image::assertNotGenerated('Missing prompt');

Image::assertNothingGenerated();
```

For queued image generations, use the queued assertion methods:

```php
Image::assertQueued(
    fn (QueuedImagePrompt $prompt) => $prompt->contains('sunset')
);

Image::assertNotQueued('Missing prompt');

Image::assertNothingQueued();
```

To ensure all image generations have a corresponding fake response, you may use `preventStrayImages`. If an image is generated without a defined fake response, an exception will be thrown:

```php
Image::fake()->preventStrayImages();
```

<a name="testing-audio"></a>
### Audio

Audio generations may be faked by invoking the `fake` method on the `Audio` class. Once audio has been faked, various assertions may be performed against the recorded audio generation prompts:

```php
use Hypervel\Ai\Audio;
use Hypervel\Ai\Prompts\AudioPrompt;
use Hypervel\Ai\Prompts\QueuedAudioPrompt;

// Automatically generate a fixed response for every prompt...
Audio::fake();

// Provide a list of prompt responses...
Audio::fake([
    base64_encode($firstAudio),
    base64_encode($secondAudio),
]);

// Dynamically handle prompt responses based on the incoming prompt...
Audio::fake(function (AudioPrompt $prompt) {
    return base64_encode('...');
});
```

After generating audio, you may make assertions about the prompts that were received:

```php
Audio::assertGenerated(function (AudioPrompt $prompt) {
    return $prompt->contains('Hello') && $prompt->isFemale();
});

Audio::assertNotGenerated('Missing prompt');

Audio::assertNothingGenerated();
```

For queued audio generations, use the queued assertion methods:

```php
Audio::assertQueued(
    fn (QueuedAudioPrompt $prompt) => $prompt->contains('Hello')
);

Audio::assertNotQueued('Missing prompt');

Audio::assertNothingQueued();
```

To ensure all audio generations have a corresponding fake response, you may use `preventStrayAudio`. If audio is generated without a defined fake response, an exception will be thrown:

```php
Audio::fake()->preventStrayAudio();
```

<a name="testing-transcriptions"></a>
### Transcriptions

Transcription generations may be faked by invoking the `fake` method on the `Transcription` class. Once transcription has been faked, various assertions may be performed against the recorded transcription generation prompts:

```php
use Hypervel\Ai\Transcription;
use Hypervel\Ai\Prompts\TranscriptionPrompt;
use Hypervel\Ai\Prompts\QueuedTranscriptionPrompt;

// Automatically generate a fixed response for every prompt...
Transcription::fake();

// Provide a list of prompt responses...
Transcription::fake([
    'First transcription text.',
    'Second transcription text.',
]);

// Dynamically handle prompt responses based on the incoming prompt...
Transcription::fake(function (TranscriptionPrompt $prompt) {
    return 'Transcribed text...';
});
```

After generating transcriptions, you may make assertions about the prompts that were received:

```php
Transcription::assertGenerated(function (TranscriptionPrompt $prompt) {
    return $prompt->language === 'en' && $prompt->isDiarized();
});

Transcription::assertNotGenerated(
    fn (TranscriptionPrompt $prompt) => $prompt->language === 'fr'
);

Transcription::assertNothingGenerated();
```

For queued transcription generations, use the queued assertion methods:

```php
Transcription::assertQueued(
    fn (QueuedTranscriptionPrompt $prompt) => $prompt->isDiarized()
);

Transcription::assertNotQueued(
    fn (QueuedTranscriptionPrompt $prompt) => $prompt->language === 'fr'
);

Transcription::assertNothingQueued();
```

To ensure all transcription generations have a corresponding fake response, you may use `preventStrayTranscriptions`. If a transcription is generated without a defined fake response, an exception will be thrown:

```php
Transcription::fake()->preventStrayTranscriptions();
```

<a name="testing-embeddings"></a>
### Embeddings

Embeddings generations may be faked by invoking the `fake` method on the `Embeddings` class. Once embeddings has been faked, various assertions may be performed against the recorded embeddings generation prompts:

```php
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Prompts\EmbeddingsPrompt;
use Hypervel\Ai\Prompts\QueuedEmbeddingsPrompt;

// Automatically generate fake embeddings of the proper dimensions for every prompt...
Embeddings::fake();

// Provide a list of prompt responses...
Embeddings::fake([
    [$firstEmbeddingVector],
    [$secondEmbeddingVector],
]);

// Dynamically handle prompt responses based on the incoming prompt...
Embeddings::fake(function (EmbeddingsPrompt $prompt) {
    return array_map(
        fn () => Embeddings::fakeEmbedding($prompt->dimensions),
        $prompt->inputs
    );
});
```

After generating embeddings, you may make assertions about the prompts that were received:

```php
Embeddings::assertGenerated(function (EmbeddingsPrompt $prompt) {
    return $prompt->contains('Hypervel') && $prompt->dimensions === 1536;
});

Embeddings::assertNotGenerated(
    fn (EmbeddingsPrompt $prompt) => $prompt->contains('Other')
);

Embeddings::assertNothingGenerated();
```

For queued embeddings generations, use the queued assertion methods:

```php
Embeddings::assertQueued(
    fn (QueuedEmbeddingsPrompt $prompt) => $prompt->contains('Hypervel')
);

Embeddings::assertNotQueued(
    fn (QueuedEmbeddingsPrompt $prompt) => $prompt->contains('Other')
);

Embeddings::assertNothingQueued();
```

To ensure all embeddings generations have a corresponding fake response, you may use `preventStrayEmbeddings`. If embeddings are generated without a defined fake response, an exception will be thrown:

```php
Embeddings::fake()->preventStrayEmbeddings();
```

<a name="testing-reranking"></a>
### Reranking

Reranking operations may be faked by invoking the `fake` method on the `Reranking` class:

```php
use Hypervel\Ai\Reranking;
use Hypervel\Ai\Prompts\RerankingPrompt;
use Hypervel\Ai\Responses\Data\RankedDocument;

// Automatically generate a fake reranked responses...
Reranking::fake();

// Provide custom responses...
Reranking::fake([
    [
        new RankedDocument(index: 0, document: 'First', score: 0.95),
        new RankedDocument(index: 1, document: 'Second', score: 0.80),
    ],
]);
```

After reranking, you may make assertions about the operations that were performed:

```php
Reranking::assertReranked(function (RerankingPrompt $prompt) {
    return $prompt->contains('Hypervel') && $prompt->limit === 5;
});

Reranking::assertNotReranked(
    fn (RerankingPrompt $prompt) => $prompt->contains('Django')
);

Reranking::assertNothingReranked();
```

<a name="testing-classification"></a>
### Classification

Classification may be faked by invoking the `fake` method on the `Classification` class. If no custom responses are provided, Hypervel will automatically generate answers that match the shape of each question:

```php
use Hypervel\Ai\Classification;
use Hypervel\Ai\Prompts\ClassificationPrompt;
use Hypervel\Ai\Responses\Data\BooleanAnswer;
use Hypervel\Ai\Responses\Data\ChoiceAnswer;

// Automatically generate fake answers...
Classification::fake();

// Provide answers for specific questions...
Classification::fake([
    [
        'urgent' => new BooleanAnswer(0.94),
        'department' => new ChoiceAnswer('technical', [
            'billing' => 0.08,
            'technical' => 0.87,
            'sales' => 0.05,
        ], confidence: 0.82),
    ],
]);

// Build answers from the prompt...
Classification::fake(fn (ClassificationPrompt $prompt) => [
    'urgent' => new BooleanAnswer($prompt->contains('ASAP') ? 1.0 : 0.0),
]);
```

Questions that are omitted from a fake response will still receive a generated answer, so your tests only need to provide the answers they make assertions against.

After classifying, you may make assertions about the operations that were performed:

```php
Classification::assertClassified(function (ClassificationPrompt $prompt) {
    return $prompt->contains('refund') && $prompt->asks('department');
});

Classification::assertNotClassified(
    fn (ClassificationPrompt $prompt) => $prompt->asks('sentiment')
);

Classification::assertNothingClassified();
```

<a name="testing-files"></a>
### Files

File operations may be faked by invoking the `fake` method on the `Files` class:

```php
use Hypervel\Ai\Files;

Files::fake();
```

Once file operations have been faked, you may make assertions about the uploads and deletions that occurred:

```php
use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Files\Document;

// Store files...
Document::fromString('Hello, Hypervel!', mimeType: 'text/plain')
    ->as('hello.txt')
    ->put();

// Make assertions...
Files::assertStored(fn (StorableFile $file) =>
    (string) $file === 'Hello, Hypervel!' &&
        $file->mimeType() === 'text/plain'
);

Files::assertNotStored(fn (StorableFile $file) =>
    (string) $file === 'Hello, World!'
);

Files::assertNothingStored();
```

For asserting against file deletions, you may pass a file ID:

```php
Files::assertDeleted('file-id');
Files::assertNotDeleted('file-id');
Files::assertNothingDeleted();
```

<a name="testing-vector-stores"></a>
### Vector Stores

Vector store operations may be faked by invoking the `fake` method on the `Stores` class. Faking stores will also fake [file operations](#files) automatically:

```php
use Hypervel\Ai\Stores;

Stores::fake();
```

Once store operations have been faked, you may make assertions about the stores that were created or deleted:

```php
use Hypervel\Ai\Stores;

// Create store...
$store = Stores::create('Knowledge Base');

// Make assertions...
Stores::assertCreated('Knowledge Base');

Stores::assertCreated(fn (string $name, ?string $description) =>
    $name === 'Knowledge Base'
);

Stores::assertNotCreated('Other Store');

Stores::assertNothingCreated();
```

For asserting against store deletions, you may provide the store ID:

```php
Stores::assertDeleted('store_id');
Stores::assertNotDeleted('other_store_id');
Stores::assertNothingDeleted();
```

To assert files were added or removed from a store, use the assertion methods on a given `Store` instance:

```php
Stores::fake();

$store = Stores::get('store_id');

// Add / remove files...
$store->add('added_id');
$store->remove('removed_id');

// Make assertions...
$store->assertAdded('added_id');
$store->assertRemoved('removed_id');

$store->assertNotAdded('other_file_id');
$store->assertNotRemoved('other_file_id');
```

If a file is stored in the provider's [file storage](#files) and added to a vector store in the same request, you may not know the file's provider ID. In this case, you can pass a closure to the `assertAdded` method to assert against the content of the added file:

```php
use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Files\Document;

$store->add(Document::fromString('Hello, World!', 'text/plain')->as('hello.txt'));

$store->assertAdded(fn (StorableFile $file) => $file->name() === 'hello.txt');
$store->assertAdded(fn (StorableFile $file) => $file->content() === 'Hello, World!');
```

<a name="events"></a>
## Events

The Hypervel AI SDK dispatches a variety of [events](/docs/{{version}}/events), including:

- `AddingFileToStore`
- `AgentFailed`
- `AgentFailedOver`
- `AgentPrompted`
- `AgentStreamAbandoned`
- `AgentStreamed`
- `AudioGenerated`
- `ClassificationFailed`
- `Classified`
- `Classifying`
- `CreatingStore`
- `EmbeddingsFailed`
- `EmbeddingsGenerated`
- `FileAddedToStore`
- `FileDeleted`
- `FileRemovedFromStore`
- `FileStored`
- `GeneratingAudio`
- `GeneratingEmbeddings`
- `GeneratingImage`
- `GeneratingTranscription`
- `ImageGenerated`
- `InvokingTool`
- `PromptingAgent`
- `ProviderFailedOver`
- `RemovingFileFromStore`
- `Reranked`
- `Reranking`
- `StartingStep`
- `StepCompleted`
- `StepFailed`
- `StoreCreated`
- `StoreDeleted`
- `StoringFile`
- `StreamingAgent`
- `ToolApprovalRequested`
- `ToolApprovalResolved`
- `ToolFailed`
- `ToolInvoked`
- `TranscriptionGenerated`

You can listen to any of these events to log or store AI SDK usage information.

Resolving providers and tools and capturing conversation history happen before provider execution. If this setup fails, the exception is thrown directly without provider lifecycle events such as `PromptingAgent` or `AgentFailed`.

`StartingStep` includes the instructions, tools, messages, model, and options actually sent for that step, including changes made by middleware. Listeners may inspect these values without calling the agent's `instructions` or `tools` methods again. Queued listeners receive an empty `tools` list, since live tool instances may contain closures or anonymous classes that cannot be serialized.

`ClassificationFailed` and `EmbeddingsFailed` report each failed provider attempt, including attempts followed by failover. They contain the invocation ID, provider, model, prompt, and original exception. Errors thrown by success-event listeners do not produce failure events.

`AgentStreamAbandoned` is dispatched when an unfinished stream is closed without an exception, such as when consumption stops early or an HTTP response write fails. A thrown exception instead follows the normal failure or failover path. Stream aggregation and conversation-persistence failures also report `AgentFailed`; errors after `AgentStreamed`, including errors in its listeners, do not report another terminal event. Replaying a completed response does not dispatch these lifecycle events again.

Queued listeners require a serializable event. Raw uploaded files cannot be serialized. If you use queued listeners, attach uploads using the `fromUpload()` method of the matching `Hypervel\Ai\Files` class, such as `Document::fromUpload()`, `Image::fromUpload()` or `Audio::fromUpload()`, to include the file's contents. Alternatively, store the file on a disk the queue worker can access and attach it using `fromStorage()`.

Failure and failover events also include the thrown exception, which PHP can't always serialize. For those events, or when a listener only needs a few values such as token usage, handle the event synchronously and dispatch a job containing only those values.
