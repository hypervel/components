<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Http\Casts\FormRequestCastTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Dto;
use Hypervel\Data\Http\Casts\AsDataCollection;
use Hypervel\Data\Resource;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Routing\Redirector;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use stdClass;

class FormRequestCastTest extends TestCase
{
    /**
     * Get package providers for the FormRequest cast test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    /**
     * Test Data, Dto, and Resource classes cast validated request input directly.
     */
    public function testDataClassesCastValidatedInputDirectly(): void
    {
        $request = $this->validateRequest(DataCastingRequest::class, [
            'contact' => ['name' => 'Taylor'],
            'dto' => ['name' => 'Abigail'],
            'resource' => ['name' => 'Tim'],
        ]);
        $validated = $request->validated();

        $this->assertEquals(new RequestContactData('Taylor'), $validated['contact']);
        $this->assertEquals(new RequestContactDto('Abigail'), $validated['dto']);
        $this->assertEquals(new RequestContactResource('Tim'), $validated['resource']);
    }

    /**
     * Test direct data object request casts reject declaration arguments.
     */
    public function testDataClassCastRejectsArguments(): void
    {
        $request = $this->validateRequest(DataCastingWithArgumentsRequest::class, [
            'contact' => ['name' => 'Taylor'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Data object request cast [' . RequestContactData::class . '] does not accept arguments.',
        );

        $request->validated();
    }

    /**
     * Test data collection casts support default and explicit targets.
     */
    public function testDataCollectionSupportsDefaultAndExplicitTargets(): void
    {
        $request = $this->validateRequest(DataCollectionCastingRequest::class, [
            'default_contacts' => [
                'primary' => ['name' => 'Taylor'],
                'secondary' => ['name' => 'Abigail'],
            ],
            'array_contacts' => [
                'primary' => ['name' => 'Taylor'],
            ],
            'collection_contacts' => [
                'secondary' => ['name' => 'Abigail'],
            ],
        ]);
        $validated = $request->validated();
        $default = $validated['default_contacts'];
        $array = $validated['array_contacts'];
        $collection = $validated['collection_contacts'];

        $this->assertInstanceOf(DataCollection::class, $default);
        $this->assertSame(['primary', 'secondary'], array_keys($default->items()));
        $this->assertEquals(new RequestContactData('Taylor'), $default['primary']);
        $this->assertIsArray($array);
        $this->assertSame(['primary'], array_keys($array));
        $this->assertEquals(new RequestContactData('Taylor'), $array['primary']);
        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertEquals(new RequestContactData('Abigail'), $collection['secondary']);
    }

    /**
     * Test Data casts preserve present nulls and do not add missing input.
     */
    public function testDataCastsPreserveNullAndMissingInput(): void
    {
        $request = $this->validateRequest(NullableDataCastingRequest::class, [
            'contact' => null,
            'contacts' => null,
        ]);
        $validated = $request->validated();

        $this->assertSame([
            'contact' => null,
            'contacts' => null,
        ], $validated);
        $this->assertArrayNotHasKey('missing_contact', $validated);
        $this->assertArrayNotHasKey('missing_contacts', $validated);
    }

    /**
     * Test data collection declarations retain Laravel-style positional syntax.
     */
    public function testDataCollectionDeclarationSyntax(): void
    {
        $this->assertSame(
            AsDataCollection::class . ':' . RequestContactData::class . ',' . DataCollection::class,
            AsDataCollection::of(RequestContactData::class),
        );
        $this->assertSame(
            AsDataCollection::class . ':' . RequestContactData::class . ',array',
            AsDataCollection::of(RequestContactData::class, 'array'),
        );
    }

    /**
     * Test data collection casts reject missing and invalid data classes.
     */
    public function testDataCollectionRejectsInvalidDeclarations(): void
    {
        foreach ([
            static fn () => AsDataCollection::castRequestUsing([]),
            static fn () => AsDataCollection::castRequestUsing([stdClass::class]),
        ] as $declaration) {
            $this->assertThrows($declaration, InvalidArgumentException::class);
        }
    }

    /**
     * Validate and prepare a form request.
     *
     * @template TRequest of FormRequest
     *
     * @param class-string<TRequest> $class
     * @return TRequest
     */
    protected function validateRequest(string $class, array $input): FormRequest
    {
        $request = $class::create('/', 'POST', $input);
        $request->setContainer($this->app)
            ->setRedirector($this->app->make(Redirector::class));
        $request->validateResolved();

        return $request;
    }
}

class DataCastingRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'contact' => RequestContactData::class,
            'dto' => RequestContactDto::class,
            'resource' => RequestContactResource::class,
        ];
    }

    public function rules(): array
    {
        return [
            'contact' => ['required', 'array'],
            'contact.name' => ['required', 'string'],
            'dto' => ['required', 'array'],
            'dto.name' => ['required', 'string'],
            'resource' => ['required', 'array'],
            'resource.name' => ['required', 'string'],
        ];
    }
}

class DataCastingWithArgumentsRequest extends FormRequest
{
    protected function casts(): array
    {
        return ['contact' => RequestContactData::class . ':default'];
    }

    public function rules(): array
    {
        return [
            'contact' => ['required', 'array'],
            'contact.name' => ['required', 'string'],
        ];
    }
}

class DataCollectionCastingRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'default_contacts' => AsDataCollection::of(RequestContactData::class),
            'array_contacts' => AsDataCollection::of(RequestContactData::class, 'array'),
            'collection_contacts' => AsDataCollection::of(RequestContactData::class, Collection::class),
        ];
    }

    public function rules(): array
    {
        return [
            'default_contacts' => ['required', 'array'],
            'array_contacts' => ['required', 'array'],
            'collection_contacts' => ['required', 'array'],
        ];
    }
}

class NullableDataCastingRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'contact' => RequestContactData::class,
            'contacts' => AsDataCollection::of(RequestContactData::class),
            'missing_contact' => RequestContactData::class,
            'missing_contacts' => AsDataCollection::of(RequestContactData::class),
        ];
    }

    public function rules(): array
    {
        return [
            'contact' => ['nullable'],
            'contacts' => ['nullable'],
        ];
    }
}

class RequestContactData extends Data
{
    public function __construct(public string $name)
    {
    }
}

class RequestContactDto extends Dto
{
    public function __construct(public string $name)
    {
    }
}

class RequestContactResource extends Resource
{
    public function __construct(public string $name)
    {
    }
}
