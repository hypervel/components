#!/usr/bin/env php
<?php

declare(strict_types=1);

use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Support\Annotations\DataIterableAnnotationReader;
use Hypervel\Data\Support\Creation\DataCreator;
use Hypervel\Data\Support\DataClassRepository;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\Factories\DataClassFactory;
use Hypervel\Data\Support\Transformation\DataTransformer;
use Hypervel\Data\Support\Validation\DataValidator;
use Hypervel\Support\DataObject as SupportDataObject;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Application as TestbenchApplication;

use function Hypervel\Coroutine\run;

const SAMPLES = 9;
const WARMUP = 2_000;
const OPERATIONS = 30_000;

require dirname(__DIR__, 3) . '/tests/bootstrap.php';

Bootstrapper::bootstrap();

enum BenchStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
}

class DataFlat extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public bool $active,
        public float $score,
    ) {
    }
}

class DataDefaults extends Data
{
    public function __construct(
        public int $id,
        public string $name = 'default',
        public bool $active = true,
        public float $score = 1.5,
        public ?string $note = null,
    ) {
    }
}

class DataWide extends Data
{
    public function __construct(
        public int $one,
        public int $two,
        public int $three,
        public int $four,
        public int $five,
        public int $six,
        public int $seven,
        public int $eight,
        public int $nine,
        public int $ten,
        public int $eleven,
        public int $twelve,
        public int $thirteen,
        public int $fourteen,
        public int $fifteen,
        public int $sixteen,
        public int $seventeen,
        public int $eighteen,
        public int $nineteen,
        public int $twenty,
    ) {
    }
}

class DataLeaf extends Data
{
    public function __construct(
        public int $id,
        public string $code,
        public bool $enabled,
        public float $score,
    ) {
    }
}

class DataNested extends Data
{
    public function __construct(
        public DataLeaf $child,
        public int $id,
        public string $name,
        public bool $active,
        public ?string $note,
    ) {
    }
}

class DataMiddle extends Data
{
    public function __construct(
        public DataLeaf $child,
        public int $id,
        public string $name,
    ) {
    }
}

class DataDeep extends Data
{
    public function __construct(
        public DataMiddle $child,
        public int $id,
        public string $name,
    ) {
    }
}

class DataEnum extends Data
{
    public function __construct(
        public int $id,
        public BenchStatus $status,
    ) {
    }
}

class DataDate extends Data
{
    public function __construct(
        public int $id,
        #[MapInputName('created_at')]
        public DateTimeImmutable $createdAt,
    ) {
    }
}

class DataMixed extends Data
{
    public function __construct(
        #[MapInputName('external_id')]
        #[MapOutputName('external_id')]
        public int $externalId,
        #[MapInputName('display_name')]
        #[MapOutputName('display_name')]
        public string $displayName,
        public BenchStatus $status,
        #[MapInputName('created_at')]
        #[MapOutputName('created_at')]
        public DateTimeImmutable $createdAt,
        public DataLeaf $child,
    ) {
    }
}

class DataCold extends Data
{
    public function __construct(public int $id, public string $name, public bool $active)
    {
    }
}

class DataWarm extends Data
{
    public function __construct(public int $id)
    {
    }
}

class LightweightFlat extends SupportDataObject
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public bool $active,
        public float $score,
    ) {
    }
}

class LightweightDefaults extends SupportDataObject
{
    public function __construct(
        public int $id,
        public string $name = 'default',
        public bool $active = true,
        public float $score = 1.5,
        public ?string $note = null,
    ) {
    }
}

class LightweightWide extends SupportDataObject
{
    public function __construct(
        public int $one,
        public int $two,
        public int $three,
        public int $four,
        public int $five,
        public int $six,
        public int $seven,
        public int $eight,
        public int $nine,
        public int $ten,
        public int $eleven,
        public int $twelve,
        public int $thirteen,
        public int $fourteen,
        public int $fifteen,
        public int $sixteen,
        public int $seventeen,
        public int $eighteen,
        public int $nineteen,
        public int $twenty,
    ) {
    }
}

