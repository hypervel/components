#!/usr/bin/env php
<?php

declare(strict_types=1);

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
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Application as TestbenchApplication;
use Hypervel\Tests\Benchmarks\Data\Fixtures\DataObject;

use function Hypervel\Coroutine\run;

const SAMPLES = 9;
const WARMUP = 2_000;
const OPERATIONS = 30_000;

require dirname(__DIR__, 3) . '/tests/bootstrap.php';
require __DIR__ . '/Fixtures/DataObject.php';

Bootstrapper::bootstrap();

enum BenchStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
}

class OldFlat extends DataObject
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

class NewFlat extends Data
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

class OldDefaults extends DataObject
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

class NewDefaults extends Data
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

class OldWide extends DataObject
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

class NewWide extends Data
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

class OldLeaf extends DataObject
{
    public function __construct(
        public int $id,
        public string $code,
        public bool $enabled,
        public float $score,
    ) {
    }
}

class NewLeaf extends Data
{
    public function __construct(
        public int $id,
        public string $code,
        public bool $enabled,
        public float $score,
    ) {
    }
}

class OldNested extends DataObject
{
    public function __construct(
        public OldLeaf $child,
        public int $id,
        public string $name,
        public bool $active,
        public ?string $note,
    ) {
    }
}

class NewNested extends Data
{
    public function __construct(
        public NewLeaf $child,
        public int $id,
        public string $name,
        public bool $active,
        public ?string $note,
    ) {
    }
}

class OldMiddle extends DataObject
{
    public function __construct(
        public OldLeaf $child,
        public int $id,
        public string $name,
    ) {
    }
}

class NewMiddle extends Data
{
    public function __construct(
        public NewLeaf $child,
        public int $id,
        public string $name,
    ) {
    }
}

class OldDeep extends DataObject
{
    public function __construct(
        public OldMiddle $child,
        public int $id,
        public string $name,
    ) {
    }
}

class NewDeep extends Data
{
    public function __construct(
        public NewMiddle $child,
        public int $id,
        public string $name,
    ) {
    }
}

class OldEnum extends DataObject
{
    public function __construct(
        public int $id,
        public BenchStatus $status,
    ) {
    }
}

class NewEnum extends Data
{
    public function __construct(
        public int $id,
        public BenchStatus $status,
    ) {
    }
}

class OldDate extends DataObject
{
    public function __construct(
        public int $id,
        public DateTimeImmutable $createdAt,
    ) {
    }
}

class NewDate extends Data
{
    public function __construct(
        public int $id,
        #[MapInputName('created_at')]
        public DateTimeImmutable $createdAt,
    ) {
    }
}

class OldMixed extends DataObject
{
    public function __construct(
        public int $externalId,
        public string $displayName,
        public BenchStatus $status,
        public DateTimeImmutable $createdAt,
        public OldLeaf $child,
    ) {
    }
}

class NewMixed extends Data
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
        public NewLeaf $child,
    ) {
    }
}

class OldCold extends DataObject
{
    public function __construct(public int $id, public string $name, public bool $active)
    {
    }
}

class NewCold extends Data
{
    public function __construct(public int $id, public string $name, public bool $active)
    {
    }
}

