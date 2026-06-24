<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\FoundationFormRequestTest;

use Closure;
use Exception;
use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Auth\Access\Response;
use Hypervel\Container\Container;
use Hypervel\Contracts\Translation\Translator;
use Hypervel\Contracts\Validation\Factory as ValidationFactoryContract;
use Hypervel\Contracts\Validation\Validator;
use Hypervel\Foundation\Http\Attributes\ErrorBag;
use Hypervel\Foundation\Http\Attributes\FailOnUnknownFields;
use Hypervel\Foundation\Http\Attributes\StopOnFirstFailure;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Http\RedirectResponse;
use Hypervel\Routing\Redirector;
use Hypervel\Routing\UrlGenerator;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\Factory as ValidationFactory;
use Hypervel\Validation\ValidationException;
use Mockery as m;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\InputBag;

class FoundationFormRequestTest extends TestCase
{
    protected array $mocks = [];

    public function testValidatedMethodReturnsTheValidatedData(): void
    {
        $request = $this->createRequest(['name' => 'specified', 'with' => 'extras']);

        $request->validateResolved();

        $this->assertEquals(['name' => 'specified'], $request->validated());
    }

    public function testValidatedMethodReturnsTheValidatedDataNestedRules(): void
    {
        $payload = ['nested' => ['foo' => 'bar', 'baz' => ''], 'array' => [1, 2]];

        $request = $this->createRequest($payload, FoundationTestFormRequestNestedStub::class);

        $request->validateResolved();

        $this->assertEquals(['nested' => ['foo' => 'bar'], 'array' => [1, 2]], $request->validated());
    }

    public function testValidatedMethodReturnsTheValidatedDataNestedChildRules(): void
    {
        $payload = ['nested' => ['foo' => 'bar', 'with' => 'extras']];

        $request = $this->createRequest($payload, FoundationTestFormRequestNestedChildStub::class);

        $request->validateResolved();

        $this->assertEquals(['nested' => ['foo' => 'bar']], $request->validated());
    }

    public function testValidatedMethodReturnsTheValidatedDataNestedArrayRules(): void
    {
        $payload = ['nested' => [['bar' => 'baz', 'with' => 'extras'], ['bar' => 'baz2', 'with' => 'extras']]];

        $request = $this->createRequest($payload, FoundationTestFormRequestNestedArrayStub::class);

        $request->validateResolved();

        $this->assertEquals(['nested' => [['bar' => 'baz'], ['bar' => 'baz2']]], $request->validated());
    }

    public function testValidatedMethodNotValidateTwice(): void
    {
        $payload = ['name' => 'specified', 'with' => 'extras'];

        $request = $this->createRequest($payload, FoundationTestFormRequestTwiceStub::class);

        $request->validateResolved();
        $request->validated();

        $this->assertEquals(1, FoundationTestFormRequestTwiceStub::$count);
    }

    public function testValidateThrowsWhenValidationFails(): void
    {
        $this->expectException(ValidationException::class);

        $request = $this->createRequest(['no' => 'name']);

        $this->mocks['redirect']->shouldReceive('withInput->withErrors');

        $request->validateResolved();
    }

    public function testValidateThrowsWhenValidationFailsWithConfiguredErrorBagAttribute(): void
    {
        $request = $this->createRequest(['no' => 'name'], FoundationTestFormRequestWithErrorBagAttribute::class);

        $exception = $this->catchException(ValidationException::class, function () use ($request) {
            $request->validateResolved();
        });

        $this->assertSame('login', $exception->errorBag);
    }

    public function testValidateMethodThrowsWhenAuthorizationFails(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('This action is unauthorized.');

        $this->createRequest([], FoundationTestFormRequestForbiddenStub::class)->validateResolved();
    }

    public function testValidateThrowsExceptionFromAuthorizationResponse(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('foo');

        $this->createRequest([], FoundationTestFormRequestForbiddenWithResponseStub::class)->validateResolved();
    }

    public function testValidateDoesntThrowExceptionFromResponseAllowed(): void
    {
        $this->createRequest([], FoundationTestFormRequestPassesWithResponseStub::class)->validateResolved();
    }

    public function testPrepareForValidationRunsBeforeValidation(): void
    {
        $this->createRequest([], FoundationTestFormRequestHooks::class)->validateResolved();
    }

