<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai\Fixtures;

class Providers
{
    /**
     * Provide text-to-speech services.
     */
    public static function textToSpeech(): array
    {
        return [
            'openai' => ['openai', 'OPENAI_API_KEY'],
            'gemini' => ['gemini', 'GEMINI_API_KEY'],
            'openrouter' => ['openrouter', 'OPENROUTER_API_KEY'],
        ];
    }

    /**
     * Provide embedding services and dimensions.
     */
    public static function embeddings(): array
    {
        return [
            'openai' => ['openai', 'OPENAI_API_KEY', 1536],
            'voyageai' => ['voyageai', 'VOYAGEAI_API_KEY', 1024],
        ];
    }

    /**
     * Provide transcription services.
     */
    public static function transcription(): array
    {
        return [
            'openai' => ['openai', 'OPENAI_API_KEY'],
            'gemini' => ['gemini', 'GEMINI_API_KEY'],
            'openrouter' => ['openrouter', 'OPENROUTER_API_KEY'],
        ];
    }

    /**
     * Provide diarization services.
     */
    public static function diarization(): array
    {
        return [
            'openai' => ['openai', 'OPENAI_API_KEY'],
            'gemini' => ['gemini', 'GEMINI_API_KEY'],
        ];
    }

    /**
     * Provide image generation services and models.
     */
    public static function images(): array
    {
        return [
            'openai-gpt-image-2' => ['openai', 'OPENAI_API_KEY', 'gpt-image-2'],
            'xai' => ['xai', 'XAI_API_KEY', 'grok-imagine-image'],
            'gemini-2.5-flash-image' => ['gemini', 'GEMINI_API_KEY', 'gemini-2.5-flash-image'],
            'openrouter-gemini' => ['openrouter', 'OPENROUTER_API_KEY', 'google/gemini-3.1-flash-image'],
            'openrouter-gpt-image' => ['openrouter', 'OPENROUTER_API_KEY', 'openai/gpt-image-1-mini'],
            'openrouter-flux' => ['openrouter', 'OPENROUTER_API_KEY', 'black-forest-labs/flux-3-image'],
            'openrouter-seedream' => ['openrouter', 'OPENROUTER_API_KEY', 'bytedance-seed/seedream-4.5'],
            'openrouter-grok' => ['openrouter', 'OPENROUTER_API_KEY', 'x-ai/grok-imagine-image-2.0'],
        ];
    }

    /**
     * Provide file storage services.
     */
    public static function files(): array
    {
        return [
            'anthropic' => ['anthropic', 'ANTHROPIC_API_KEY'],
            'openai' => ['openai', 'OPENAI_API_KEY'],
            'gemini' => ['gemini', 'GEMINI_API_KEY'],
            'azure' => ['azure', 'AZURE_OPENAI_API_KEY'],
            'openrouter' => ['openrouter', 'OPENROUTER_API_KEY'],
        ];
    }

    /**
     * Provide vector store services.
     */
    public static function stores(): array
    {
        return [
            'openai' => ['openai', 'OPENAI_API_KEY'],
            'gemini' => ['gemini', 'GEMINI_API_KEY'],
            'azure' => ['azure', 'AZURE_OPENAI_API_KEY'],
        ];
    }

    /**
     * Provide file search services.
     */
    public static function fileSearch(): array
    {
        return [
            'openai' => ['openai', 'OPENAI_API_KEY'],
            'azure' => ['azure', 'AZURE_OPENAI_API_KEY'],
            'gemini' => ['gemini', 'GEMINI_API_KEY'],
        ];
    }

    /**
     * Provide reranking services.
     */
    public static function reranking(): array
    {
        return [
            'cohere' => ['cohere', 'COHERE_API_KEY'],
            'openrouter' => ['openrouter', 'OPENROUTER_API_KEY'],
            'voyageai' => ['voyageai', 'VOYAGEAI_API_KEY'],
        ];
    }

    /**
     * Provide classification services.
     */
    public static function classification(): array
    {
        return [
            'openai' => ['openai', 'OPENAI_API_KEY'],
            'openrouter' => ['openrouter', 'OPENROUTER_API_KEY'],
            'typesafe' => ['typesafe', 'TYPESAFE_API_KEY'],
        ];
    }

    /**
     * Provide image classification services.
     */
    public static function imageClassification(): array
    {
        return [
            'openai' => ['openai', 'OPENAI_API_KEY'],
        ];
    }

