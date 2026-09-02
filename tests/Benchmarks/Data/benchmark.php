#!/usr/bin/env php
<?php

declare(strict_types=1);

use Hypervel\Container\Attributes\Config;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Data\Attributes\AutoLazy;
use Hypervel\Data\Attributes\LoadRelation;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Attributes\PropertyForMorph;
use Hypervel\Data\Attributes\Validation\Email;
use Hypervel\Data\Attributes\WithCast;
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Contracts\PropertyMorphableData;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Lazy;
use Hypervel\Data\Support\Creation\ConstructionState;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataClassRepository;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Factories\DataClassFactory;
use Hypervel\Database\Connection;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\ClassMetadataCache;
use Hypervel\Support\LazyCollection;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Application as TestbenchApplication;

use function Hypervel\Coroutine\run;

require dirname(__DIR__, 3) . '/tests/bootstrap.php';

Bootstrapper::bootstrap();

class DataBenchmarkAddress extends Data
{
    public function __construct(
        #[MapInputName('line_one')]
        public string $lineOne,
        public string $city,
        #[MapInputName('country_code')]
        public string $countryCode,
    ) {
    }
}

class DataBenchmarkUser extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public bool $active,
        public ?DataBenchmarkAddress $address,
    ) {
    }
}

class DataBenchmarkColdData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $active,
    ) {
    }
}

class DataBenchmarkLeaf extends Data
{
    public function __construct(
        public int $id,
        public string $code,
        public string $label,
        public bool $enabled,
    ) {
    }
}

class DataBenchmarkLevelThree extends Data
{
    public function __construct(
        public DataBenchmarkLeaf $child,
        public string $alpha,
        public string $beta,
        public string $gamma,
        public string $delta,
    ) {
    }
}

class DataBenchmarkLevelTwo extends Data
{
    public function __construct(
        public DataBenchmarkLevelThree $child,
        public int $one,
        public int $two,
        public int $three,
        public int $four,
    ) {
    }
}

class DataBenchmarkRoot extends Data
{
    public function __construct(
        public DataBenchmarkLevelTwo $child,
        public float $amount,
        public string $status,
        public bool $active,
        public ?string $note,
    ) {
    }
}

class DataBenchmarkValidatedItem extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        #[Email]
        public string $email,
        public bool $active,
    ) {
    }
}

class DataBenchmarkLazyItem extends Data
{
    public function __construct(
        public int $id,
        #[AutoLazy]
        public DataBenchmarkAddress|Lazy $address,
    ) {
    }
}

class DataBenchmarkPrefixCast implements Cast
{
    /**
     * Prefix one benchmark value.
     */
    public function cast(
        DataProperty $property,
        mixed $value,
        ConstructionState $state,
        CreationContext $context,
    ): string {
        return 'cast:' . $value;
    }
}

abstract class DataBenchmarkShape extends Data implements PropertyMorphableData
{
    public function __construct(
        #[PropertyForMorph]
        public string $type,
    ) {
    }

    /**
     * Resolve the concrete benchmark shape.
     */
    public static function morph(array $properties): ?string
    {
        return ($properties['type'] ?? null) === 'circle'
            ? DataBenchmarkCircle::class
            : null;
    }
}

class DataBenchmarkCircle extends DataBenchmarkShape
{
    public function __construct(
        string $type,
        public float $radius,
    ) {
        parent::__construct($type);
    }
}

class DataBenchmarkExtensionData extends Data
{
    public function __construct(
        #[Config('app.name')]
        public string $applicationName,
        #[MapInputName('external_id')]
        public int $id,
        #[WithCast(DataBenchmarkPrefixCast::class)]
        public string $label,
        public DataBenchmarkShape $shape,
    ) {
    }
}

class DataBenchmarkNamedFactoryDependency
{
    public function __construct(public readonly string $suffix = 'dependency')
    {
    }
}

class DataBenchmarkDirectFactoryData extends Data
{
    public function __construct(public int $id)
    {
    }

    /**
     * Create benchmark data from one identifier.
     */
    public static function fromIdentifier(int $identifier): self
    {
        return new self($identifier);
    }
}

class DataBenchmarkContainerFactoryData extends Data
{
    public function __construct(public string $value)
    {
    }

    /**
     * Create benchmark data through the container invocation path.
     */
    public static function fromIdentifier(
        int $identifier,
        DataBenchmarkNamedFactoryDependency $dependency,
        CreationContext $context,
    ): self {
        return new self($identifier . ':' . $dependency->suffix . ':' . $context->dataClass);
    }
}