    public function testAfterValidationRunsAfterValidation(): void
    {
        $request = $this->createRequest([], FoundationTestFormRequestHooks::class);

        $request->validateResolved();

        $this->assertEquals(['name' => 'Adam'], $request->all());
    }

    public function testValidatedMethodReturnsOnlyRequestedValidatedData(): void
    {
        $request = $this->createRequest(['name' => 'specified', 'with' => 'extras']);

        $request->validateResolved();

        $this->assertSame('specified', $request->validated('name'));
    }

    public function testValidatedMethodReturnsOnlyRequestedNestedValidatedData(): void
    {
        $payload = ['nested' => ['foo' => 'bar', 'baz' => ''], 'array' => [1, 2]];

        $request = $this->createRequest($payload, FoundationTestFormRequestNestedStub::class);

        $request->validateResolved();

        $this->assertSame('bar', $request->validated('nested.foo'));
    }

    public function testAfterMethod(): void
    {
        $request = new class extends FormRequest {
            public string $value = 'value-from-request';

            public function rules()
            {
                return [];
            }

            protected function failedValidation(Validator $validator): void
            {
                throw new class($validator) extends Exception {
                    public function __construct(public Validator $validator)
                    {
                    }
                };
            }

            public function after(InjectedDependency $dep)
            {
                return [
                    new AfterValidationRule($dep->value),
                    new InvokableAfterValidationRule($this->value),
                    fn ($validator) => $validator->errors()->add('closure', 'true'),
                ];
            }
        };
        $request->setContainer($container = new Container);
        $container->instance(ValidationFactoryContract::class, (new ValidationFactory(
            new \Hypervel\Translation\Translator(new \Hypervel\Translation\ArrayLoader, 'en')
        ))->setContainer($container));
        $container->instance(InjectedDependency::class, new InjectedDependency('value-from-dependency'));

        $messages = [];

        try {
            $request->validateResolved();
            $this->fail();
        } catch (Exception $e) {
            if (property_exists($e, 'validator')) {
                $messages = $e->validator->messages()->messages();
            }
        }

        $this->assertSame([
            'after' => ['value-from-dependency'],
            'invokable' => ['value-from-request'],
            'closure' => ['true'],
        ], $messages);
    }

    public function testRequestCanPassWithoutRulesMethod(): void
    {
        $request = $this->createRequest([], FoundationTestFormRequestWithoutRulesMethod::class);

        $request->validateResolved();

        $this->assertEquals([], $request->all());
    }

    public function testRequestWithGetRules(): void
    {
        FoundationTestFormRequestWithGetRules::$useRuleSet = 'a';
        $request = $this->createRequest(['a' => 1], FoundationTestFormRequestWithGetRules::class);

        $request->validateResolved();
        $this->assertEquals(['a' => 1], $request->all());

        $this->expectException(ValidationException::class);
        FoundationTestFormRequestWithGetRules::$useRuleSet = 'b';

        $request = $this->createRequest(['a' => 1], FoundationTestFormRequestWithGetRules::class);

        $request->validateResolved();
    }

    public function testFailOnUnknownFieldsRejectsExtraInputWhenEnabledOnRequest(): void
    {
        $request = $this->createRequest(
            ['name' => 'Taylor', 'unexpected' => 'value'],
            FoundationTestFormRequestFailOnUnknownFieldsStub::class,
            'POST'
        );

        $exception = $this->catchException(ValidationException::class, function () use ($request) {
            $request->validateResolved();
        });

        $this->assertTrue($exception->validator->errors()->has('unexpected'));
    }

    public function testFailOnUnknownFieldsAllowsExtraInputWhenExplicitlyDisabledOnRequest(): void
    {
        $request = $this->createRequest(
            ['name' => 'Taylor', 'with' => 'extras'],
            FoundationTestFormRequestSkipUnknownFieldsFailureStub::class,
            'POST'
        );

        $request->validateResolved();

        $this->assertEquals(['name' => 'Taylor'], $request->validated());
    }

    public function testFailOnUnknownFieldsEnabledViaFailOnUnknownFieldsStaticMethod(): void
    {
        FormRequest::failOnUnknownFields();

        $request = $this->createRequest(
            ['name' => 'Taylor', 'unexpected' => 'value'],
            FoundationTestFormRequestStub::class,
            'POST'
        );

        $exception = $this->catchException(ValidationException::class, function () use ($request) {
            $request->validateResolved();
        });

        $this->assertTrue($exception->validator->errors()->has('unexpected'));
    }

