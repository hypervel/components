<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Normalizers\FormRequestNormalizerTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Normalizers\FormRequestNormalizer;
use Hypervel\Foundation\Http\Attributes\FailOnUnknownFields;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\DataWithNullable;
use Hypervel\Validation\ValidationException;

#[WithConfig('data.normalizers', [FormRequestNormalizer::class])]
class FormRequestNormalizerTest extends TestCase
{
    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testWillNotNormalizeAnyOtherThingThanAFormRequest(): void
    {
        $data = DataWithNullable::from([
            'string' => 'Hello',
            'nullableString' => 'World',
        ]);

        $this->assertSame(['string' => 'Hello', 'nullableString' => 'World'], $data->toArray());
    }

    public function testCanCreateADataObjectFromFormRequest(): void
    {
        $request = new class extends FormRequest {
            /**
             * Get the validation rules.
             */
            public function rules(): array
            {
                return [
                    'string' => 'required|string',
                    'nullableString' => 'nullable|string',
                ];
            }
        };
        $request
            ->replace([
                'string' => 'Hello',
                'nullableString' => 'World',
            ])
            ->setContainer($this->app)
            ->validateResolved();

        $this->assertEquals(new DataWithNullable('Hello', 'World'), DataWithNullable::from($request));
    }

    public function testExcludesUnsafeData(): void
    {
        $request = new class extends FormRequest {
            /**
             * Get the validation rules.
             */
            public function rules(): array
            {
                return [
                    'string' => 'required|string',
                ];
            }
        };
        $request
            ->replace([
                'string' => 'Hello',
                'nullableString' => 'World',
            ])
            ->setContainer($this->app)
            ->validateResolved();

        $this->assertEquals(new DataWithNullable('Hello', null), DataWithNullable::from($request));
    }

    public function testUnknownFieldsAreCheckedAgainstTheValidatedInput(): void
    {
        $request = (new UnknownFieldsFormRequest)->replace(['string' => 'Hello', 'extra' => 'excluded'])->setContainer($this->app);
        $request->validateResolved();

        $this->assertSame('Hello', StrictFormRequestData::from($request)->string);

        $request = (new UnknownFieldsFormRequest)->replace(['string' => 'Hello', 'role' => 'admin'])->setContainer($this->app);
        $request->validateResolved();

        try {
            StrictFormRequestData::from($request);
            $this->fail('Expected validated input the data class does not declare to fail unknown-field validation.');
        } catch (ValidationException $exception) {
            $this->assertSame(['role'], array_keys($exception->errors()));
        }
    }
}

class UnknownFieldsFormRequest extends FormRequest
{
    /**
     * Get the validation rules.
     */
    public function rules(): array
    {
        return [
            'string' => 'required|string',
            'role' => 'sometimes|string',
        ];
    }
}

#[FailOnUnknownFields]
class StrictFormRequestData extends Data
{
    /**
     * Create the strict data object.
     */
    public function __construct(
        public string $string,
    ) {
    }
}
