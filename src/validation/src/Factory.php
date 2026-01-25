<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Closure;
use Hypervel\Support\Str;
use Hypervel\Contracts\Translation\Translator;
use Hypervel\Validation\Contracts\Factory as FactoryContract;
use Psr\Container\ContainerInterface;

class Factory implements FactoryContract
{
    /**
     * The Presence Verifier implementation.
     */
    protected ?PresenceVerifierInterface $verifier = null;

    /**
     * All of the custom validator extensions.
     *
     * @var array<string, Closure|string>
     */
    protected array $extensions = [];

    /**
     * All of the custom implicit validator extensions.
     *
     * @var array<string, Closure|string>
     */
    protected array $implicitExtensions = [];

    /**
     * All of the custom dependent validator extensions.
     *
     * @var array<string, Closure|string>
     */
    protected array $dependentExtensions = [];

    /**
     * All of the custom validator message replacers.
     *
     * @var array<string, Closure|string>
     */
    protected array $replacers = [];

    /**
     * All of the fallback messages for custom rules.
     *
     * @var array<string, string>
     */
    protected array $fallbackMessages = [];

    /**
     * Indicates that unvalidated array keys should be excluded, even if the parent array was validated.
     */
    protected bool $excludeUnvalidatedArrayKeys = true;

    /**
     * The Validator resolver instance.
     */
    protected ?Closure $resolver = null;

    /**
     * Create a new Validator factory instance.
     *
     * @param Translator $translator the Translator implementation
     * @param null|ContainerInterface $container the IoC container instance
     */
    public function __construct(
        protected Translator $translator,
        protected ?ContainerInterface $container = null
    ) {
    }

    /**
     * Create a new Validator instance.
     */
    public function make(array $data, array $rules, array $messages = [], array $attributes = []): Validator
    {
        $validator = $this->resolve(
            $data,
            $rules,
            $messages,
            $attributes
        );

        // The presence verifier is responsible for checking the unique and exists data
        // for the validator. It is behind an interface so that multiple versions of
        // it may be written besides database. We'll inject it into the validator.
        if (! is_null($this->verifier)) {
            $validator->setPresenceVerifier($this->verifier);
        }

        // Next we'll set the IoC container instance of the validator, which is used to
        // resolve out class based validator extensions. If it is not set then these
        // types of extensions will not be possible on these validation instances.
        if (! is_null($this->container)) {
            $validator->setContainer($this->container);
        }

        $validator->excludeUnvalidatedArrayKeys = $this->excludeUnvalidatedArrayKeys;

        $this->addExtensions($validator);

        return $validator;
    }

    /**
     * Validate the given data against the provided rules.
     *
     * @throws ValidationException
     */
    public function validate(array $data, array $rules, array $messages = [], array $attributes = []): array
    {
        return $this->make($data, $rules, $messages, $attributes)->validate();
    }

    /**
     * Resolve a new Validator instance.
     */
    protected function resolve(array $data, array $rules, array $messages, array $attributes): Validator
    {
        if (is_null($this->resolver)) {
            return new Validator($this->translator, $data, $rules, $messages, $attributes);
        }

        return call_user_func($this->resolver, $this->translator, $data, $rules, $messages, $attributes);
    }

    /**
     * Add the extensions to a validator instance.
     */
    protected function addExtensions(Validator $validator): void
    {
        $validator->addExtensions($this->extensions);

        // Next, we will add the implicit extensions, which are similar to the required
        // and accepted rule in that they're run even if the attributes aren't in an
        // array of data which is given to a validator instance via instantiation.
        $validator->addImplicitExtensions($this->implicitExtensions);

        $validator->addDependentExtensions($this->dependentExtensions);

        $validator->addReplacers($this->replacers);

        $validator->setFallbackMessages($this->fallbackMessages);
    }

    /**
     * Register a custom validator extension.
     */
    public function extend(string $rule, Closure|string $extension, ?string $message = null): void
    {
        $this->extensions[$rule] = $extension;

        if ($message) {
            $this->fallbackMessages[Str::snake($rule)] = $message;
        }
    }

    /**
     * Register a custom implicit validator extension.
     */
    public function extendImplicit(string $rule, Closure|string $extension, ?string $message = null): void
    {
        $this->implicitExtensions[$rule] = $extension;

        if ($message) {
            $this->fallbackMessages[Str::snake($rule)] = $message;
        }
    }

    /**
     * Register a custom dependent validator extension.
     */
    public function extendDependent(string $rule, Closure|string $extension, ?string $message = null): void
    {
        $this->dependentExtensions[$rule] = $extension;

        if ($message) {
            $this->fallbackMessages[Str::snake($rule)] = $message;
        }
    }

    /**
     * Register a custom validator message replacer.
     */
    public function replacer(string $rule, Closure|string $replacer): void
    {
        $this->replacers[$rule] = $replacer;
    }

    /**
     * Indicate that unvalidated array keys should be included in validated data when the parent array is validated.
     */
    public function includeUnvalidatedArrayKeys(): void
    {
        $this->excludeUnvalidatedArrayKeys = false;
    }

    /**
     * Indicate that unvalidated array keys should be excluded from the validated data, even if the parent array was validated.
     */
    public function excludeUnvalidatedArrayKeys(): void
    {
        $this->excludeUnvalidatedArrayKeys = true;
    }

    /**
     * Set the Validator instance resolver.
     */
    public function resolver(Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    /**
     * Get the Translator implementation.
     */
    public function getTranslator(): Translator
    {
        return $this->translator;
    }

    /**
     * Get the Presence Verifier implementation.
     */
    public function getPresenceVerifier(): PresenceVerifierInterface
    {
        return $this->verifier;
    }

    /**
     * Set the Presence Verifier implementation.
     */
    public function setPresenceVerifier(PresenceVerifierInterface $presenceVerifier): void
    {
        $this->verifier = $presenceVerifier;
    }

    /**
     * Get the container instance used by the validation factory.
     */
    public function getContainer(): ?ContainerInterface
    {
        return $this->container;
    }

    /**
     * Set the container instance used by the validation factory.
     */
    public function setContainer(ContainerInterface $container): static
    {
        $this->container = $container;

        return $this;
    }
}