    public function testFailOnUnknownFieldsWorksWhenRequestDoesNotDefineRulesMethod(): void
    {
        FormRequest::failOnUnknownFields();

        $request = $this->createRequest(
            ['unexpected' => 'value'],
            FoundationTestFormRequestWithoutRulesMethod::class,
            'POST'
        );

        $exception = $this->catchException(ValidationException::class, function () use ($request) {
            $request->validateResolved();
        });

        $this->assertTrue($exception->validator->errors()->has('unexpected'));
    }

    public function testFailOnUnknownFieldsAttributeOverridesGlobalStatic(): void
    {
        FormRequest::failOnUnknownFields();

        $request = $this->createRequest(
            ['name' => 'Taylor', 'with' => 'extras'],
            FoundationTestFormRequestSkipUnknownFieldsFailureStub::class,
            'POST'
        );

        $request->validateResolved();

        $this->assertEquals(['name' => 'Taylor'], $request->validated());
    }

    public function testFailOnUnknownFieldsAllowsKeysMatchingWildcardRules(): void
    {
        $request = $this->createRequest(
            [
                'items' => [
                    ['id' => 1, 'name' => 'a'],
                    ['id' => 2, 'name' => 'b'],
                ],
            ],
            FoundationTestFormRequestFailOnUnknownFieldsWithWildcardStub::class,
            'POST'
        );

        $exception = $this->catchException(ValidationException::class, function () use ($request) {
            $request->validateResolved();
        });

        $this->assertTrue($exception->validator->errors()->has('items.0.name'));
    }

    public function testFailOnUnknownFieldsPassesForInputMatchingWildcardRulesOnly(): void
    {
        $request = $this->createRequest(
            [
                'items' => [
                    ['id' => 1],
                    ['id' => 2],
                ],
            ],
            FoundationTestFormRequestFailOnUnknownFieldsWithWildcardStub::class,
            'POST'
        );

        $request->validateResolved();

        $this->assertSame(
            [
                'items' => [
                    ['id' => 1],
                    ['id' => 2],
                ],
            ],
            $request->validated()
        );
    }

    public function testFailOnUnknownFieldsWildcardMatchesSingleSegmentOnly(): void
    {
        $request = $this->createRequest(
            [
                'items' => [
                    ['name' => 'a'],
                ],
            ],
            FoundationTestFormRequestFailOnUnknownFieldsSingleSegmentWildcardStub::class,
            'POST'
        );

        $exception = $this->catchException(ValidationException::class, function () use ($request) {
            $request->validateResolved();
        });

        $this->assertTrue($exception->validator->errors()->has('items.0.name'));
    }

    public function testFailOnUnknownFieldsRejectsMultipleUnknownKeys(): void
    {
        $request = $this->createRequest(
            [
                'name' => 'Taylor',
                'role' => 'admin',
                'profile' => ['is_admin' => true],
            ],
            FoundationTestFormRequestFailOnUnknownFieldsStub::class,
            'POST'
        );

        $exception = $this->catchException(ValidationException::class, function () use ($request) {
            $request->validateResolved();
        });

        $this->assertTrue($exception->validator->errors()->has('role'));
        $this->assertTrue($exception->validator->errors()->has('profile.is_admin'));
    }

    public function testFailOnUnknownFieldsRejectsUnknownNestedSibling(): void
    {
        $request = $this->createRequest(
            ['user' => ['name' => 'Taylor', 'role' => 'admin']],
            FoundationTestFormRequestFailOnUnknownFieldsNestedStub::class,
            'POST'
        );

        $exception = $this->catchException(ValidationException::class, function () use ($request) {
            $request->validateResolved();
        });

        $this->assertTrue($exception->validator->errors()->has('user.role'));
    }

    public function testFailOnUnknownFieldsUsesPreparedInput(): void
    {
        $request = $this->createRequest(
            ['full_name' => 'Taylor'],
            FoundationTestFormRequestFailOnUnknownFieldsPrepareForValidationStub::class,
            'POST'
        );

        $request->validateResolved();

        $this->assertSame(['name' => 'Taylor'], $request->validated());
    }