class NewWarm extends Data
{
    public function __construct(public int $id)
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
            run(static fn (): NewWarm => NewWarm::from(['id' => 1]));
        }

        $elapsed = 0;
        $completed = run(static function () use ($application, $mode, &$elapsed): void {
            $startedAt = hrtime(true);

            match ($mode) {
                'old' => OldCold::from(['id' => 1, 'name' => 'cold', 'active' => true]),
                'data-first', 'data-class' => NewCold::from(['id' => 1, 'name' => 'cold', 'active' => true]),
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
 * @param Closure(int): object $factory
 */
function retainedInstanceBytes(Closure $factory): float
{
    gc_collect_cycles();
    $baseline = memory_get_usage(false);
    $instances = [];

    for ($index = 1; $index <= 20_000; ++$index) {
        $instances[] = $factory($index);
    }

    $bytes = (memory_get_usage(false) - $baseline) / count($instances);
    unset($instances);
    gc_collect_cycles();

    return $bytes;
}

/**
 * Print one benchmark row.
 */
function printRow(string $scenario, array $old, array $new): void
{
    printf(
        "%-38s %12.1f %12.1f %8.2fx %12.1f %12.1f\n",
        $scenario,
        $old['p50'],
        $new['p50'],
        $new['p50'] / $old['p50'],
        $old['p95'],
        $new['p95'],
    );
}

/**
 * Run the comparison matrix.
 */
function execute(): void
{
    $application = TestbenchApplication::create(options: ['load_environment_variables' => false]);
    $application->register(DataServiceProvider::class);

    $flat = ['id' => 1, 'name' => 'Taylor', 'email' => 'taylor@example.com', 'active' => true, 'score' => 9.5];
    $coerced = ['id' => '1', 'name' => 123, 'email' => 456, 'active' => 1, 'score' => '9.5'];
    $defaults = ['id' => 1];
    $wide = array_combine(
        ['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty'],
        range(1, 20),
    );
    $oldLeaf = ['id' => 1, 'code' => 'leaf', 'enabled' => true, 'score' => 9.5];
    $newLeaf = $oldLeaf;
    $oldNested = ['child' => $oldLeaf, 'id' => 2, 'name' => 'nested', 'active' => true, 'note' => null];
    $newNested = ['child' => $newLeaf, 'id' => 2, 'name' => 'nested', 'active' => true, 'note' => null];
    $oldDeep = ['child' => ['child' => $oldLeaf, 'id' => 2, 'name' => 'middle'], 'id' => 3, 'name' => 'deep'];
    $newDeep = ['child' => ['child' => $newLeaf, 'id' => 2, 'name' => 'middle'], 'id' => 3, 'name' => 'deep'];
    $enum = ['id' => 1, 'status' => 'active'];
    $oldDate = ['id' => 1, 'created_at' => '2026-09-04 12:34:56'];
    $newDate = ['id' => 1, 'created_at' => '2026-09-04T12:34:56+00:00'];
    $oldMixed = [
        'external_id' => '9',
        'display_name' => 123,
        'status' => 'active',
        'created_at' => '2026-09-04 12:34:56',
        'child' => ['id' => '1', 'code' => 456, 'enabled' => 1, 'score' => '9.5'],
    ];
    $newMixed = [...$oldMixed, 'created_at' => '2026-09-04T12:34:56+00:00'];
    $rows = array_fill(0, 1_000, $flat);

    try {
        run(function () use (
            $application,
            $coerced,
            $defaults,
            $enum,
            $flat,
            $newDate,
            $newDeep,
            $newMixed,
            $newNested,
            $oldDeep,
            $oldDate,
            $oldMixed,
            $oldNested,
            $rows,
            $wide,
        ): void {
            NewFlat::from($flat);
            OldFlat::from($flat);

            $construction = [
                'flat-5-scalars' => [
                    fn (): int => OldFlat::from($flat)->id,
                    fn (): int => NewFlat::from($flat)->id,
                ],
                'with-defaults' => [
                    fn (): int => OldDefaults::from($defaults)->id,
                    fn (): int => NewDefaults::from($defaults)->id,
                ],
                'wide-20-scalars' => [
                    fn (): int => OldWide::from($wide)->twenty,
                    fn (): int => NewWide::from($wide)->twenty,
                ],
                'flat-requiring-coercion' => [
                    fn (): int => OldFlat::from($coerced)->id,
                    fn (): int => NewFlat::from($coerced)->id,
                ],
                'nested-1-level' => [
                    fn (): int => OldNested::from($oldNested, true)->child->id,
                    fn (): int => NewNested::from($newNested)->child->id,
                ],
                'deep-3-levels' => [
                    fn (): int => OldDeep::from($oldDeep, true)->child->child->id,
                    fn (): int => NewDeep::from($newDeep)->child->child->id,
                ],
                'backed-enum' => [
                    fn (): int => OldEnum::from($enum, true)->status === BenchStatus::Active ? 1 : 0,
                    fn (): int => NewEnum::from($enum)->status === BenchStatus::Active ? 1 : 0,
                ],
                'date-time' => [
                    fn (): int => OldDate::from($oldDate, true)->id,
                    fn (): int => NewDate::from($newDate)->id,
                ],
                'mixed-api-payload' => [
                    fn (): int => OldMixed::from($oldMixed, true)->child->id,
                    fn (): int => NewMixed::from($newMixed)->child->id,
                ],
                '1000-item-from-loop' => [
                    function () use ($rows): int {
                        $checksum = 0;
                        foreach ($rows as $row) {
                            $checksum += OldFlat::from($row)->id;
                        }
                        return $checksum;
                    },
                    function () use ($rows): int {
                        $checksum = 0;
                        foreach ($rows as $row) {
                            $checksum += NewFlat::from($row)->id;
                        }
                        return $checksum;
                    },
                ],
            ];

            printf("Construction (nanoseconds per operation)\n");
            printf("%-38s %12s %12s %9s %12s %12s\n", 'scenario', 'old p50', 'data p50', 'ratio', 'old p95', 'data p95');

            foreach ($construction as $name => [$old, $new]) {
                $divisor = $name === '1000-item-from-loop' ? 1_000 : 1;
                $operations = $divisor === 1 ? OPERATIONS : 60;
                $warmup = $divisor === 1 ? WARMUP : 10;
                $oldResult = measure($old, $operations, $warmup);
                $newResult = measure($new, $operations, $warmup);
                $oldResult = ['p50' => $oldResult['p50'] / $divisor, 'p95' => $oldResult['p95'] / $divisor];
                $newResult = ['p50' => $newResult['p50'] / $divisor, 'p95' => $newResult['p95'] / $divisor];
                printRow($name, $oldResult, $newResult);
            }

            $oldFlatObject = OldFlat::from($flat);
            $newFlatObject = NewFlat::from($flat);
            $oldWideObject = OldWide::from($wide);
            $newWideObject = NewWide::from($wide);
            $oldNestedObject = OldNested::from($oldNested, true);
            $newNestedObject = NewNested::from($newNested);
            $oldDeepObject = OldDeep::from($oldDeep, true);
            $newDeepObject = NewDeep::from($newDeep);
            $oldObjects = array_fill(0, 1_000, null);
            $newObjects = array_fill(0, 1_000, null);

            foreach (array_keys($oldObjects) as $index) {
                $oldObjects[$index] = OldFlat::from($flat);
                $newObjects[$index] = NewFlat::from($flat);
            }

            $transformation = [
                'flat-uncached' => [
                    fn (): int => $oldFlatObject->refresh()->toArray()['id'],
                    fn (): int => $newFlatObject->toArray()['id'],
                ],
                'wide-uncached' => [
                    fn (): int => $oldWideObject->refresh()->toArray()['twenty'],
                    fn (): int => $newWideObject->toArray()['twenty'],
                ],
                'nested-whole-tree' => [
                    function () use ($oldNestedObject): int {
                        $oldNestedObject->child->refresh();
                        return $oldNestedObject->refresh()->toArray()['child']['id'];
                    },
                    fn (): int => $newNestedObject->toArray()['child']['id'],
                ],
                'deep-whole-tree' => [
                    function () use ($oldDeepObject): int {
                        $oldDeepObject->child->child->refresh();
                        $oldDeepObject->child->refresh();
                        return $oldDeepObject->refresh()->toArray()['child']['child']['id'];
                    },
                    fn (): int => $newDeepObject->toArray()['child']['child']['id'],
                ],
                'json-encode-nested' => [
                    function () use ($oldNestedObject): int {
                        $oldNestedObject->child->refresh();
                        $oldNestedObject->refresh();
                        return strlen((string) json_encode($oldNestedObject, JSON_THROW_ON_ERROR));
                    },
                    fn (): int => strlen((string) json_encode($newNestedObject, JSON_THROW_ON_ERROR)),
                ],
                '1000-object-transform' => [
                    function () use ($oldObjects): int {
                        $checksum = 0;
                        foreach ($oldObjects as $object) {
                            $checksum += $object->refresh()->toArray()['id'];
                        }
                        return $checksum;
                    },
                    function () use ($newObjects): int {
                        $checksum = 0;
                        foreach ($newObjects as $object) {
                            $checksum += $object->toArray()['id'];
                        }
                        return $checksum;
                    },
                ],
                'property-read' => [
                    fn (): int => $oldFlatObject->id,
                    fn (): int => $newFlatObject->id,
                ],
            ];

            printf("\nTransformation (nanoseconds per operation)\n");
            printf("%-38s %12s %12s %9s %12s %12s\n", 'scenario', 'old p50', 'data p50', 'ratio', 'old p95', 'data p95');

            foreach ($transformation as $name => [$old, $new]) {
                $divisor = $name === '1000-object-transform' ? 1_000 : 1;
                $operations = $divisor === 1 ? OPERATIONS : 80;
                $warmup = $divisor === 1 ? WARMUP : 10;
                $oldResult = measure($old, $operations, $warmup);
                $newResult = measure($new, $operations, $warmup);
                $oldResult = ['p50' => $oldResult['p50'] / $divisor, 'p95' => $oldResult['p95'] / $divisor];
                $newResult = ['p50' => $newResult['p50'] / $divisor, 'p95' => $newResult['p95'] / $divisor];
                printRow($name, $oldResult, $newResult);
            }

            printf("\nRetained instance bytes\n");
            printf("%-38s %12.1f %12.1f\n", 'flat', retainedInstanceBytes(fn (int $id): OldFlat => OldFlat::from([...$flat, 'id' => $id])), retainedInstanceBytes(fn (int $id): NewFlat => NewFlat::from([...$flat, 'id' => $id])));
            printf("%-38s %12.1f %12.1f\n", 'wide', retainedInstanceBytes(fn (int $id): OldWide => OldWide::from([...$wide, 'one' => $id])), retainedInstanceBytes(fn (int $id): NewWide => NewWide::from([...$wide, 'one' => $id])));

            $repository = $application->make(DataClassRepository::class);
            $repository->get(NewWarm::class);
            gc_collect_cycles();
            $before = memory_get_usage(false);
            $repository->get(NewCold::class);
            $newMetadata = memory_get_usage(false) - $before;

            DataObject::flushState();
            gc_collect_cycles();
            $before = memory_get_usage(false);
            $oldObject = OldCold::from(['id' => 1, 'name' => 'cold', 'active' => true]);
            unset($oldObject);
            gc_collect_cycles();
            $oldMetadata = memory_get_usage(false) - $before;

            printf("\nRetained metadata bytes (one small class, warm services)\n");
            printf("%-38s %12d %12d\n", 'metadata', $oldMetadata, $newMetadata);
        });
    } finally {
        $application->terminate();
    }

    printf("\nFresh-process first-use (nanoseconds)\n");
    printf("%-38s %12s %12s\n", 'scenario', 'p50', 'p95');
    foreach (['old', 'data-first', 'data-class'] as $mode) {
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
            NewFlat::from($flat);
            $factory = NewFlat::factory();
            $context = $factory->get();
            $creator = $application->make(DataCreator::class);

            $operations = [
                'native-constructor' => fn (): int => (new NewFlat(1, 'Taylor', 'taylor@example.com', true, 9.5))->id,
                'base-data-from' => fn (): int => NewFlat::from($flat)->id,
                'prepared-factory-from' => fn (): int => $factory->from($flat)->id,
                'creator-with-context' => fn (): int => $creator->create(NewFlat::class, $context, $flat)->id,
                'factory-get-context' => fn (): int => $factory->get()->mapPropertyNames ? 1 : 0,
                'base-data-factory' => fn (): int => NewFlat::factory()->dataClass === NewFlat::class ? 1 : 0,
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
            $flat = NewFlat::from($flatPayload);
            $nested = NewNested::from($nestedPayload);
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