class LightweightLeaf extends SupportDataObject
{
    public function __construct(
        public int $id,
        public string $code,
        public bool $enabled,
        public float $score,
    ) {
    }
}

class LightweightNested extends SupportDataObject
{
    public function __construct(
        public LightweightLeaf $child,
        public int $id,
        public string $name,
        public bool $active,
        public ?string $note,
    ) {
    }
}

class LightweightMiddle extends SupportDataObject
{
    public function __construct(
        public LightweightLeaf $child,
        public int $id,
        public string $name,
    ) {
    }
}

class LightweightDeep extends SupportDataObject
{
    public function __construct(
        public LightweightMiddle $child,
        public int $id,
        public string $name,
    ) {
    }
}

class LightweightEnum extends SupportDataObject
{
    public function __construct(
        public int $id,
        public BenchStatus $status,
    ) {
    }
}

class LightweightDate extends SupportDataObject
{
    public function __construct(
        public int $id,
        public DateTimeImmutable $createdAt,
    ) {
    }
}

class LightweightMixed extends SupportDataObject
{
    public function __construct(
        public int $externalId,
        public string $displayName,
        public BenchStatus $status,
        public DateTimeImmutable $createdAt,
        public LightweightLeaf $child,
    ) {
    }
}

class LightweightCold extends SupportDataObject
{
    public function __construct(public int $id, public string $name, public bool $active)
    {
    }
}

class LightweightItemList extends SupportDataObject
{
    public function __construct(public array $items)
    {
    }
}

