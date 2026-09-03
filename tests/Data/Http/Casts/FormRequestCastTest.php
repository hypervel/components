<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Http\Casts\FormRequestCastTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Dto;
use Hypervel\Data\Http\Casts\AsData;
use Hypervel\Data\Http\Casts\AsDataCollection;
use Hypervel\Foundation\Http\FormRequest;
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

    public function testAsDataCastsValidatedInputThroughTheDataFactory(): void
    {
        $request = DataCastingRequest::create('/', 'POST', [
            'contact' => ['name' => 'Taylor'],
            'dto' => ['name' => 'Abigail'],
        ]);
        $request->setContainer($this->app);
        $request->validateResolved();

        $contact = $request->casted('contact');
        $dto = $request->casted('dto');

        $this->assertInstanceOf(RequestContactData::class, $contact);
        $this->assertSame('Taylor', $contact->name);
        $this->assertInstanceOf(RequestContactDto::class, $dto);
        $this->assertSame('Abigail', $dto->name);
    }

    public function testAsDataCollectionSupportsTheDefaultAndExplicitTargets(): void
    {
        $request = DataCollectionCastingRequest::create('/', 'POST', [
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
        $request->setContainer($this->app);

        $default = $request->casted('default_contacts', false);
        $array = $request->casted('array_contacts', false);
        $collection = $request->casted('collection_contacts', false);

        $this->assertInstanceOf(DataCollection::class, $default);
        $this->assertSame(['primary', 'secondary'], array_keys($default->items()));
        $this->assertEquals(new RequestContactData('Taylor'), $default['primary']);
        $this->assertIsArray($array);
        $this->assertSame(['primary'], array_keys($array));
        $this->assertEquals(new RequestContactData('Taylor'), $array['primary']);
        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertEquals(new RequestContactData('Abigail'), $collection['secondary']);
    }

    public function testDataCastsReturnNullForMissingOrNullInputs(): void
    {
        $request = NullableDataCastingRequest::create('/', 'POST', [
            'contact' => null,
            'contacts' => null,
        ]);
        $request->setContainer($this->app);

        $this->assertNull($request->casted('contact', false));
        $this->assertNull($request->casted('contacts', false));
        $this->assertNull($request->casted('missing_contact', false));
        $this->assertNull($request->casted('missing_contacts', false));
    }

    public function testCastDeclarationsUseTheGenericFoundationCasterSurface(): void
    {
        $this->assertSame(
            AsData::class . ':' . RequestContactData::class,
            AsData::of(RequestContactData::class),
        );
        $this->assertSame(
            AsDataCollection::class . ':' . RequestContactData::class . ',' . DataCollection::class,
            AsDataCollection::of(RequestContactData::class),
        );
        $this->assertSame(
            AsDataCollection::class . ':' . RequestContactData::class . ',array',
            AsDataCollection::of(RequestContactData::class, 'array'),
        );
    }

    public function testDataCastsRejectMissingAndInvalidTargetClasses(): void
    {
        foreach ([
            static fn () => AsData::castUsing(),
            static fn () => AsDataCollection::castUsing(),
            static fn () => AsData::castUsing([stdClass::class]),
            static fn () => AsDataCollection::castUsing([stdClass::class]),
        ] as $declaration) {
            $this->assertThrows($declaration, InvalidArgumentException::class);
        }
    }
}

class DataCastingRequest extends FormRequest
{
    /**
     * Get the request casts.
     */
    protected function casts(): array
    {
        return [
            'contact' => AsData::of(RequestContactData::class),
            'dto' => AsData::of(RequestContactDto::class),
        ];
    }

    /**
     * Get the validation rules for the request.
     */
    public function rules(): array
    {
        return [
            'contact' => ['required', 'array'],
            'contact.name' => ['required', 'string'],
            'dto' => ['required', 'array'],
            'dto.name' => ['required', 'string'],
        ];
    }
}

class DataCollectionCastingRequest extends FormRequest
{
    /**
     * Get the request casts.
     */
    protected function casts(): array
    {
        return [
            'default_contacts' => AsDataCollection::of(RequestContactData::class),
            'array_contacts' => AsDataCollection::of(RequestContactData::class, 'array'),
            'collection_contacts' => AsDataCollection::of(RequestContactData::class, Collection::class),
        ];
    }

    /**
     * Get the validation rules for the request.
     */
    public function rules(): array
    {
        return [];
    }
}

class NullableDataCastingRequest extends FormRequest
{
    /**
     * Get the request casts.
     */
    protected function casts(): array
    {
        return [
            'contact' => AsData::of(RequestContactData::class),
            'contacts' => AsDataCollection::of(RequestContactData::class),
            'missing_contact' => AsData::of(RequestContactData::class),
            'missing_contacts' => AsDataCollection::of(RequestContactData::class),
        ];
    }

    /**
     * Get the validation rules for the request.
     */
    public function rules(): array
    {
        return [];
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
