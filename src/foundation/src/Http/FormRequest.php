<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http;

use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Auth\Access\Response;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Validation\Factory as ValidationFactory;
use Hypervel\Contracts\Validation\ValidatesWhenResolved;
use Hypervel\Contracts\Validation\Validator;
use Hypervel\Foundation\Http\Attributes\ErrorBag;
use Hypervel\Foundation\Http\Attributes\RedirectTo;
use Hypervel\Foundation\Http\Attributes\RedirectToRoute;
use Hypervel\Foundation\Http\Attributes\StopOnFirstFailure;
use Hypervel\Foundation\Http\Traits\HasCasts;
use Hypervel\Http\Request;
use Hypervel\Routing\Redirector;
use Hypervel\Support\Arr;
use Hypervel\Support\ValidatedInput;
use Hypervel\Validation\ValidatesWhenResolvedTrait;
use ReflectionClass;

class FormRequest extends Request implements ValidatesWhenResolved
{
    use HasCasts;
    use ValidatesWhenResolvedTrait;

    /**
     * The cached attribute configuration for each form request class.
     *
     * @var array<class-string, array<string, mixed>>
     */
    protected static array $attributeConfiguration = [];

    /**
     * The container instance.
     */
    protected Container $container;

    /**
     * The redirector instance.
     */
    protected Redirector $redirector;

    /**
     * The URI to redirect to if validation fails.
     */
    protected ?string $redirect = null;

    /**
     * The route to redirect to if validation fails.
     */
    protected ?string $redirectRoute = null;

    /**
     * The controller action to redirect to if validation fails.
     */
    protected ?string $redirectAction = null;

    /**
     * The key to be used for the view error bag.
     */
    protected string $errorBag = 'default';

    /**
     * Indicates whether validation should stop after the first rule failure.
     */
    protected bool $stopOnFirstFailure = false;

    /**
     * The validator instance.
     */
    protected ?Validator $validator = null;

    /**
     * The scenes defined by developer.
     */
    protected array $scenes = [];

    /**
     * The current validation scene.
     */
    protected ?string $currentScene = null;

    /**
     * Get the validator instance for the request.
     */
    protected function getValidatorInstance(): Validator
    {
        if ($this->validator) {
            return $this->validator;
        }

        $this->configureFromAttributes();

        $factory = $this->container->make(ValidationFactory::class);

        if (method_exists($this, 'validator')) {
            $validator = $this->container->call($this->validator(...), compact('factory'));
        } else {
            $validator = $this->createDefaultValidator($factory);
        }

        if (method_exists($this, 'withValidator')) {
            $this->withValidator($validator);
        }

        if (method_exists($this, 'after')) {
            $validator->after($this->container->call(
                $this->after(...),
                ['validator' => $validator]
            ));
        }

        $this->setValidator($validator);

        return $this->validator;
    }

    /**
     * Configure the form request from class attributes.
     */
    protected function configureFromAttributes(): void
    {
        $class = static::class;

        if (! isset(static::$attributeConfiguration[$class])) {
            $reflection = new ReflectionClass($this);

            $config = [];

            if (count($reflection->getAttributes(StopOnFirstFailure::class)) > 0) {
                $config['stopOnFirstFailure'] = true;
            }

            $errorBag = $reflection->getAttributes(ErrorBag::class);

            if (count($errorBag) > 0) {
                $config['errorBag'] = $errorBag[0]->newInstance()->name;
            }

            $redirectTo = $reflection->getAttributes(RedirectTo::class);

            if (count($redirectTo) > 0) {
                $config['redirect'] = $redirectTo[0]->newInstance()->url;
            }

            $redirectToRoute = $reflection->getAttributes(RedirectToRoute::class);

            if (count($redirectToRoute) > 0) {
                $config['redirectRoute'] = $redirectToRoute[0]->newInstance()->route;
            }

            static::$attributeConfiguration[$class] = $config;
        }

        $config = static::$attributeConfiguration[$class];

        if (isset($config['stopOnFirstFailure'])) {
            $this->stopOnFirstFailure = true;
        }

        if (isset($config['errorBag'])) {
            $this->errorBag = $config['errorBag'];
        }

        if (isset($config['redirect'])) {
            $this->redirect = $config['redirect'];
        }

        if (isset($config['redirectRoute'])) {
            $this->redirectRoute = $config['redirectRoute'];
        }
    }