    public function testFailOnUnknownFieldsChecksRequestPayloadWhenValidationDataIsOverridden(): void
    {
        $request = $this->createRequest(
            ['name' => 'Taylor', 'unexpected' => 'value'],
            FoundationTestFormRequestFailOnUnknownFieldsValidationDataOverrideStub::class,
            'POST'
        );

        $exception = $this->catchException(ValidationException::class, function () use ($request) {
            $request->validateResolved();
        });

        $this->assertTrue($exception->validator->errors()->has('unexpected'));
    }

    public function testFailOnUnknownFieldsRejectsWildcardPayloadKeysOutsideEffectiveValidationData(): void
    {
        $request = $this->createRequest(
            [
                'items' => [
                    ['id' => 1],
                    ['id' => 2],
                ],
            ],
            FoundationTestFormRequestFailOnUnknownFieldsWildcardValidationDataOverrideStub::class,
            'POST'
        );

        $exception = $this->catchException(ValidationException::class, function () use ($request) {
            $request->validateResolved();
        });

        $this->assertTrue($exception->validator->errors()->has('items.1.id'));
    }

    public function testFailOnUnknownFieldsStillRunsWithStopOnFirstFailureAttribute(): void
    {
        $request = $this->createRequest(
            ['unexpected' => 'value'],
            FoundationTestFormRequestFailOnUnknownFieldsStopOnFirstFailureStub::class,
            'POST'
        );

        $exception = $this->catchException(ValidationException::class, function () use ($request) {
            $request->validateResolved();
        });

        $this->assertTrue($exception->validator->errors()->has('unexpected'));
    }

    public function testFailOnUnknownFieldsIgnoresQueryParametersOnGetRequests(): void
    {
        FormRequest::failOnUnknownFields();

        $request = $this->createRequest(
            ['page' => 1, 'perPage' => 5, 'expires' => 1234567890, 'signature' => 'abc123'],
            FoundationTestFormRequestWithoutRulesMethod::class
        );

        $request->validateResolved();

        $this->assertSame([], $request->validated());
    }

    public function testFailOnUnknownFieldsRejectsExtraJsonInput(): void
    {
        FormRequest::failOnUnknownFields();

        $request = $this->createRequest(
            [],
            FoundationTestFormRequestStub::class,
            'POST'
        );
        $request->headers->set('Content-Type', 'application/json');
        $request->setJson(new InputBag(['name' => 'Taylor', 'unexpected' => 'value']));

        $exception = $this->catchException(ValidationException::class, function () use ($request) {
            $request->validateResolved();
        });

        $this->assertTrue($exception->validator->errors()->has('unexpected'));
    }

    public function testFailOnUnknownFieldsAllowsConfirmationFieldsWhenBaseFieldIsConfirmed(): void
    {
        FormRequest::failOnUnknownFields();

        $request = $this->createRequest(
            ['password' => 'secret123', 'password_confirmation' => 'secret123'],
            FoundationTestFormRequestConfirmedFieldStub::class,
            'POST'
        );

        $request->validateResolved();

        $this->assertEquals(['password' => 'secret123'], $request->validated());
    }

    public function testFailOnUnknownFieldsAllowsCustomConfirmationFieldWhenBaseFieldIsConfirmed(): void
    {
        FormRequest::failOnUnknownFields();

        $request = $this->createRequest(
            ['password' => 'secret123', 'repeat_password' => 'secret123'],
            FoundationTestFormRequestCustomConfirmedFieldStub::class,
            'POST'
        );

        $request->validateResolved();

        $this->assertEquals(['password' => 'secret123'], $request->validated());
    }

    public function testFailOnUnknownFieldsRejectsConfirmationFieldsWithoutConfirmedRule(): void
    {
        FormRequest::failOnUnknownFields();

        $request = $this->createRequest(
            ['password' => 'secret123', 'password_confirmation' => 'secret123'],
            FoundationTestFormRequestUnconfirmedFieldStub::class,
            'POST'
        );

        $exception = $this->catchException(ValidationException::class, function () use ($request) {
            $request->validateResolved();
        });

        $this->assertTrue($exception->validator->errors()->has('password_confirmation'));
    }