class DataBenchmarkProfile extends Model
{
    protected ?string $table = 'data_benchmark_profiles';

    public bool $timestamps = false;
}

class DataBenchmarkUserModel extends Model
{
    protected ?string $table = 'data_benchmark_users';

    public bool $timestamps = false;

    /**
     * Get the benchmark user's profile.
     */
    public function profile(): HasOne
    {
        return $this->hasOne(DataBenchmarkProfile::class, 'user_id');
    }
}

class DataBenchmarkProfileData extends Data
{
    public function __construct(
        public int $userId,
        public string $bio,
    ) {
    }
}

class DataBenchmarkModelData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        #[LoadRelation]
        public DataBenchmarkProfileData $profile,
    ) {
    }
}

class DataBenchmark
{
    private int $queryCount = 0;

    /**
     * Create a new data benchmark.
     *
     * @param EloquentCollection<int, DataBenchmarkUserModel> $loadedModels
     */
    public function __construct(
        private readonly DataClassFactory $dataClassFactory,
        private readonly DataClassRepository $dataClasses,
        private readonly Connection $connection,
        private readonly EloquentCollection $loadedModels,
        private readonly int $operations,
        private readonly int $samples,
        private readonly int $warmup,
    ) {
        $this->connection->listen(function (QueryExecuted $event): void {
            ++$this->queryCount;
        });
    }

