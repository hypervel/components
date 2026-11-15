<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\FormRequestInjection;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Attributes\Give;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Container\Transient;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Contracts\Validation\Validator;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Support\Attributes\BootOnly;
use Hypervel\Validation\Factory;
use Hypervel\Validation\Validator as BaseValidator;

class SharedRequest extends FormRequest
{
    public function rules(Repository $config, Factory $factory): array
    {
        $config->set('validation.mode', $this->input('mode'));
        $factory->includeUnvalidatedArrayKeys();

        return ['name' => 'required'];
    }

    public function authorize(Repository $config): bool
    {
        $config->set('validation.authorized', true);

        return true;
    }

    public function validator(#[Give(Factory::class)] Factory $factory, Repository $config): Validator
    {
        $factory->includeUnvalidatedArrayKeys();
        $config->set('validation.mode', 'custom');

        return new CustomValidator($factory->getTranslator(), $this->all(), []);
    }

    public function after(CustomValidator $validator, Repository $config): array
    {
        $validator->configure();
        $config->set('validation.after', true);

        return [];
    }
}

class CustomValidator extends BaseValidator
{
    #[BootOnly(whenShared: true)]
    public function configure(): void
    {
    }
}

class LocalConfig extends ConfigRepository implements Transient
{
}

class DefaultConfig extends ConfigRepository
{
}

class SafeRequest extends FormRequest
{
    public function rules(DefaultConfig $defaults = new DefaultConfig): array
    {
        $defaults->set('local', true);

        return [];
    }

    public function authorize(#[Give(LocalConfig::class)] Repository $config): bool
    {
        $config->set('local', true);

        return true;
    }
}

class DispatcherBaseRequest extends FormRequest
{
    public function rules(Repository $config): array
    {
        $config->set('mode', true);

        return [];
    }
}

class OverriddenRequest extends DispatcherBaseRequest
{
    protected function validationRules(): array
    {
        return $this->rules(new ConfigRepository);
    }
}

class BoundRequest extends FormRequest
{
    public function rules(Repository $config): array
    {
        $config->set('local', true);

        return [];
    }
}

class BoundJob implements ShouldQueue
{
    use Dispatchable;

    public function handle(Repository $config): void
    {
        $config->set('local', true);
    }
}