    public function testFailOnUnknownFieldsUsesDynamicValidatorRules(): void
    {
        FormRequest::failOnUnknownFields();

        $request = $this->createRequest(
            ['name' => 'Taylor', 'nickname' => 'Tay'],
            FoundationTestFormRequestDynamicRulesStub::class,
            'POST'
        );

        $request->validateResolved();

        $this->assertEquals(['name' => 'Taylor', 'nickname' => 'Tay'], $request->validated());
    }

    public function testFailOnUnknownFieldsAllowsDeclaredFieldsFilteredOutByPrecognition(): void
    {
        FormRequest::failOnUnknownFields();

        $request = $this->createRequest(
            ['name' => [], 'email' => 'taylor@example.com'],
            FoundationTestFormRequestPrecognitiveStub::class,
            'POST'
        );

        $request->attributes->set('precognitive', true);
        $request->headers->set('Precognition-Validate-Only', 'name');

        $exception = $this->catchException(ValidationException::class, function () use ($request) {
            $request->validateResolved();
        });

        $this->assertTrue($exception->validator->errors()->has('name'));
        $this->assertFalse($exception->validator->errors()->has('email'));
    }

    public function testFlushStateClearsGlobalFailOnUnknownFields(): void
    {
        FormRequest::failOnUnknownFields();

        FormRequest::flushState();

        $request = $this->createRequest(
            ['unexpected' => 'value'],
            FoundationTestFormRequestWithoutRulesMethod::class,
            'POST'
        );

        $request->validateResolved();

        $this->assertSame([], $request->validated());
    }

    /**
     * Catch the given exception thrown from the executor, and return it.
     *
     * @throws Exception
     */
    protected function catchException(string $class, Closure $executor): Exception
    {
        try {
            $executor();
        } catch (Exception $e) {
            if (is_a($e, $class)) {
                return $e;
            }

            throw $e;
        }

        throw new Exception("No exception thrown. Expected exception {$class}.");
    }

    /**
     * Create a new request of the given type.
     *
     * @param class-string<\Hypervel\Foundation\Http\FormRequest> $class
     */
    protected function createRequest(array $payload = [], string $class = FoundationTestFormRequestStub::class, string $method = 'GET'): FormRequest
    {
        $container = tap(new Container, function ($container) {
            $container->instance(
                ValidationFactoryContract::class,
                $this->createValidationFactory($container)
            );
        });

        $request = $class::create('/', $method, $payload);

        return $request->setRedirector($this->createMockRedirector($request))
            ->setContainer($container);
    }

    /**
     * Create a new validation factory.
     */
    protected function createValidationFactory(Container $container): ValidationFactory
    {
        $translator = m::mock(Translator::class)->shouldReceive('get')
            ->zeroOrMoreTimes()->andReturn('error')
            ->shouldReceive('choice')->zeroOrMoreTimes()->andReturn('error')->getMock();

        return new ValidationFactory($translator, $container);
    }

    /**
     * Create a mock redirector.
     *
     * @param \Hypervel\Http\Request $request
     */
    protected function createMockRedirector(FormRequest $request): Redirector
    {
        $redirector = $this->mocks['redirector'] = m::mock(Redirector::class);

        $redirector->shouldReceive('getUrlGenerator')->zeroOrMoreTimes()
            ->andReturn($generator = $this->createMockUrlGenerator());

        $redirector->shouldReceive('to')->zeroOrMoreTimes()
            ->andReturn($this->createMockRedirectResponse());

        $generator->shouldReceive('previous')->zeroOrMoreTimes()
            ->andReturn('previous');

        return $redirector;
    }

    /**
     * Create a mock URL generator.
     */
    protected function createMockUrlGenerator(): UrlGenerator
    {
        return $this->mocks['generator'] = m::mock(UrlGenerator::class);
    }

    /**
     * Create a mock redirect response.
     */
    protected function createMockRedirectResponse(): RedirectResponse
    {
        return $this->mocks['redirect'] = m::mock(RedirectResponse::class);
    }

