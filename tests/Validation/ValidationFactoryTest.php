<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Translation\Translator as TranslatorInterface;
use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Factory;
use Hypervel\Validation\PresenceVerifierInterface;
use Hypervel\Validation\Validator;
use Mockery as m;
use ReflectionProperty;

class ValidationFactoryTest extends TestCase
{
    public function testMakeMethodCreatesValidValidator(): void
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $factory = new Factory($translator);
        $validator = $factory->make(['foo' => 'bar'], ['baz' => 'boom']);
        $this->assertEquals($translator, $validator->getTranslator());
        $this->assertEquals(['foo' => 'bar'], $validator->getData());
        $this->assertEquals(['baz' => ['boom']], $validator->getRules());

        $presence = new FakePresenceVerifier;
        $noop1 = function (): void {
        };
        $noop2 = function (): void {
        };
        $noop3 = function (): void {
        };
        $factory->extend('foo', $noop1);
        $factory->extendImplicit('implicit', $noop2);
        $factory->extendDependent('dependent', $noop3);
        $factory->replacer('replacer', $noop3);
        $factory->setPresenceVerifier($presence);
        $validator = $factory->make([], []);
        $this->assertEquals(['foo' => $noop1, 'implicit' => $noop2, 'dependent' => $noop3], $validator->extensions);
        $this->assertEquals(['replacer' => $noop3], $validator->replacers);
        $this->assertEquals($presence, $validator->getPresenceVerifier());

        $presence = new FakePresenceVerifier;
        $factory->extend('foo', $noop1, 'foo!');
        $factory->extendImplicit('implicit', $noop2, 'implicit!');
        $factory->extendImplicit('dependent', $noop3, 'dependent!');
        $factory->setPresenceVerifier($presence);
        $validator = $factory->make([], []);
        $this->assertEquals(['foo' => $noop1, 'implicit' => $noop2, 'dependent' => $noop3], $validator->extensions);
        $this->assertEquals(['foo' => 'foo!', 'implicit' => 'implicit!', 'dependent' => 'dependent!'], $validator->fallbackMessages);
        $this->assertEquals($presence, $validator->getPresenceVerifier());
    }

    public function testValidateCallsValidateOnTheValidator(): void
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $factory = new Factory($translator);

        $validated = $factory->validate(
            ['foo' => 'bar', 'baz' => 'boom'],
            ['foo' => 'required']
        );

        $this->assertEquals(['foo' => 'bar'], $validated);
    }

    public function testCustomResolverIsCalled(): void
    {
        unset($_SERVER['__validator.factory']);
        $translator = new Translator(new ArrayLoader, 'en');
        $factory = new Factory($translator);
        $factory->resolver(function (TranslatorInterface $translator, array $data, array $rules): Validator {
            $_SERVER['__validator.factory'] = true;

            return new Validator($translator, $data, $rules);
        });
        $validator = $factory->make(['foo' => 'bar'], ['baz' => 'boom']);

        $this->assertTrue($_SERVER['__validator.factory']);
        $this->assertEquals($translator, $validator->getTranslator());
        $this->assertEquals(['foo' => 'bar'], $validator->getData());
        $this->assertEquals(['baz' => ['boom']], $validator->getRules());
        unset($_SERVER['__validator.factory']);
    }

    public function testValidateMethodCanBeCalledPublicly(): void
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $factory = new Factory($translator);
        $factory->extend('foo', function (string $attribute, mixed $value, array $parameters, Validator $validator): bool {
            return $validator->validateArray($attribute, $value);
        });

        $validator = $factory->make(['bar' => ['baz']], ['bar' => 'foo']);
        $this->assertTrue($validator->passes());
    }

    public function testExcludeAndIncludeUnvalidatedArrayKeys(): void
    {
        $translator = new Translator(new ArrayLoader, 'en');

        $factory = new Factory($translator);
        // check the default behaviour.
        $validator1 = $factory->make(['key' => ['val']], ['key' => 'required']);
        $this->assertTrue($validator1->excludeUnvalidatedArrayKeys);

        $factory->excludeUnvalidatedArrayKeys();
        $validator2 = $factory->make(['key' => ['val']], ['key' => 'required']);
        $this->assertTrue($validator2->excludeUnvalidatedArrayKeys);

        $factory->includeUnvalidatedArrayKeys();
        $validator3 = $factory->make(['key' => ['val']], ['key' => 'required']);
        $this->assertFalse($validator3->excludeUnvalidatedArrayKeys);

        // checks it does not switch behaviour automatically.
        $validator4 = $factory->make(['key' => ['val']], ['key' => 'required']);
        $this->assertFalse($validator4->excludeUnvalidatedArrayKeys);

        // checks it can switch.
        $factory->excludeUnvalidatedArrayKeys();
        $validator5 = $factory->make(['key' => ['val']], ['key' => 'required']);
        $this->assertTrue($validator5->excludeUnvalidatedArrayKeys);

        // checks switching does not affect previously created validator objects.
        $this->assertTrue($validator1->excludeUnvalidatedArrayKeys);
        $this->assertTrue($validator2->excludeUnvalidatedArrayKeys);
        $this->assertFalse($validator3->excludeUnvalidatedArrayKeys);
        $this->assertFalse($validator4->excludeUnvalidatedArrayKeys);
    }

    public function testSetContainer(): void
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $container = m::mock(Container::class);
        $factory = new Factory($translator);

        $this->assertNull($factory->getContainer());

        $this->assertSame($container, $factory->setContainer($container)->getContainer());
    }

    public function testPresenceVerifierIsNullableWhenFactoryIsConstructedDirectly(): void
    {
        $factory = new Factory(m::mock(TranslatorInterface::class));

        $this->assertNull($factory->getPresenceVerifier());
    }

    public function testFakeDnsLookupsDelegatesToTheValidator(): void
    {
        $factory = new Factory(new Translator(new ArrayLoader, 'en'));
        $property = new ReflectionProperty(Validator::class, 'fakeDnsLookups');

        $factory->fakeDnsLookups();
        $this->assertTrue($property->getValue());

        $factory->fakeDnsLookups(false);
        $this->assertFalse($property->getValue());
    }
}

class FakePresenceVerifier implements PresenceVerifierInterface
{
    /**
     * Count the number of objects in a collection having the given value.
     */
    public function getCount(string $collection, string $column, mixed $value, int|string|null $excludeId = null, ?string $idColumn = null, array $extra = []): int
    {
        return 0;
    }

    /**
     * Count the number of objects in a collection with the given values.
     */
    public function getMultiCount(string $collection, string $column, array $values, array $extra = []): int
    {
        return 0;
    }
}