    /**
     * Provide agent services and models.
     */
    public static function agents(): array
    {
        return [
            'anthropic' => ['anthropic', 'ANTHROPIC_API_KEY', 'claude-haiku-4-5-20251001'],
            'azure' => ['azure', 'AZURE_OPENAI_API_KEY', 'gpt-5.4-mini'],
            'cohere' => ['cohere', 'COHERE_API_KEY', 'command-a-03-2025'],
            'deepseek' => ['deepseek', 'DEEPSEEK_API_KEY', 'deepseek-v4-pro'],
            'gemini' => ['gemini', 'GEMINI_API_KEY', 'gemini-3.5-flash-lite'],
            'groq' => ['groq', 'GROQ_API_KEY', 'openai/gpt-oss-20b'],
            'mistral' => ['mistral', 'MISTRAL_API_KEY', 'mistral-small-latest'],
            'ollama' => ['ollama', 'OLLAMA_API_KEY', 'gpt-oss:20b'],
            'openai' => ['openai', 'OPENAI_API_KEY', 'gpt-6-luna'],
            'openrouter' => ['openrouter', 'OPENROUTER_API_KEY', 'anthropic/claude-haiku-4.5'],
            'xai' => ['xai', 'XAI_API_KEY', 'grok-4.20-non-reasoning'],
        ];
    }

    /**
     * Provide tool search services and models.
     */
    public static function toolSearch(): array
    {
        return [
            'anthropic' => ['anthropic', 'ANTHROPIC_API_KEY', 'claude-haiku-4-5-20251001'],
            'openai' => ['openai', 'OPENAI_API_KEY', 'gpt-6-sol'],
            'azure' => ['azure', 'AZURE_OPENAI_API_KEY', 'gpt-5.4-mini'],
        ];
    }

    /**
     * Provide document input services and models.
     */
    public static function agentDocuments(): array
    {
        return [
            'anthropic' => ['anthropic', 'ANTHROPIC_API_KEY', 'claude-haiku-4-5-20251001'],
            'openai' => ['openai', 'OPENAI_API_KEY', 'gpt-6-luna'],
            'gemini' => ['gemini', 'GEMINI_API_KEY', 'gemini-3.1-flash-lite'],
            'mistral' => ['mistral', 'MISTRAL_API_KEY', 'mistral-small-latest'],
        ];
    }

    /**
     * Provide image input services and models.
     */
    public static function agentImages(): array
    {
        return [
            'openai' => ['openai', 'OPENAI_API_KEY', 'gpt-6-luna'],
            'gemini' => ['gemini', 'GEMINI_API_KEY', 'gemini-3.1-flash-lite'],
            'xai' => ['xai', 'XAI_API_KEY', 'grok-4.20-non-reasoning'],
            'cohere' => ['cohere', 'COHERE_API_KEY', 'command-a-vision-07-2025'],
        ];
    }

    /**
     * Provide reasoning and non-reasoning tool replay models.
     */
    public static function toolReplay(): array
    {
        return [
            // Reasoning model
            'openai-gpt-6-luna' => ['openai', 'OPENAI_API_KEY', 'gpt-6-luna', true],
            // Non-reasoning model (backward compatibility)
            'openai-gpt-4-1' => ['openai', 'OPENAI_API_KEY', 'gpt-4.1', false],
        ];
    }

    /**
     * Provide code execution services and models.
     */
    public static function codeExecution(): array
    {
        return [
            'anthropic' => ['anthropic', 'ANTHROPIC_API_KEY', 'claude-haiku-4-5-20251001'],
            'openai' => ['openai', 'OPENAI_API_KEY', 'gpt-6-sol'],
            'gemini' => ['gemini', 'GEMINI_API_KEY', 'gemini-3.1-flash-lite'],
            'xai' => ['xai', 'XAI_API_KEY', 'grok-4.6'],
            'groq' => ['groq', 'GROQ_API_KEY', 'openai/gpt-oss-120b'],
        ];
    }

    /**
     * Provide reasoning models and their options.
     */
    public static function reasoning(): array
    {
        return [
            'anthropic' => ['anthropic', 'ANTHROPIC_API_KEY', 'claude-haiku-4-5-20251001', ['thinking' => ['type' => 'enabled', 'budget_tokens' => 2000], 'max_tokens' => 4000]],
            'openai' => ['openai', 'OPENAI_API_KEY', 'gpt-6-luna', ['reasoning' => ['effort' => 'high', 'summary' => 'auto']]],
            'gemini' => ['gemini', 'GEMINI_API_KEY', 'gemini-3.5-flash', ['thinking_level' => 'high', 'thinking_summaries' => 'auto']],
            'xai' => ['xai', 'XAI_API_KEY', 'grok-4.6', ['reasoning' => ['summary' => 'auto']]],
            'cohere' => ['cohere', 'COHERE_API_KEY', 'command-a-reasoning-08-2025', ['thinking' => ['type' => 'enabled']]],
            'deepseek' => ['deepseek', 'DEEPSEEK_API_KEY', 'deepseek-reasoner', []],
            'groq' => ['groq', 'GROQ_API_KEY', 'openai/gpt-oss-20b', []],
            'mistral' => ['mistral', 'MISTRAL_API_KEY', 'magistral-medium-latest', []],
            'ollama' => ['ollama', 'OLLAMA_API_KEY', 'gpt-oss:20b', ['think' => true]],
            'openrouter' => ['openrouter', 'OPENROUTER_API_KEY', 'anthropic/claude-haiku-4.5', ['reasoning' => ['max_tokens' => 2000]]],
        ];
    }
}