    /**
     * Run every benchmark scenario.
     *
     * @return array{environment: array<string, mixed>, results: list<array<string, float|int|string>>}
     */
    public function execute(): array
    {
        $environment = $this->environment();
        $flatPayload = [
            'id' => 1001,
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'active' => true,
            'address' => null,
        ];
        $nestedPayload = [
            ...$flatPayload,
            'address' => [
                'line_one' => '1 Framework Way',
                'city' => 'Little Rock',
                'country_code' => 'US',
            ],
        ];
        $deepPayload = [
            'child' => [
                'child' => [
                    'child' => [
                        'id' => 1001,
                        'code' => 'sdk-1001',
                        'label' => 'Benchmark leaf',
                        'enabled' => true,
                    ],
                    'alpha' => 'a',
                    'beta' => 'b',
                    'gamma' => 'c',
                    'delta' => 'd',
                ],
                'one' => 1,
                'two' => 2,
                'three' => 3,
                'four' => 4,
            ],
            'amount' => 125.50,
            'status' => 'active',
            'active' => true,
            'note' => null,
        ];
        $collectionRows = $this->userRows(1_000);
        $validationRows = $this->userRows(5_000);
        $lazyRows = array_map(
            static fn (array $row): array => [
                'id' => $row['id'],
                'address' => [
                    'line_one' => $row['id'] . ' Framework Way',
                    'city' => 'Little Rock',
                    'country_code' => 'US',
                ],
            ],
            $collectionRows,
        );
        $factoryIdentifiers = range(1, 1_000);
        $simpleData = DataBenchmarkUser::from($flatPayload);
        $nestedData = DataBenchmarkUser::from($nestedPayload);
        $lazyTransformData = DataBenchmarkLazyItem::from($lazyRows[0])
            ->includePermanently('address')
            ->onlyPermanently('id', 'address.lineOne');

        $results = [
            $this->benchmarkOnce(
                'data-from-cold-first-use',
                fn (): int => DataBenchmarkColdData::from([
                    'id' => 1,
                    'name' => 'Cold',
                    'active' => true,
                ])->id,
            ),
        ];

        $standardOperations = $this->operations;
        $nestedOperations = $this->scaledOperations(10);
        $collectionOperations = $this->scaledOperations(100);
        $validationOperations = $this->scaledOperations(1_000);
        $standardWarmup = $this->warmup;
        $nestedWarmup = $this->scaledWarmup(10);
        $collectionWarmup = $this->scaledWarmup(100);
        $validationWarmup = $this->scaledWarmup(1_000);

        $scenarios = [
            'native-constructor' => [
                $standardOperations,
                $standardWarmup,
                fn (): int => (new DataBenchmarkUser(
                    $flatPayload['id'],
                    $flatPayload['name'],
                    $flatPayload['email'],
                    $flatPayload['active'],
                    null,
                ))->id,
            ],
            'manual-flat-mapper' => [
                $standardOperations,
                $standardWarmup,
                fn (): int => $this->mapUser($flatPayload)->id,
            ],
            'data-from-flat-warm' => [
                $standardOperations,
                $standardWarmup,
                fn (): int => DataBenchmarkUser::from($flatPayload)->id,
            ],
            'manual-nested-mapper' => [
                $standardOperations,
                $standardWarmup,
                fn (): int => $this->mapUser($nestedPayload)->address?->countryCode === 'US' ? 1 : 0,
            ],
            'data-from-nested-warm' => [
                $standardOperations,
                $standardWarmup,
                fn (): int => DataBenchmarkUser::from($nestedPayload)->address?->countryCode === 'US' ? 1 : 0,
            ],
            'data-from-deep-wide' => [
                $nestedOperations,
                $nestedWarmup,
                fn (): int => DataBenchmarkRoot::from($deepPayload)->child->child->child->id,
            ],
            'collect-1000-eager' => [
                $collectionOperations,
                $collectionWarmup,
                fn (): int => DataBenchmarkUser::collect($collectionRows, DataCollection::class)->count(),
            ],
            'collect-1000-lazy-traversal' => [
                $collectionOperations,
                $collectionWarmup,
                function () use ($collectionRows): int {
                    $items = DataBenchmarkUser::collect(LazyCollection::make(
                        static fn (): iterable => yield from $collectionRows,
                    ));
                    $checksum = 0;

                    foreach ($items as $item) {
                        $checksum += $item->id;
                    }

                    return $checksum;
                },
            ],
            'collect-1000-auto-lazy' => [
                $collectionOperations,
                $collectionWarmup,
                fn (): int => DataBenchmarkLazyItem::collect($lazyRows, DataCollection::class)->count(),
            ],
            'validate-5000-nested' => [
                $validationOperations,
                $validationWarmup,
                fn (): int => DataBenchmarkValidatedItem::factory()
                    ->alwaysValidate()
                    ->collect($validationRows, DataCollection::class)
                    ->count(),
            ],
            'factory-direct' => [
                $standardOperations,
                $standardWarmup,
                fn (): int => DataBenchmarkDirectFactoryData::from(1001)->id,
            ],
            'factory-container' => [
                $standardOperations,
                $standardWarmup,
                fn (): int => strlen(DataBenchmarkContainerFactoryData::from(1001)->value),
            ],
            'factory-direct-collection' => [
                $collectionOperations,
                $collectionWarmup,
                fn (): int => DataBenchmarkDirectFactoryData::collect(
                    $factoryIdentifiers,
                    DataCollection::class,
                )->count(),
            ],
            'factory-container-collection' => [
                $collectionOperations,
                $collectionWarmup,
                fn (): int => DataBenchmarkContainerFactoryData::collect(
                    $factoryIdentifiers,
                    DataCollection::class,
                )->count(),
            ],
            'mapped-cast-morph-injection' => [
                $nestedOperations,
                $nestedWarmup,
                fn (): int => DataBenchmarkExtensionData::from([
                    'external_id' => 1001,
                    'label' => 'benchmark',
                    'shape' => ['type' => 'circle', 'radius' => 2.5],
                ])->id,
            ],
            'transform-simple' => [
                $standardOperations,
                $standardWarmup,
                fn (): int => $simpleData->toArray()['id'],
            ],
            'transform-nested' => [
                $standardOperations,
                $standardWarmup,
                fn (): int => $nestedData->toArray()['address']['countryCode'] === 'US' ? 1 : 0,
            ],
            'transform-lazy-partial' => [
                $nestedOperations,
                $nestedWarmup,
                fn (): int => $lazyTransformData->toArray()['id'],
            ],
            'metadata-analysis' => [
                $nestedOperations,
                $nestedWarmup,
                fn (): int => count($this->dataClassFactory
                    ->build(ClassMetadataCache::reflectClass(DataBenchmarkRoot::class))
                    ->properties),
            ],
            'metadata-repository-hit' => [
                $standardOperations,
                $standardWarmup,
                fn (): int => count($this->dataClasses->get(DataBenchmarkRoot::class)->properties),
            ],
            'eloquent-loaded-relations' => [
                $collectionOperations,
                $collectionWarmup,
                fn (): int => DataBenchmarkModelData::collect(
                    $this->loadedModels,
                    DataCollection::class,
                )->count(),
            ],
            'eloquent-load-missing' => [
                $collectionOperations,
                $collectionWarmup,
                fn (): int => DataBenchmarkModelData::collect(
                    $this->freshUnloadedModels(),
                    DataCollection::class,
                )->count(),
            ],
        ];

        printf("Hypervel data benchmark\n");

        foreach ($environment as $key => $value) {
            printf("%s: %s\n", $key, is_array($value) ? implode(', ', $value) : (string) $value);
        }

        printf(
            "\n%-34s %10s %14s %14s %14s %12s %14s\n",
            'scenario',
            'operations',
            'operations/s',
            'p50 ns/op',
            'p95 ns/op',
            'queries/op',
            'peak delta',
        );

        foreach ($scenarios as $name => [$operations, $warmup, $operation]) {
            $result = $this->benchmark($name, $operation, $operations, $warmup);
            $results[] = $result;
        }

        foreach ($results as $result) {
            $this->printResult($result);
        }

        return compact('environment', 'results');
    }

