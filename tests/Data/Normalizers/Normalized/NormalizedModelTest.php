<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Normalizers\Normalized;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Data\Attributes\LoadRelation;
use Hypervel\Data\Normalizers\Normalized\NormalizedModel;
use Hypervel\Data\Normalizers\Normalized\UnknownProperty;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Factories\DataPropertyFactory;
use Hypervel\Data\Support\Factories\DataTypeFactory;
use Hypervel\Data\Support\NameMapperResolver;
use Hypervel\Data\Support\Types\PhpDocTypeNameResolver;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Tests\TestCase;
use ReflectionClass;
use stdClass;

class NormalizedModelTest extends TestCase
{
    /**
     * Test only declared model attributes are read and null remains present.
     */
    public function testReadsDeclaredSnakeCaseAttributesWithoutSerializingTheModel(): void
    {
        $model = new NormalizedModelFixture;
        $model->setRawAttributes([
            'first_name' => 'Taylor',
            'nullable_name' => null,
            'unrequested' => 'ignored',
        ]);
        $source = new NormalizedModel($model);

        $this->assertSame('Taylor', $source->getProperty('firstName', $this->property('firstName')));
        $this->assertNull($source->getProperty('nullableName', $this->property('nullableName')));
        $this->assertSame(
            UnknownProperty::create(),
            $source->getProperty('missing', $this->property('missing')),
        );
        $this->assertSame(0, $model->serializationCount);
    }

    /**
     * Test loaded relations are returned without another load.
     */
    public function testReadsAlreadyLoadedRelations(): void
    {
        $relation = new stdClass;
        $model = new NormalizedModelFixture;
        $model->setRelation('profile', $relation);
        $source = new NormalizedModel($model);

        $this->assertSame($relation, $source->getProperty('profile', $this->property('profile')));
        $this->assertSame(0, $model->loadMissingCount);
    }

    /**
     * Test LoadRelation explicitly permits one missing relation load.
     */
    public function testLoadsOnlyRelationsMarkedForLoading(): void
    {
        $model = new NormalizedModelFixture;
        $source = new NormalizedModel($model);

        $this->assertSame(
            UnknownProperty::create(),
            $source->getProperty('profile', $this->property('profile')),
        );

        $loaded = $source->getProperty('loadedProfile', $this->property('loadedProfile'));

        $this->assertInstanceOf(stdClass::class, $loaded);
        $this->assertSame(1, $model->loadMissingCount);
        $this->assertSame($loaded, $source->getProperty('loadedProfile', $this->property('loadedProfile')));
        $this->assertSame(1, $model->loadMissingCount);
    }

    /**
     * Build property metadata for the model projection fixture.
     */
    protected function property(string $name): DataProperty
    {
        $defaults = require __DIR__ . '/../../../../src/data/config/data.php';
        $config = new DataConfig(new Repository(['data' => $defaults]));
        $typeFactory = new DataTypeFactory(new PhpDocTypeNameResolver);
        $reflectionClass = new ReflectionClass(NormalizedModelDataFixture::class);

        return (new DataPropertyFactory(
            $typeFactory,
            $config,
            new NameMapperResolver(new Container),
        ))->build(
            $reflectionClass->getProperty($name),
            $reflectionClass,
        );
    }
}

class NormalizedModelDataFixture
{
    public string $firstName;

    public ?string $nullableName;

    public string $missing;

    public ?object $profile;

    #[LoadRelation]
    public ?object $loadedProfile;
}

class NormalizedModelFixture extends Model
{
    public int $serializationCount = 0;

    public int $loadMissingCount = 0;

    /**
     * Determine if a fixture relation exists.
     */
    public function isRelation(string $key): bool
    {
        return in_array($key, ['profile', 'loadedProfile'], true);
    }

    /**
     * Mark a requested fixture relation as loaded.
     */
    public function loadMissing(array|string $relations): static
    {
        ++$this->loadMissingCount;
        $relation = is_array($relations) ? $relations[0] : $relations;
        $this->setRelation($relation, new stdClass);

        return $this;
    }

    /**
     * Fail if model-wide serialization is attempted.
     */
    public function toArray(): array
    {
        ++$this->serializationCount;

        return parent::toArray();
    }
}
