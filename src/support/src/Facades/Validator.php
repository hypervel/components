<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

/**
 * @method static void excludeUnvalidatedArrayKeys()
 * @method static void extend(string $rule, \Closure|string $extension, string|null $message = null)
 * @method static void extendDependent(string $rule, \Closure|string $extension, string|null $message = null)
 * @method static void extendImplicit(string $rule, \Closure|string $extension, string|null $message = null)
 * @method static void fakeDnsLookups(bool $value = true)
 * @method static \Hypervel\Contracts\Container\Container|null getContainer()
 * @method static \Hypervel\Validation\PresenceVerifierInterface|null getPresenceVerifier()
 * @method static \Hypervel\Contracts\Translation\Translator getTranslator()
 * @method static void includeUnvalidatedArrayKeys()
 * @method static \Hypervel\Validation\Validator make(array $data, array $rules, array $messages = [], array $attributes = [])
 * @method static void replacer(string $rule, \Closure|string $replacer)
 * @method static void resolver(\Closure $resolver)
 * @method static \Hypervel\Validation\Factory setContainer(\Hypervel\Contracts\Container\Container $container)
 * @method static void setPresenceVerifier(\Hypervel\Validation\PresenceVerifierInterface $presenceVerifier)
 * @method static array validate(array $data, array $rules, array $messages = [], array $attributes = [])
 *
 * @see \Hypervel\Validation\Factory
 */
class Validator extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'validator';
    }
}