    /**
     * Benchmark one operation over repeated samples.
     *
     * @param Closure(): int $operation
     * @return array<string, float|int|string>
     */
    private function benchmark(
        string $name,
        Closure $operation,
        int $operations,
        int $warmup,
    ): array {
        $checksum = 0;

        for ($iteration = 0; $iteration < $warmup; ++$iteration) {
            $checksum += $operation();
        }

        $nanosecondsPerOperation = [];
        $peakMemory = 0;
        $queryCount = $this->queryCount;

        for ($sample = 0; $sample < $this->samples; ++$sample) {
            memory_reset_peak_usage();
            $baselineMemory = memory_get_usage(true);
            $startedAt = hrtime(true);

            for ($operationIndex = 0; $operationIndex < $operations; ++$operationIndex) {
                $checksum += $operation();
            }

            $elapsedNanoseconds = hrtime(true) - $startedAt;
            $nanosecondsPerOperation[] = $elapsedNanoseconds / $operations;
            $peakMemory = max($peakMemory, memory_get_peak_usage(true) - $baselineMemory);
        }

        if ($checksum === 0) {
            throw new LogicException("Benchmark scenario [{$name}] produced an empty checksum.");
        }

        sort($nanosecondsPerOperation, SORT_NUMERIC);
        $median = $this->percentile($nanosecondsPerOperation, 0.50);

        return [
            'scenario' => $name,
            'operations' => $operations,
            'samples' => $this->samples,
            'operations_per_second' => 1_000_000_000 / $median,
            'p50_nanoseconds' => $median,
            'p95_nanoseconds' => $this->percentile($nanosecondsPerOperation, 0.95),
            'queries_per_operation' => ($this->queryCount - $queryCount) / ($operations * $this->samples),
            'peak_memory_bytes' => $peakMemory,
        ];
    }

    /**
     * Benchmark one worker-first-use operation.
     *
     * @param Closure(): int $operation
     * @return array<string, float|int|string>
     */
    private function benchmarkOnce(string $name, Closure $operation): array
    {
        memory_reset_peak_usage();
        $baselineMemory = memory_get_usage(true);
        $queryCount = $this->queryCount;
        $startedAt = hrtime(true);
        $checksum = $operation();
        $elapsedNanoseconds = hrtime(true) - $startedAt;

        if ($checksum === 0) {
            throw new LogicException("Benchmark scenario [{$name}] produced an empty checksum.");
        }

        return [
            'scenario' => $name,
            'operations' => 1,
            'samples' => 1,
            'operations_per_second' => 1_000_000_000 / $elapsedNanoseconds,
            'p50_nanoseconds' => $elapsedNanoseconds,
            'p95_nanoseconds' => $elapsedNanoseconds,
            'queries_per_operation' => $this->queryCount - $queryCount,
            'peak_memory_bytes' => memory_get_peak_usage(true) - $baselineMemory,
        ];
    }

    /**
     * Print one benchmark result.
     *
     * @param array<string, float|int|string> $result
     */
    private function printResult(array $result): void
    {
        printf(
            "%-34s %10d %14.0f %14.2f %14.2f %12.3f %14d\n",
            $result['scenario'],
            $result['operations'],
            $result['operations_per_second'],
            $result['p50_nanoseconds'],
            $result['p95_nanoseconds'],
            $result['queries_per_operation'],
            $result['peak_memory_bytes'],
        );
    }

    /**
     * Map one representative SDK payload without reflection.
     */
    private function mapUser(array $payload): DataBenchmarkUser
    {
        $address = $payload['address'] === null
            ? null
            : new DataBenchmarkAddress(
                $payload['address']['line_one'],
                $payload['address']['city'],
                $payload['address']['country_code'],
            );

        return new DataBenchmarkUser(
            $payload['id'],
            $payload['name'],
            $payload['email'],
            $payload['active'],
            $address,
        );
    }

