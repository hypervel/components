<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\VarDumper\DataVarDumperCasterTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Contracts\TransformableData;
use Hypervel\Data\CursorPaginatedDataCollection;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Lazy;
use Hypervel\Data\Optional;
use Hypervel\Data\PaginatedDataCollection;
use Hypervel\Data\Resource;
use Hypervel\Data\Support\VarDumper\DataVarDumperCaster;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Testbench\TestCase;
use stdClass;
use Symfony\Component\VarDumper\Cloner\AbstractCloner;
use Symfony\Component\VarDumper\Cloner\Stub;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

class DataVarDumperCasterTest extends TestCase
{
    /**
     * Get package providers for the VarDumper caster test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCasterReturnsTheCurrentMappedLogicalView(): void
    {
        $data = new DumpData(
            'Taylor',
            Optional::create(),
            Lazy::create(static fn (): string => 'secret'),
        );
        $resource = new DumpResource('Abigail');

        $data->name = 'Jess';

        $this->assertSame(
            ['display_name' => 'Jess'],
            DataVarDumperCaster::cast($data, ['internal' => true], new Stub, false),
        );
        $this->assertSame(
            ['display_name' => 'Abigail'],
            DataVarDumperCaster::cast($resource, [], new Stub, false),
        );
    }

    public function testCasterUsesOneItemsEnvelopeForEveryCollectionShape(): void
    {
        $item = new DumpData('Taylor', Optional::create(), 'visible');
        $collection = new DataCollection(DumpData::class, [$item]);
        $paginated = new PaginatedDataCollection(
            DumpData::class,
            new Paginator([$item], 15, 1),
        );
        $cursorPaginated = new CursorPaginatedDataCollection(
            DumpData::class,
            new CursorPaginator([$item], 15),
        );

        foreach ([$collection, $paginated, $cursorPaginated] as $data) {
            $this->assertSame(
                ['items' => [$item]],
                DataVarDumperCaster::cast($data, [], new Stub, false),
            );
        }
    }

    public function testSymfonyAppliesTheRegisteredInterfaceCaster(): void
    {
        $data = new DumpData(
            'Taylor',
            Optional::create(),
            Lazy::create(static fn (): string => 'secret'),
        );
        $output = $this->dump($data);

        $this->assertSame(
            [DataVarDumperCaster::class, 'cast'],
            AbstractCloner::$defaultCasters[TransformableData::class],
        );
        $this->assertStringContainsString('display_name', $output);
        $this->assertStringContainsString('Taylor', $output);
        $this->assertStringNotContainsString('_additional', $output);
        $this->assertStringNotContainsString('partialDefinitions', $output);
        $this->assertStringNotContainsString('secret', $output);
    }

    public function testProviderPreservesAnExistingApplicationCaster(): void
    {
        $hadCaster = array_key_exists(
            TransformableData::class,
            AbstractCloner::$defaultCasters,
        );
        $previousCaster = AbstractCloner::$defaultCasters[TransformableData::class] ?? null;
        $caster = static fn (): array => ['custom' => true];

        try {
            AbstractCloner::$defaultCasters[TransformableData::class] = $caster;

            (new DataServiceProvider($this->app))->boot();

            $this->assertSame(
                $caster,
                AbstractCloner::$defaultCasters[TransformableData::class],
            );
        } finally {
            if ($hadCaster) {
                AbstractCloner::$defaultCasters[TransformableData::class] = $previousCaster;
            } else {
                unset(AbstractCloner::$defaultCasters[TransformableData::class]);
            }
        }
    }

    public function testOrdinaryObjectsKeepSymfonyDefaultDumping(): void
    {
        $object = new stdClass;
        $object->name = 'Taylor';

        $output = $this->dump($object);

        $this->assertStringContainsString('name', $output);
        $this->assertStringContainsString('Taylor', $output);
    }

    /**
     * Dump a value through Symfony's configured cloner.
     */
    private function dump(mixed $value): string
    {
        $dumper = new CliDumper;
        $dumper->setColors(false);

        return $dumper->dump((new VarCloner)->cloneVar($value), true);
    }
}

class DumpData extends Data
{
    public function __construct(
        #[MapOutputName('display_name')]
        public string $name,
        public string|Optional $missing,
        public Lazy|string $secret,
    ) {
    }
}

class DumpResource extends Resource
{
    public function __construct(
        #[MapOutputName('display_name')]
        public string $name,
    ) {
    }
}
