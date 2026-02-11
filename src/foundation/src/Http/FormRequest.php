<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http;

use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Context\Context;
use Hypervel\Context\ResponseContext;
use Hypervel\Contracts\Validation\Factory as ValidationFactory;
use Hypervel\Contracts\Validation\ValidatesWhenResolved;
use Hypervel\Contracts\Validation\Validator;
use Hypervel\Foundation\Http\Traits\HasCasts;
use Hypervel\Http\Request;
use Hypervel\Support\Arr;
use Hypervel\Validation\ValidatesWhenResolvedTrait;
use Hypervel\Validation\ValidationException;
use Hypervel\Contracts\Container\Container;
use Psr\Http\Message\ResponseInterface;

class FormRequest extends Request implements ValidatesWhenResolved
{
    use HasCasts;
    use ValidatesWhenResolvedTrait;

    /**
     * The key to be used for the view error bag.
     */
    protected string $errorBag = 'default';

    /**
     * The scenes defined by developer.
     */
    protected array $scenes = [];

    /**
     * The input keys that should not be flashed on redirect.
     */
    protected array $dontFlash = ['password', 'password_confirmation'];

    public function __construct(
        protected Container $container
    ) {
    }

    public function scene(string $scene): static
    {
        Context::set($this->getContextValidatorKey('scene'), $scene);

        return $this;
    }

    public function getScene(): ?string
    {
        return Context::get($this->getContextValidatorKey('scene'));
    }

    /**
     * Get the proper failed validation response for the request.
     */
    public function response(): ResponseInterface
    {
        return ResponseContext::get()->withStatus(422);
    }

    /**
     * Get the validated data from the request.
     */
    public function validated(): array
    {
        return $this->getValidatorInstance()->validated();
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [];
    }

    /**
     * Set the container implementation.
     */
    public function setContainer(Container $container): static
    {
        $this->container = $container;

        return $this;
    }

    public function rules(): array
    {
        return [];
    }

    /**
     * Get the validator instance for the request.
     */
    protected function getValidatorInstance(): Validator
    {
        return Context::getOrSet($this->getContextValidatorKey(Validator::class), function () {
            $factory = $this->container->get(ValidationFactory::class);

            if (method_exists($this, 'validator')) {
                $validator = call_user_func_array([$this, 'validator'], compact('factory'));
            } else {
                $validator = $this->createDefaultValidator($factory);
            }

            if (method_exists($this, 'withValidator')) {
                $this->withValidator($validator);
            }

            return $validator;
        });
    }

    /**
     * Create the default validator instance.
     */
    protected function createDefaultValidator(ValidationFactory $factory): Validator
    {
        return $factory->make(
            $this->all(),
            $this->getRules(),
            $this->messages(),
            $this->attributes()
        );
    }

    /**
     * Handle a failed validation attempt.
     *
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator, $this->response());
    }

    /**
     * Format the errors from the given Validator instance.
     */
    protected function formatErrors(Validator $validator): array
    {
        return $validator->getMessageBag()->getMessages();
    }

    /**
     * Determine if the request passes the authorization check.
     */
    protected function passesAuthorization(): bool
    {
        if (method_exists($this, 'authorize')) {
            return call_user_func_array([$this, 'authorize'], []);
        }

        return false;
    }

    /**
     * Handle a failed authorization attempt.
     */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException();
    }

    /**
     * Get context validator key.
     */
    protected function getContextValidatorKey(string $key): string
    {
        return sprintf('%s:%s', spl_object_hash($this), $key);
    }

    /**
     * Get scene rules.
     */
    protected function getRules(): array
    {
        $rules = $this->rules();
        $scene = $this->getScene();
        if ($scene && isset($this->scenes[$scene]) && is_array($this->scenes[$scene])) {
            return Arr::only($rules, $this->scenes[$scene]);
        }

        return $rules;
    }
}