    /**
     * Build representative keyed API rows.
     *
     * @return list<array{id: int, name: string, email: string, active: bool, address: null}>
     */
    private function userRows(int $count): array
    {
        $rows = [];

        for ($index = 1; $index <= $count; ++$index) {
            $rows[] = [
                'id' => $index,
                'name' => 'User ' . $index,
                'email' => 'user' . $index . '@example.com',
                'active' => true,
                'address' => null,
            ];
        }

        return $rows;
    }

    /**
     * Create fresh unloaded model instances for one relation-loading operation.
     *
     * @return EloquentCollection<int, DataBenchmarkUserModel>
     */
    private function freshUnloadedModels(): EloquentCollection
    {
        return new EloquentCollection($this->loadedModels->map(
            static fn (DataBenchmarkUserModel $model): DataBenchmarkUserModel => $model->newFromBuilder(
                $model->getAttributes(),
                $model->getConnectionName(),
            ),
        ));
    }

    /**
     * Scale expensive scenarios while retaining at least one measured operation.
     */
    private function scaledOperations(int $divisor): int
    {
        return max(1, intdiv($this->operations, $divisor));
    }

    /**
     * Scale warmup work consistently with the measured scenario.
     */
    private function scaledWarmup(int $divisor): int
    {
        return $this->warmup === 0 ? 0 : max(1, intdiv($this->warmup, $divisor));
    }

    /**
     * Return the nearest-rank percentile from sorted samples.
     *
     * @param list<float> $samples
     */
    private function percentile(array $samples, float $percentile): float
    {
        $index = (int) ceil(count($samples) * $percentile) - 1;

        return $samples[max(0, min(count($samples) - 1, $index))];
    }

    /**
     * Return the reproducibility inputs for this run.
     *
     * @return array<string, mixed>
     */
    private function environment(): array
    {
        $commit = trim((string) shell_exec('git rev-parse HEAD 2>/dev/null'));

        return [
            'timestamp' => gmdate(DATE_ATOM),
            'commit' => $commit !== '' ? $commit : 'unknown',
            'php' => PHP_VERSION,
            'os' => php_uname(),
            'extensions' => get_loaded_extensions(),
            'opcache_enabled' => ini_get('opcache.enable_cli') ?: '0',
            'jit' => ini_get('opcache.jit') ?: 'disabled',
            'operations_per_sample' => $this->operations,
            'samples' => $this->samples,
            'warmup_operations' => $this->warmup,
            'collection_items' => 1_000,
            'validation_items' => 5_000,
            'database_driver' => $this->connection->getDriverName(),
        ];
    }
}

/**
 * Run the benchmark CLI.
 */
function main(): int
{
    $options = getopt('', ['operations:', 'samples:', 'warmup:', 'json:', 'csv:', 'help']);

    if ($options === false) {
        fwrite(STDERR, "Unable to parse benchmark options.\n");

        return 1;
    }

    if (array_key_exists('help', $options)) {
        printUsage();

        return 0;
    }

    try {
        $operations = parseIntegerOption($options, 'operations', 20_000, 1, 1_000_000);
        $samples = parseIntegerOption($options, 'samples', 7, 1, 100);
        $warmup = parseIntegerOption($options, 'warmup', 1_000, 0, 100_000);
        $databasePath = tempnam(sys_get_temp_dir(), 'hypervel-data-benchmark-');

        if ($databasePath === false) {
            throw new RuntimeException('Unable to create the benchmark database.');
        }

        $application = null;
        try {
            $application = TestbenchApplication::create(
                options: ['load_environment_variables' => false],
            );
            $application->register(DataServiceProvider::class);

            $config = $application->make(Repository::class);
            $config->set('database.default', 'sqlite');
            $config->set('database.connections.sqlite', [
                'driver' => 'sqlite',
                'url' => null,
                'database' => $databasePath,
                'prefix' => '',
                'prefix_indexes' => null,
                'foreign_key_constraints' => true,
                'busy_timeout' => null,
                'journal_mode' => null,
                'synchronous' => null,
                'transaction_mode' => 'DEFERRED',
                'pragmas' => [],
            ]);

            $connection = $application->make(DatabaseManager::class)->connection();

            if (! $connection instanceof Connection) {
                throw new RuntimeException('The Data benchmark requires a Hypervel database connection.');
            }

            $report = null;
            $executionException = null;

            $completed = run(function () use (
                $application,
                $connection,
                $operations,
                $samples,
                $warmup,
                &$executionException,
                &$report,
            ): void {
                try {
                    $loadedModels = prepareDataBenchmarkDatabase($connection);
                    $report = (new DataBenchmark(
                        $application->make(DataClassFactory::class),
                        $application->make(DataClassRepository::class),
                        $connection,
                        $loadedModels,
                        $operations,
                        $samples,
                        $warmup,
                    ))->execute();
                } catch (Throwable $throwable) {
                    $executionException = $throwable;
                }
            });

            if ($executionException !== null) {
                throw $executionException;
            }

            if (! $completed || $report === null) {
                throw new RuntimeException('The benchmark coroutine did not complete.');
            }

            if (array_key_exists('json', $options)) {
                writeJsonReport($options['json'], $report);
            }

            if (array_key_exists('csv', $options)) {
                writeCsvReport($options['csv'], $report['results']);
            }
        } finally {
            $application?->terminate();

            if (is_file($databasePath)) {
                unlink($databasePath);
            }
        }
    } catch (Throwable $throwable) {
        fwrite(STDERR, sprintf("Benchmark failed: %s: %s\n", $throwable::class, $throwable->getMessage()));

        return 1;
    }

    return 0;
}