    /**
     * Create the default validator instance.
     */
    protected function createDefaultValidator(ValidationFactory $factory): Validator
    {
        $rules = $this->validationRules();

        $validator = $factory->make(
            $this->validationData(),
            $rules,
            $this->messages(),
            $this->attributes(),
        )->stopOnFirstFailure($this->stopOnFirstFailure);

        if ($this->isPrecognitive()) {
            $validator->setRules(
                $this->filterPrecognitiveRules($validator->getRulesWithoutPlaceholders())
            );
        }

        return $validator;
    }

    /**
     * Get data to be validated from the request.
     */
    public function validationData(): array
    {
        return $this->all();
    }

    /**
     * Get the validation rules for this form request.
     *
     * Applies scene filtering when a scene is active.
     */
    protected function validationRules(): array
    {
        $rules = method_exists($this, 'rules')
            ? $this->container->call([$this, 'rules'])
            : [];

        $scene = $this->getScene();

        if ($scene && isset($this->scenes[$scene]) && is_array($this->scenes[$scene])) {
            return Arr::only($rules, $this->scenes[$scene]);
        }

        return $rules;
    }

    /**
     * Handle a failed validation attempt.
     *
     * @throws \Hypervel\Validation\ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        $exception = $validator->getException();

        throw (new $exception($validator))
            ->errorBag($this->errorBag)
            ->redirectTo($this->getRedirectUrl());
    }

    /**
     * Get the URL to redirect to on a validation error.
     */
    protected function getRedirectUrl(): string
    {
        $url = $this->redirector->getUrlGenerator();

        if ($this->redirect) {
            return $url->to($this->redirect);
        }
        if ($this->redirectRoute) {
            return $url->route($this->redirectRoute);
        }
        if ($this->redirectAction) {
            return $url->action($this->redirectAction);
        }

        return $url->previous();
    }

    /**
     * Determine if the request passes the authorization check.
     *
     * @throws AuthorizationException
     */
    protected function passesAuthorization(): bool|Response
    {
        if (method_exists($this, 'authorize')) {
            $result = $this->container->call([$this, 'authorize']);

            return $result instanceof Response ? $result->authorize() : $result;
        }

        return true;
    }

    /**
     * Handle a failed authorization attempt.
     *
     * @throws AuthorizationException
     */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException;
    }

    /**
     * Get a validated input container for the validated input.
     */
    public function safe(?array $keys = null): array|ValidatedInput
    {
        return is_array($keys)
            ? $this->validator->safe()->only($keys)
            : $this->validator->safe();
    }

    /**
     * Get the validated data from the request.
     */
    public function validated(array|int|string|null $key = null, mixed $default = null): mixed
    {
        return data_get($this->validator->validated(), $key, $default);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [];
    }

    /**
     * Set the Validator instance.
     */
    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    /**
     * Set the Redirector instance.
     */
    public function setRedirector(Redirector $redirector): static
    {
        $this->redirector = $redirector;

        return $this;
    }

    /**
     * Set the container implementation.
     */
    public function setContainer(Container $container): static
    {
        $this->container = $container;

        return $this;
    }

    /**
     * Flush the cached attribute configuration.
     */
    public static function flushState(): void
    {
        static::$attributeConfiguration = [];
    }

    /**
     * Set the active validation scene.
     */
    public function scene(string $scene): static
    {
        $this->currentScene = $scene;

        return $this;
    }

    /**
     * Get the active validation scene.
     */
    public function getScene(): ?string
    {
        return $this->currentScene;
    }
}
