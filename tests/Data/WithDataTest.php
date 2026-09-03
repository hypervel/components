<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\WithDataTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Exceptions\CannotFindDataClass;
use Hypervel\Data\WithData;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Testbench\TestCase;
use Hypervel\Validation\ValidationException;

class WithDataTest extends TestCase
{
    /**
     * Get package providers for the WithData test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testModelCanDeclareItsDataClassWithAProperty(): void
    {
        $model = new WithDataModelSource;
        $model->setRawAttributes(['name' => 'Taylor']);

        $data = $model->getData();

        $this->assertInstanceOf(WithDataNameData::class, $data);
        $this->assertSame('Taylor', $data->name);
    }

    public function testArrayableCanDeclareItsDataClassWithAMethod(): void
    {
        $data = (new WithDataArrayableSource('Taylor'))->getData();

        $this->assertInstanceOf(WithDataNameData::class, $data);
        $this->assertSame('Taylor', $data->name);
    }

    public function testPropertyDeclarationTakesPrecedenceOverMethodDeclaration(): void
    {
        $data = (new WithDataPrecedenceSource)->getData();

        $this->assertInstanceOf(WithDataPropertyData::class, $data);
    }

    public function testMissingDataClassDeclarationFailsClearly(): void
    {
        $this->expectException(CannotFindDataClass::class);
        $this->expectExceptionMessageIs(
            'Class [' . WithDataMissingSource::class . '] must declare a [$dataClass] property or a [dataClass()] method to use [getData()].',
        );

        (new WithDataMissingSource)->getData();
    }

    public function testInvalidDataClassDeclarationFailsClearly(): void
    {
        $this->expectException(CannotFindDataClass::class);
        $this->expectExceptionMessageIs(
            'Class [' . WithDataInvalidSource::class . '] declared data class [array], which must implement [Hypervel\Data\Contracts\BaseData].',
        );

        (new WithDataInvalidSource)->getData();
    }

    public function testFormRequestUsesTheAssociatedDataClassValidation(): void
    {
        $request = WithDataFormRequestSource::create('/', 'POST', ['name' => 'invalid']);
        $request->setContainer($this->app);

        try {
            $request->getData();
            $this->fail('Expected the associated data class validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Data validation ran.'], $exception->errors()['name']);
        }
    }

    public function testModelSourceDoesNotValidateUnderOnlyRequests(): void
    {
        $model = new WithDataValidatedModelSource;
        $model->setRawAttributes(['name' => 'invalid']);

        $data = $model->getData();

        $this->assertInstanceOf(WithDataValidatedNameData::class, $data);
        $this->assertSame('invalid', $data->name);
    }
}

class WithDataNameData extends Data
{
    public function __construct(public string $name)
    {
    }
}

class WithDataPropertyData extends Data
{
}

class WithDataMethodData extends Data
{
}

class WithDataValidatedNameData extends Data
{
    public function __construct(public string $name)
    {
    }

    /**
     * Get the validation rules.
     */
    public static function rules(): array
    {
        return ['name' => ['in:data']];
    }

    /**
     * Get the validation messages.
     */
    public static function messages(): array
    {
        return ['name.in' => 'Data validation ran.'];
    }
}

class WithDataModelSource extends Model
{
    /** @use WithData<WithDataNameData> */
    use WithData;

    protected string $dataClass = WithDataNameData::class;
}

class WithDataValidatedModelSource extends Model
{
    /** @use WithData<WithDataValidatedNameData> */
    use WithData;

    protected string $dataClass = WithDataValidatedNameData::class;
}

class WithDataArrayableSource implements Arrayable
{
    /** @use WithData<WithDataNameData> */
    use WithData;

    public function __construct(public string $name)
    {
    }

    /**
     * Convert the source to an array.
     */
    public function toArray(): array
    {
        return ['name' => $this->name];
    }

    /**
     * Get the associated data class.
     */
    protected function dataClass(): string
    {
        return WithDataNameData::class;
    }
}

class WithDataPrecedenceSource implements Arrayable
{
    /** @use WithData<WithDataPropertyData> */
    use WithData;

    protected string $dataClass = WithDataPropertyData::class;

    /**
     * Convert the source to an array.
     */
    public function toArray(): array
    {
        return [];
    }

    /**
     * Get the fallback data class.
     */
    protected function dataClass(): string
    {
        return WithDataMethodData::class;
    }
}

class WithDataMissingSource
{
    /** @use WithData<WithDataNameData> */
    use WithData;
}

class WithDataInvalidSource
{
    /** @use WithData<WithDataNameData> */
    use WithData;

    protected array $dataClass = [];
}

class WithDataFormRequestSource extends FormRequest
{
    /** @use WithData<WithDataValidatedNameData> */
    use WithData;

    protected string $dataClass = WithDataValidatedNameData::class;

    /**
     * Get the FormRequest validation rules.
     */
    public function rules(): array
    {
        return ['name' => ['in:request']];
    }

    /**
     * Get the FormRequest validation messages.
     */
    public function messages(): array
    {
        return ['name.in' => 'FormRequest validation ran.'];
    }
}