/**
 * Build the benchmark schema and return models with their relation preloaded.
 *
 * @return EloquentCollection<int, DataBenchmarkUserModel>
 */
function prepareDataBenchmarkDatabase(Connection $connection): EloquentCollection
{
    $schema = $connection->getSchemaBuilder();
    $schema->dropIfExists('data_benchmark_profiles');
    $schema->dropIfExists('data_benchmark_users');
    $schema->create('data_benchmark_users', function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->string('name');
    });
    $schema->create('data_benchmark_profiles', function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->integer('user_id')->index();
        $table->string('bio');
    });

    $users = [];
    $profiles = [];

    for ($identifier = 1; $identifier <= 1_000; ++$identifier) {
        $users[] = [
            'id' => $identifier,
            'name' => 'User ' . $identifier,
        ];
        $profiles[] = [
            'id' => $identifier,
            'user_id' => $identifier,
            'bio' => 'Profile ' . $identifier,
        ];
    }

    foreach (array_chunk($users, 200) as $chunk) {
        $connection->table('data_benchmark_users')->insert($chunk);
    }

    foreach (array_chunk($profiles, 200) as $chunk) {
        $connection->table('data_benchmark_profiles')->insert($chunk);
    }

    return DataBenchmarkUserModel::query()
        ->with('profile')
        ->orderBy('id')
        ->get();
}

/**
 * Parse and validate one integer option.
 *
 * @param array<string, array<int, string>|false|string> $options
 */
function parseIntegerOption(array $options, string $name, int $default, int $minimum, int $maximum): int
{
    $value = $options[$name] ?? (string) $default;

    if (! is_string($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
        throw new InvalidArgumentException("--{$name} must be an integer.");
    }

    $value = (int) $value;

    if ($value < $minimum || $value > $maximum) {
        throw new InvalidArgumentException("--{$name} must be between {$minimum} and {$maximum}.");
    }

    return $value;
}

/**
 * Write one JSON report.
 *
 * @param array<string, mixed> $report
 */
function writeJsonReport(mixed $path, array $report): void
{
    if (! is_string($path) || $path === '') {
        throw new InvalidArgumentException('--json must be a non-empty path.');
    }

    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
}

/**
 * Write one CSV report.
 *
 * @param list<array<string, float|int|string>> $results
 */
function writeCsvReport(mixed $path, array $results): void
{
    if (! is_string($path) || $path === '') {
        throw new InvalidArgumentException('--csv must be a non-empty path.');
    }

    $stream = fopen($path, 'wb');

    if ($stream === false) {
        throw new RuntimeException("Unable to open CSV report [{$path}].");
    }

    try {
        fputcsv($stream, array_keys($results[0]));

        foreach ($results as $result) {
            fputcsv($stream, $result);
        }
    } finally {
        fclose($stream);
    }
}

/**
 * Print command usage.
 */
function printUsage(): void
{
    echo <<<'TEXT'
Usage: php tests/Benchmarks/Data/benchmark.php [options]

Options:
  --operations=COUNT    Operations measured per sample (default: 20000)
  --samples=COUNT       Number of measured samples (default: 7)
  --warmup=COUNT        Unmeasured warmup operations (default: 1000)
  --json=PATH           Write the complete report as JSON
  --csv=PATH            Write scenario results as CSV
  --help                Show this help

TEXT;
}

exit(main());