// Data needs explicit item metadata to produce the same nested-array output as DataObject.
class DataItemList extends Data
{
    public function __construct(#[DataCollectionOf(DataLeaf::class)] public array $items)
    {
    }
}

/**
 * Run one operation in repeated batches and return p50/p95 nanoseconds.
 *
 * @param Closure(): int $operation
 * @return array{p50: float, p95: float}
 */
function measure(Closure $operation, int $operations = OPERATIONS, int $warmup = WARMUP): array
{
    $checksum = 0;

    for ($index = 0; $index < $warmup; ++$index) {
        $checksum += $operation();
    }

    $samples = [];

    for ($sample = 0; $sample < SAMPLES; ++$sample) {
        $startedAt = hrtime(true);

        for ($index = 0; $index < $operations; ++$index) {
            $checksum += $operation();
        }

        $samples[] = (hrtime(true) - $startedAt) / $operations;
    }

    if ($checksum === 0) {
        throw new LogicException('Benchmark operation produced an empty checksum.');
    }

    sort($samples, SORT_NUMERIC);

    return [
        'p50' => $samples[4],
        'p95' => $samples[8],
    ];
}

/**
 * Measure one operation in a fresh process.
 */
function coldMeasurement(string $mode): int
{
    $application = TestbenchApplication::create(options: ['load_environment_variables' => false]);
    $application->register(DataServiceProvider::class);

    try {
        if ($mode === 'data-class') {
            run(static fn (): DataWarm => DataWarm::from(['id' => 1]));
        }

        $elapsed = 0;
        $completed = run(static function () use ($application, $mode, &$elapsed): void {
            $startedAt = hrtime(true);

            match ($mode) {
                'data-object' => LightweightCold::from(['id' => 1, 'name' => 'cold', 'active' => true]),
                'data-first', 'data-class' => DataCold::from(['id' => 1, 'name' => 'cold', 'active' => true]),
                'data-config' => $application->make(DataConfig::class),
                'annotation-reader' => $application->make(DataIterableAnnotationReader::class),
                'class-factory' => $application->make(DataClassFactory::class),
                'class-repository' => $application->make(DataClassRepository::class),
                'data-validator' => $application->make(DataValidator::class),
                'data-creator' => $application->make(DataCreator::class),
                'data-transformer' => $application->make(DataTransformer::class),
                'data-runtime' => [$application->make(DataCreator::class), $application->make(DataTransformer::class)],
                default => throw new InvalidArgumentException("Unknown cold mode [{$mode}]."),
            };

            $elapsed = hrtime(true) - $startedAt;
        });

        if (! $completed) {
            throw new RuntimeException('Cold benchmark coroutine did not complete.');
        }

        return $elapsed;
    } finally {
        $application->terminate();
    }
}

/**
 * Return p50/p95 from fresh-process cold measurements.
 *
 * @return array{p50: float, p95: float}
 */
function measureCold(string $mode): array
{
    $samples = [];

    for ($sample = 0; $sample < SAMPLES; ++$sample) {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
            . ' --cold=' . escapeshellarg($mode);
        $output = shell_exec($command);

        if ($output === null || ! is_numeric(trim($output))) {
            throw new RuntimeException("Cold subprocess [{$mode}] failed.");
        }

        $samples[] = (float) trim($output);
    }

    sort($samples, SORT_NUMERIC);

    return ['p50' => $samples[4], 'p95' => $samples[8]];
}

/**
 * Measure retained instance bytes over a large held set.
 *
 * @template TInstance of object
 *
 * @param Closure(int): TInstance $factory
 * @param null|Closure(TInstance): void $prepare
 */
function retainedInstanceBytes(Closure $factory, ?Closure $prepare = null): float
{
    gc_collect_cycles();
    $baseline = memory_get_usage(false);
    $instances = [];

    for ($index = 1; $index <= 20_000; ++$index) {
        $instance = $factory($index);
        $prepare?->__invoke($instance);
        $instances[] = $instance;
    }

    $bytes = (memory_get_usage(false) - $baseline) / count($instances);
    unset($instances);
    gc_collect_cycles();

    return $bytes;
}

/**
 * Print one benchmark row.
 */
function printRow(string $scenario, array $dataObject, array $data): void
{
    printf(
        "%-38s %12.1f %12.1f %8.2fx %12.1f %12.1f\n",
        $scenario,
        $dataObject['p50'],
        $data['p50'],
        $data['p50'] / $dataObject['p50'],
        $dataObject['p95'],
        $data['p95'],
    );
}

/**
 * Run the comparison matrix.
 */
function execute(): void
{
    $application = TestbenchApplication::create(options: ['load_environment_variables' => false]);
    $application->register(DataServiceProvider::class);
    $reverseOrder = getenv('BENCHMARK_REVERSE') === '1';

    $flat = ['id' => 1, 'name' => 'Taylor', 'email' => 'taylor@example.com', 'active' => true, 'score' => 9.5];
    $coerced = ['id' => '1', 'name' => 123, 'email' => 456, 'active' => 1, 'score' => '9.5'];
    $defaults = ['id' => 1];
    $wide = array_combine(
        ['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty'],
        range(1, 20),
    );
    $leaf = ['id' => 1, 'code' => 'leaf', 'enabled' => true, 'score' => 9.5];
    $nested = ['child' => $leaf, 'id' => 2, 'name' => 'nested', 'active' => true, 'note' => null];
    $deep = ['child' => ['child' => $leaf, 'id' => 2, 'name' => 'middle'], 'id' => 3, 'name' => 'deep'];
    $enum = ['id' => 1, 'status' => 'active'];
    $dataObjectDate = ['id' => 1, 'createdAt' => '2026-09-04T12:34:56+00:00'];
    $dataDate = ['id' => 1, 'created_at' => '2026-09-04T12:34:56+00:00'];
    $dataObjectMixed = [
        'externalId' => '9',
        'displayName' => 123,
        'status' => 'active',
        'createdAt' => '2026-09-04T12:34:56+00:00',
        'child' => ['id' => '1', 'code' => 456, 'enabled' => 1, 'score' => '9.5'],
    ];
    $dataMixed = [
        'external_id' => '9',
        'display_name' => 123,
        'status' => 'active',
        'created_at' => '2026-09-04T12:34:56+00:00',
        'child' => ['id' => '1', 'code' => 456, 'enabled' => 1, 'score' => '9.5'],
    ];
    $rows = array_fill(0, 1_000, $flat);
    $itemRows = array_fill(0, 25, $leaf);

    try {
        run(function () use (
            $application,
            $coerced,
            $defaults,
            $enum,
            $flat,
            $itemRows,
            $dataDate,
            $dataMixed,
            $nested,
            $deep,
            $dataObjectDate,
            $dataObjectMixed,
            $reverseOrder,
            $rows,
            $wide,
        ): void {
            DataFlat::from($flat);
            LightweightFlat::from($flat);

            $construction = [
                'flat-5-scalars' => [
                    fn (): int => LightweightFlat::from($flat)->id,
                    fn (): int => DataFlat::from($flat)->id,
                ],
                'with-defaults' => [
                    fn (): int => LightweightDefaults::from($defaults)->id,
                    fn (): int => DataDefaults::from($defaults)->id,
                ],
                'wide-20-scalars' => [
                    fn (): int => LightweightWide::from($wide)->twenty,
                    fn (): int => DataWide::from($wide)->twenty,
                ],
                'flat-requiring-coercion' => [
                    fn (): int => LightweightFlat::from($coerced)->id,
                    fn (): int => DataFlat::from($coerced)->id,
                ],
                'nested-1-level' => [
                    fn (): int => LightweightNested::from($nested)->child->id,
                    fn (): int => DataNested::from($nested)->child->id,
                ],
                'deep-3-levels' => [
                    fn (): int => LightweightDeep::from($deep)->child->child->id,
                    fn (): int => DataDeep::from($deep)->child->child->id,
                ],
                'backed-enum' => [
                    fn (): int => LightweightEnum::from($enum)->status === BenchStatus::Active ? 1 : 0,
                    fn (): int => DataEnum::from($enum)->status === BenchStatus::Active ? 1 : 0,
                ],
                'date-time' => [
                    fn (): int => LightweightDate::from($dataObjectDate)->id,
                    fn (): int => DataDate::from($dataDate)->id,
                ],
                'mixed-api-payload' => [
                    fn (): int => LightweightMixed::from($dataObjectMixed)->child->id,
                    fn (): int => DataMixed::from($dataMixed)->child->id,
                ],
                'array-of-25-data-objects' => [
                    function () use ($itemRows): int {
                        $items = array_map(LightweightLeaf::from(...), $itemRows);

                        return LightweightItemList::from(['items' => $items])->items[0]->id;
                    },
                    function () use ($itemRows): int {
                        $items = array_map(DataLeaf::from(...), $itemRows);

                        return DataItemList::from(['items' => $items])->items[0]->id;
                    },
                ],
                '1000-item-from-loop' => [
                    function () use ($rows): int {
                        $checksum = 0;
                        foreach ($rows as $row) {
                            $checksum += LightweightFlat::from($row)->id;
                        }
                        return $checksum;
                    },
                    function () use ($rows): int {
                        $checksum = 0;
                        foreach ($rows as $row) {
                            $checksum += DataFlat::from($row)->id;
                        }
                        return $checksum;
                    },
                ],
            ];

            printf("Construction (nanoseconds per operation)\n");
            printf("%-38s %12s %12s %9s %12s %12s\n", 'scenario', 'object p50', 'data p50', 'ratio', 'object p95', 'data p95');

            foreach ($construction as $name => [$dataObject, $data]) {
                $divisor = $name === '1000-item-from-loop' ? 1_000 : 1;
                $operations = $divisor === 1
                    ? ($name === 'array-of-25-data-objects' ? 2_000 : OPERATIONS)
                    : 60;
                $warmup = $divisor === 1 ? WARMUP : 10;
                if ($reverseOrder) {
                    $dataResult = measure($data, $operations, $warmup);
                    $dataObjectResult = measure($dataObject, $operations, $warmup);
                } else {
                    $dataObjectResult = measure($dataObject, $operations, $warmup);
                    $dataResult = measure($data, $operations, $warmup);
                }
                $dataObjectResult = ['p50' => $dataObjectResult['p50'] / $divisor, 'p95' => $dataObjectResult['p95'] / $divisor];
                $dataResult = ['p50' => $dataResult['p50'] / $divisor, 'p95' => $dataResult['p95'] / $divisor];
                printRow($name, $dataObjectResult, $dataResult);
            }

            $dataObjectFlatObject = LightweightFlat::from($flat);
            $dataFlatObject = DataFlat::from($flat);
            $dataObjectWideObject = LightweightWide::from($wide);
            $dataWideObject = DataWide::from($wide);
            $dataObjectNestedObject = LightweightNested::from($nested);
            $dataNestedObject = DataNested::from($nested);
            $dataObjectDeepObject = LightweightDeep::from($deep);
            $dataDeepObject = DataDeep::from($deep);
            $dataObjectObjects = array_fill(0, 1_000, null);
            $dataObjects = array_fill(0, 1_000, null);

            foreach (array_keys($dataObjectObjects) as $index) {
                $dataObjectObjects[$index] = LightweightFlat::from($flat);
                $dataObjects[$index] = DataFlat::from($flat);
            }

            $dataObjectItemList = LightweightItemList::from(['items' => array_map(LightweightLeaf::from(...), $itemRows)]);
            $dataItemList = DataItemList::from(['items' => array_map(DataLeaf::from(...), $itemRows)]);

            $transformation = [
                'flat-uncached' => [
                    fn (): int => $dataObjectFlatObject->toArray()['id'],
                    fn (): int => $dataFlatObject->toArray()['id'],
                ],
                'wide-uncached' => [
                    fn (): int => $dataObjectWideObject->toArray()['twenty'],
                    fn (): int => $dataWideObject->toArray()['twenty'],
                ],
                'nested-whole-tree' => [
                    fn (): int => $dataObjectNestedObject->toArray()['child']['id'],
                    fn (): int => $dataNestedObject->toArray()['child']['id'],
                ],
                'deep-whole-tree' => [
                    fn (): int => $dataObjectDeepObject->toArray()['child']['child']['id'],
                    fn (): int => $dataDeepObject->toArray()['child']['child']['id'],
                ],
                'json-encode-nested' => [
                    fn (): int => strlen((string) json_encode($dataObjectNestedObject, JSON_THROW_ON_ERROR)),
                    fn (): int => strlen((string) json_encode($dataNestedObject, JSON_THROW_ON_ERROR)),
                ],
                'array-of-25-data-objects' => [
                    fn (): int => $dataObjectItemList->toArray()['items'][0]['id'],
                    fn (): int => $dataItemList->toArray()['items'][0]['id'],
                ],
                '1000-object-transform' => [
                    function () use ($dataObjectObjects): int {
                        $checksum = 0;
                        foreach ($dataObjectObjects as $object) {
                            $checksum += $object->toArray()['id'];
                        }
                        return $checksum;
                    },
                    function () use ($dataObjects): int {
                        $checksum = 0;
                        foreach ($dataObjects as $object) {
                            $checksum += $object->toArray()['id'];
                        }
                        return $checksum;
                    },
                ],
                'property-read' => [
                    fn (): int => $dataObjectFlatObject->id,
                    fn (): int => $dataFlatObject->id,
                ],
            ];

            printf("\nTransformation (nanoseconds per operation)\n");
            printf("%-38s %12s %12s %9s %12s %12s\n", 'scenario', 'object p50', 'data p50', 'ratio', 'object p95', 'data p95');

            foreach ($transformation as $name => [$dataObject, $data]) {
                $divisor = $name === '1000-object-transform' ? 1_000 : 1;
                $operations = $divisor === 1
                    ? ($name === 'array-of-25-data-objects' ? 3_000 : OPERATIONS)
                    : 80;
                $warmup = $divisor === 1 ? WARMUP : 10;
                if ($reverseOrder) {
                    $dataResult = measure($data, $operations, $warmup);
                    $dataObjectResult = measure($dataObject, $operations, $warmup);
                } else {
                    $dataObjectResult = measure($dataObject, $operations, $warmup);
                    $dataResult = measure($data, $operations, $warmup);
                }
                $dataObjectResult = ['p50' => $dataObjectResult['p50'] / $divisor, 'p95' => $dataObjectResult['p95'] / $divisor];
                $dataResult = ['p50' => $dataResult['p50'] / $divisor, 'p95' => $dataResult['p95'] / $divisor];
                printRow($name, $dataObjectResult, $dataResult);
            }

            printf("\nRetained instance bytes\n");
            printf("%-38s %12s %12s\n", 'scenario', 'data object', 'data');
            printf("%-38s %12.1f %12.1f\n", 'flat, untransformed', retainedInstanceBytes(fn (int $id): LightweightFlat => LightweightFlat::from([...$flat, 'id' => $id])), retainedInstanceBytes(fn (int $id): DataFlat => DataFlat::from([...$flat, 'id' => $id])));
            printf("%-38s %12.1f %12.1f\n", 'flat, transformed', retainedInstanceBytes(
                fn (int $id): LightweightFlat => LightweightFlat::from([...$flat, 'id' => $id]),
                static function (LightweightFlat $data): void {
                    $data->toArray();
                },
            ), retainedInstanceBytes(
                fn (int $id): DataFlat => DataFlat::from([...$flat, 'id' => $id]),
                static function (DataFlat $data): void {
                    $data->toArray();
                },
            ));
            printf("%-38s %12.1f %12.1f\n", 'wide, untransformed', retainedInstanceBytes(fn (int $id): LightweightWide => LightweightWide::from([...$wide, 'one' => $id])), retainedInstanceBytes(fn (int $id): DataWide => DataWide::from([...$wide, 'one' => $id])));
            printf("%-38s %12.1f %12.1f\n", 'wide, transformed', retainedInstanceBytes(
                fn (int $id): LightweightWide => LightweightWide::from([...$wide, 'one' => $id]),
                static function (LightweightWide $data): void {
                    $data->toArray();
                },
            ), retainedInstanceBytes(
                fn (int $id): DataWide => DataWide::from([...$wide, 'one' => $id]),
                static function (DataWide $data): void {
                    $data->toArray();
                },
            ));

            $repository = $application->make(DataClassRepository::class);
            $repository->get(DataWarm::class);
            gc_collect_cycles();
            $before = memory_get_usage(false);
            $repository->get(DataCold::class);
            $dataMetadata = memory_get_usage(false) - $before;

            SupportDataObject::flushState();
            gc_collect_cycles();
            $before = memory_get_usage(false);
            $object = LightweightCold::from(['id' => 1, 'name' => 'cold', 'active' => true]);
            unset($object);
            gc_collect_cycles();
            $dataObjectMetadata = memory_get_usage(false) - $before;

            printf("\nRetained metadata bytes (one small class, warm services)\n");
            printf("%-38s %12d %12d\n", 'metadata', $dataObjectMetadata, $dataMetadata);
        });
    } finally {
        $application->terminate();
    }

    printf("\nFresh-process first-use (nanoseconds)\n");
    printf("%-38s %12s %12s\n", 'scenario', 'p50', 'p95');
    foreach (['data-object', 'data-first', 'data-class'] as $mode) {
        $result = measureCold($mode);
        printf("%-38s %12.1f %12.1f\n", $mode, $result['p50'], $result['p95']);
    }
}

/**
 * Measure the major default creation layers after warm metadata.
 */
function profileDefaultCreation(): void
{
    $application = TestbenchApplication::create(options: ['load_environment_variables' => false]);
    $application->register(DataServiceProvider::class);
    $flat = ['id' => 1, 'name' => 'Taylor', 'email' => 'taylor@example.com', 'active' => true, 'score' => 9.5];

    try {
        run(function () use ($application, $flat): void {
            DataFlat::from($flat);
            $factory = DataFlat::factory();
            $context = $factory->get();
            $creator = $application->make(DataCreator::class);

            $operations = [
                'native-constructor' => fn (): int => (new DataFlat(1, 'Taylor', 'taylor@example.com', true, 9.5))->id,
                'base-data-from' => fn (): int => DataFlat::from($flat)->id,
                'prepared-factory-from' => fn (): int => $factory->from($flat)->id,
                'creator-with-context' => fn (): int => $creator->create(DataFlat::class, $context, $flat)->id,
                'factory-get-context' => fn (): int => $factory->get()->mapPropertyNames ? 1 : 0,
                'base-data-factory' => fn (): int => DataFlat::factory()->dataClass === DataFlat::class ? 1 : 0,
            ];

            printf("%-38s %12s %12s\n", 'layer', 'p50', 'p95');

            foreach ($operations as $name => $operation) {
                $result = measure($operation);
                printf("%-38s %12.1f %12.1f\n", $name, $result['p50'], $result['p95']);
            }
        });
    } finally {
        $application->terminate();
    }
}

/**
 * Measure first resolution of each fixed Data service graph boundary.
 */
function profileColdServices(): void
{
    printf("%-38s %12s %12s\n", 'service', 'p50', 'p95');

    foreach (['data-config', 'annotation-reader', 'class-factory', 'class-repository', 'data-validator', 'data-creator', 'data-transformer', 'data-runtime'] as $mode) {
        $result = measureCold($mode);
        printf("%-38s %12.1f %12.1f\n", $mode, $result['p50'], $result['p95']);
    }
}

/**
 * Measure the fixed transformation boundary separately from public dispatch.
 */
function profileTransformation(): void
{
    $application = TestbenchApplication::create(options: ['load_environment_variables' => false]);
    $application->register(DataServiceProvider::class);
    $flatPayload = ['id' => 1, 'name' => 'Taylor', 'email' => 'taylor@example.com', 'active' => true, 'score' => 9.5];
    $nestedPayload = [
        'child' => ['id' => 1, 'code' => 'leaf', 'enabled' => true, 'score' => 9.5],
        'id' => 2,
        'name' => 'nested',
        'active' => true,
        'note' => null,
    ];

    try {
        run(function () use ($application, $flatPayload, $nestedPayload): void {
            $flat = DataFlat::from($flatPayload);
            $nested = DataNested::from($nestedPayload);
            $transformer = $application->make(DataTransformer::class);
            $flatContext = $transformer->defaultContext($flat);
            $nestedContext = $transformer->defaultContext($nested);

            $operations = [
                'manual-flat-array' => fn (): int => ['id' => $flat->id, 'name' => $flat->name, 'email' => $flat->email, 'active' => $flat->active, 'score' => $flat->score]['id'],
                'transformer-flat' => fn (): int => $transformer->transform($flat, $flatContext)['id'],
                'public-flat-to-array' => fn (): int => $flat->toArray()['id'],
                'manual-nested-array' => fn (): int => ['child' => ['id' => $nested->child->id, 'code' => $nested->child->code, 'enabled' => $nested->child->enabled, 'score' => $nested->child->score], 'id' => $nested->id, 'name' => $nested->name, 'active' => $nested->active, 'note' => $nested->note]['child']['id'],
                'transformer-nested' => fn (): int => $transformer->transform($nested, $nestedContext)['child']['id'],
                'public-nested-to-array' => fn (): int => $nested->toArray()['child']['id'],
            ];

            printf("%-38s %12s %12s\n", 'layer', 'p50', 'p95');

            foreach ($operations as $name => $operation) {
                $result = measure($operation);
                printf("%-38s %12.1f %12.1f\n", $name, $result['p50'], $result['p95']);
            }
        });
    } finally {
        $application->terminate();
    }
}

$options = getopt('', ['cold:', 'profile', 'cold-profile', 'transform-profile']);

if (is_array($options) && isset($options['cold']) && is_string($options['cold'])) {
    echo coldMeasurement($options['cold']);
    exit(0);
}

if (is_array($options) && array_key_exists('profile', $options)) {
    profileDefaultCreation();
    exit(0);
}

if (is_array($options) && array_key_exists('cold-profile', $options)) {
    profileColdServices();
    exit(0);
}

if (is_array($options) && array_key_exists('transform-profile', $options)) {
    profileTransformation();
    exit(0);
}

execute();