    public function testAttributeConfigurationIsCachedPerClass(): void
    {
        FormRequest::flushState();

        $request1 = $this->createRequest(['name' => 'Taylor']);
        $request1->validateResolved();

        $request2 = $this->createRequest(['name' => 'Taylor']);
        $request2->validateResolved();

        // Both used the same class — cache should have exactly one entry.
        $reflection = new ReflectionProperty(FormRequest::class, 'attributeConfiguration');
        $cache = $reflection->getValue();

        $this->assertArrayHasKey(FoundationTestFormRequestStub::class, $cache);
        $this->assertCount(1, $cache);
    }

    public function testAttributeConfigurationCachedIndependentlyPerSubclass(): void
    {
        FormRequest::flushState();

        $request1 = $this->createRequest(['name' => 'Taylor']);
        $request1->validateResolved();

        $this->mocks['redirect']->shouldReceive('withInput->withErrors');
        $request2 = $this->createRequest(['no' => 'name'], FoundationTestFormRequestWithErrorBagAttribute::class);

        try {
            $request2->validateResolved();
        } catch (ValidationException) {
            // expected
        }

        $reflection = new ReflectionProperty(FormRequest::class, 'attributeConfiguration');
        $cache = $reflection->getValue();

        $this->assertArrayHasKey(FoundationTestFormRequestStub::class, $cache);
        $this->assertArrayHasKey(FoundationTestFormRequestWithErrorBagAttribute::class, $cache);
        $this->assertCount(2, $cache);
    }

    public function testFlushStateClearsAttributeConfigurationCache(): void
    {
        $request = $this->createRequest(['name' => 'Taylor']);
        $request->validateResolved();

        $reflection = new ReflectionProperty(FormRequest::class, 'attributeConfiguration');
        $this->assertNotEmpty($reflection->getValue());

        FormRequest::flushState();

        $this->assertEmpty($reflection->getValue());
    }
}

class FoundationTestFormRequestStub extends FormRequest
{
    public function rules(): array
    {
        return ['name' => 'required'];
    }

    public function authorize(): bool
    {
        return true;
    }
}

class FoundationTestFormRequestNestedStub extends FormRequest
{
    public function rules(): array
    {
        return ['nested.foo' => 'required', 'array.*' => 'integer'];
    }

    public function authorize(): bool
    {
        return true;
    }
}

class FoundationTestFormRequestNestedChildStub extends FormRequest
{
    public function rules(): array
    {
        return ['nested.foo' => 'required'];
    }

    public function authorize(): bool
    {
        return true;
    }
}

class FoundationTestFormRequestNestedArrayStub extends FormRequest
{
    public function rules(): array
    {
        return ['nested.*.bar' => 'required'];
    }

    public function authorize(): bool
    {
        return true;
    }
}

class FoundationTestFormRequestTwiceStub extends FormRequest
{
    public static int $count = 0;

    public function rules(): array
    {
        return ['name' => 'required'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            ++self::$count;
        });
    }

    public function authorize(): bool
    {
        return true;
    }
}

class FoundationTestFormRequestForbiddenStub extends FormRequest
{
    public function authorize(): bool
    {
        return false;
    }
}

class FoundationTestFormRequestHooks extends FormRequest
{
    public function rules(): array
    {
        return ['name' => 'required'];
    }

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->replace(['name' => 'Taylor']);
    }

    protected function passedValidation(): void
    {
        $this->replace(['name' => 'Adam']);
    }
}

class FoundationTestFormRequestForbiddenWithResponseStub extends FormRequest
{
    public function authorize(): Response
    {
        return Response::deny('foo');
    }
}

class FoundationTestFormRequestPassesWithResponseStub extends FormRequest
{
    public function rules(): array
    {
        return [];
    }

    public function authorize(): Response
    {
        return Response::allow('baz');
    }
}

#[ErrorBag('login')]
class FoundationTestFormRequestWithErrorBagAttribute extends FormRequest
{
    public function rules(): array
    {
        return ['name' => 'required'];
    }

    public function authorize(): bool
    {
        return true;
    }
}

class InvokableAfterValidationRule
{
    public function __construct(private mixed $value)
    {
    }

    public function __invoke(Validator $validator): void
    {
        $validator->errors()->add('invokable', $this->value);
    }
}

class AfterValidationRule
{
    public function __construct(private mixed $value)
    {
    }

    public function after(Validator $validator): void
    {
        $validator->errors()->add('after', $this->value);
    }
}

class InjectedDependency
{
    public function __construct(public mixed $value)
    {
    }
}

