<?php

declare(strict_types=1);

use Hypervel\Ai\AgentUserInteraction\AgentUserInteraction;
use Hypervel\Ai\Models\Conversation;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Contracts\View\View;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Route;
use Workbench\App\Agents\Assistant;
use Workbench\App\Models\User;

Route::get('/', function (): View {
    return view('welcome');
});

Route::get('/chat', function (): View {
    return view('workbench::chat');
});

Route::post('/chat/message', function (Request $request): JsonResponse {
    $data = $request->validate([
        'message' => ['required', 'string'],
        'conversation_id' => ['nullable', 'string'],
    ]);

    $user = User::firstOrCreate(
        ['email' => 'demo@workbench.test'],
        ['name' => 'Demo User', 'password' => bcrypt(str()->random(32))],
    );

    $agent = new Assistant;

    $agent = ($data['conversation_id'] ?? null)
        ? $agent->continue($data['conversation_id'], as: $user)
        : $agent->forUser($user);

    $response = $agent->prompt($data['message']);

    return response()->json([
        'text' => $response->text,
        'conversation_id' => $response->conversationId,
    ]);
});

Route::post('/ag-ui', function (Request $request): StreamableAgentResponse {
    $chat = AgentUserInteraction::chat($request);

    $user = User::firstOrCreate(
        ['email' => 'demo@workbench.test'],
        ['name' => 'Demo User', 'password' => bcrypt(str()->random(32))],
    );

    // AG-UI clients mint the thread id, so adopt it as the conversation id...
    Conversation::firstOrCreate(
        ['id' => $chat->threadId()],
        ['participant_type' => $user->getMorphClass(), 'participant_id' => $user->getKey(), 'title' => 'AG-UI chat'],
    );

    return (new Assistant)
        ->continue($chat->threadId(), as: $user)
        ->stream($chat)
        ->usingProtocol($chat->protocol());
});

Route::get('/ag-ui/{conversation}', function (Conversation $conversation): JsonResponse {
    $messages = $conversation->messages()->oldest('id')->get();

    return response()->json([
        'threadId' => $conversation->id,
        ...AgentUserInteraction::toClientState($messages),
    ]);
});
