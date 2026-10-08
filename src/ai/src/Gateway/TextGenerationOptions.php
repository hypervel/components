<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway;

use Hypervel\Ai\Attributes\CacheInstructions;
use Hypervel\Ai\Attributes\CacheToolDefinitions;
use Hypervel\Ai\Attributes\MaxSteps;
use Hypervel\Ai\Attributes\MaxTokens;
use Hypervel\Ai\Attributes\Temperature;
use Hypervel\Ai\Attributes\TopP;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\ToolChoice;
use Hypervel\Support\ClassMetadataCache;
use ReflectionClass;

class TextGenerationOptions
{
    /**
     * Create text generation options.
     */
    public function __construct(
        public readonly ?int $maxSteps = null,
        public readonly ?int $maxTokens = null,
        public readonly ?float $temperature = null,
        public readonly ?Agent $agent = null,
        public readonly ?float $topP = null,
        public readonly ?ToolChoice $toolChoice = null,
        public readonly ?CacheInstructions $cacheInstructions = null,
        public readonly ?CacheToolDefinitions $cacheToolDefinitions = null,
        public readonly ?array $providerOptions = null,
    ) {
    }

    /**
     * Get the provider-specific options for the given provider.
     *
     * @return null|array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): ?array
    {
        $agentOptions = $this->agent instanceof HasProviderOptions
            ? $this->agent->providerOptions(
                $provider instanceof Lab ? $provider : (Lab::tryFrom($provider) ?? $provider)
            )
            : null;

        if ($this->providerOptions === null) {
            return $agentOptions;
        }

        return [...($agentOptions ?? []), ...$this->providerOptions];
    }

    /**
     * Create a copy using a different tool choice.
     */
    public function withToolChoice(?ToolChoice $toolChoice): self
    {
        return $this->with(['toolChoice' => $toolChoice]);
    }

    /**
     * Create a copy using a different maximum token count.
     */
    public function withMaxTokens(?int $maxTokens): self
    {
        return $this->with(['maxTokens' => $maxTokens]);
    }

    /**
     * Create a copy using different provider options.
     *
     * @param null|array<string, mixed> $providerOptions
     */
    public function withProviderOptions(?array $providerOptions): self
    {
        return $this->with(['providerOptions' => $providerOptions]);
    }

    /**
     * Create a copy with the given property overrides.
     *
     * @param array<string, mixed> $overrides
     */
    protected function with(array $overrides): self
    {
        return new self(...[...get_object_vars($this), ...$overrides]);
    }

    /**
     * Resolve the options for the given step, releasing a forced tool choice after the first step so the model can answer.
     */
    public function forStep(int $stepNumber): self
    {
        if ($stepNumber === 0 || ! $this->toolChoice instanceof ToolChoice) {
            return $this;
        }

        if (! in_array($this->toolChoice->mode, [ToolChoice::required, ToolChoice::tool], true)) {
            return $this;
        }

        return $this->withToolChoice(null);
    }

    /**
     * Create a new TextGenerationOptions instance for the given agent.
     */
    public static function forAgent(Agent $agent): self
    {
        $reflection = ClassMetadataCache::reflectClass($agent);

        return new self(
            maxSteps: self::resolve($agent, $reflection, 'maxSteps', MaxSteps::class),
            maxTokens: self::resolve($agent, $reflection, 'maxTokens', MaxTokens::class),
            temperature: self::resolve($agent, $reflection, 'temperature', Temperature::class),
            agent: $agent,
            topP: self::resolve($agent, $reflection, 'topP', TopP::class),
            toolChoice: self::resolveToolChoice($agent, $reflection),
            cacheInstructions: self::resolveAttribute($reflection, CacheInstructions::class),
            cacheToolDefinitions: self::resolveAttribute($reflection, CacheToolDefinitions::class),
        );
    }

    /**
     * Resolve the tool choice from the agent's method, falling back to the attribute.
     */
    private static function resolveToolChoice(Agent $agent, ReflectionClass $reflection): ?ToolChoice
    {
        $value = self::resolveMethod($agent, 'toolChoice');

        if ($value !== null) {
            return ToolChoice::from($value);
        }

        $attributes = $reflection->getAttributes(ToolChoice::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * Resolve an option from the agent's method, falling back to the attribute.
     *
     * @param class-string $attribute
     */
    private static function resolve(Agent $agent, ReflectionClass $reflection, string $method, string $attribute): int|float|null
    {
        $value = self::resolveMethod($agent, $method);

        if ($value !== null) {
            return $value;
        }

        $attributes = $reflection->getAttributes($attribute);

        return $attributes === [] ? null : $attributes[0]->newInstance()->value;
    }

    /**
     * Resolve an option method that is public and needs no arguments.
     */
    private static function resolveMethod(Agent $agent, string $method): mixed
    {
        if (! method_exists($agent, $method)) {
            return null;
        }

        $reflection = ClassMetadataCache::reflectMethod($agent, $method);

        return $reflection->isPublic() && $reflection->getNumberOfRequiredParameters() === 0
            ? $agent->{$method}()
            : null;
    }

    /**
     * Resolve an attribute from the agent class.
     *
     * @template T of object
     *
     * @param ReflectionClass<object> $reflection
     * @param class-string<T> $attribute
     * @return null|T
     */
    private static function resolveAttribute(ReflectionClass $reflection, string $attribute): ?object
    {
        $attributes = $reflection->getAttributes($attribute);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }
}