class FoundationTestFormRequestWithoutRulesMethod extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}

class FoundationTestFormRequestWithGetRules extends FormRequest
{
    public static string $useRuleSet = 'a';

    protected function validationRules(): array
    {
        if (self::$useRuleSet === 'a') {
            return [
                'a' => ['required', 'int', 'min:1'],
            ];
        }
        return [
            'a' => ['required', 'int', 'min:2'],
        ];
    }
}

#[FailOnUnknownFields]
class FoundationTestFormRequestFailOnUnknownFieldsStub extends FormRequest
{
    public function rules(): array
    {
        return ['name' => 'required'];
    }

    public function authorize(): bool
    {
        return true;
    }
}

#[FailOnUnknownFields(false)]
class FoundationTestFormRequestSkipUnknownFieldsFailureStub extends FormRequest
{
    public function rules(): array
    {
        return ['name' => 'required'];
    }

    public function authorize(): bool
    {
        return true;
    }
}

#[FailOnUnknownFields]
class FoundationTestFormRequestFailOnUnknownFieldsWithWildcardStub extends FormRequest
{
    public function rules(): array
    {
        return ['items.*.id' => 'required'];
    }

    public function authorize(): bool
    {
        return true;
    }
}

#[FailOnUnknownFields]
class FoundationTestFormRequestFailOnUnknownFieldsSingleSegmentWildcardStub extends FormRequest
{
    public function rules(): array
    {
        return ['items.*' => 'array'];
    }

    public function authorize(): bool
    {
        return true;
    }
}

#[FailOnUnknownFields]
class FoundationTestFormRequestFailOnUnknownFieldsNestedStub extends FormRequest
{
    public function rules(): array
    {
        return ['user.name' => 'required'];
    }

    public function authorize(): bool
    {
        return true;
    }
}

#[FailOnUnknownFields]
class FoundationTestFormRequestFailOnUnknownFieldsPrepareForValidationStub extends FormRequest
{
    public function rules(): array
    {
        return ['name' => 'required'];
    }

    protected function prepareForValidation(): void
    {
        $this->replace(['name' => $this->input('full_name')]);
    }

    public function authorize(): bool
    {
        return true;
    }
}

#[FailOnUnknownFields]
class FoundationTestFormRequestFailOnUnknownFieldsValidationDataOverrideStub extends FormRequest
{
    public function rules(): array
    {
        return ['name' => 'required'];
    }

    public function validationData(): array
    {
        return ['name' => $this->input('name')];
    }

    public function authorize(): bool
    {
        return true;
    }
}

#[FailOnUnknownFields]
class FoundationTestFormRequestFailOnUnknownFieldsWildcardValidationDataOverrideStub extends FormRequest
{
    public function rules(): array
    {
        return ['items.*.id' => 'required'];
    }

    public function validationData(): array
    {
        return ['items' => [$this->input('items.0')]];
    }

    public function authorize(): bool
    {
        return true;
    }
}

#[StopOnFirstFailure]
#[FailOnUnknownFields]
class FoundationTestFormRequestFailOnUnknownFieldsStopOnFirstFailureStub extends FormRequest
{
    public function rules(): array
    {
        return ['name' => 'required'];
    }

    public function authorize(): bool
    {
        return true;
    }
}

class FoundationTestFormRequestConfirmedFieldStub extends FormRequest
{
    public function rules(): array
    {
        return ['password' => 'required|confirmed'];
    }

    public function authorize(): bool
    {
        return true;
    }
}

class FoundationTestFormRequestCustomConfirmedFieldStub extends FormRequest
{
    public function rules(): array
    {
        return ['password' => 'required|confirmed:repeat_password'];
    }

    public function authorize(): bool
    {
        return true;
    }
}

class FoundationTestFormRequestUnconfirmedFieldStub extends FormRequest
{
    public function rules(): array
    {
        return ['password' => 'required'];
    }

    public function authorize(): bool
    {
        return true;
    }
}

class FoundationTestFormRequestDynamicRulesStub extends FormRequest
{
    public function rules(): array
    {
        return ['name' => 'required'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->sometimes('nickname', 'required', fn () => true);
    }

    public function authorize(): bool
    {
        return true;
    }
}

class FoundationTestFormRequestPrecognitiveStub extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required',
            'email' => 'required|email',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
