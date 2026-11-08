<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use App\Member;
use App\MemberWithCustomKey;
use App\MemberWithNonIncrementingStringKey;
use App\MemberWithoutTimestampsAttribute;
use App\MemberWithoutTimestampsTable;
use App\UuidModel;
use Hypervel\PHPStan\Properties\ConnectionConfigResolver;
use Hypervel\PHPStan\Properties\MigrationCache;
use Hypervel\PHPStan\Properties\MigrationHelper;
use Hypervel\PHPStan\Properties\ModelCastHelper;
use Hypervel\PHPStan\Properties\ModelHelper;
use Hypervel\PHPStan\Properties\ModelPropertyHelper;
use Hypervel\PHPStan\Properties\Schema\DataTypeToPhpTypeConverter;
use Hypervel\PHPStan\Properties\SquashedMigrationHelper;
use Hypervel\PHPStan\Support\ConfigSourceResolver;
use Hypervel\Tests\PHPStan\Fixtures\OverridesConfig;
use ModelCastsParsing\UnknownCastsModel;
use ModelCastsParsing\VirtualCastModel;
use PDO;
use PHPStan\Analyser\DeclarationDependencyTracker;
use PHPStan\Analyser\FileAnalyser;
use PHPStan\Analyser\ResultCache\DirectoryResultCacheValueExtension;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\Analyser\ValueDependencyCollector;
use PHPStan\Collectors\Registry as CollectorRegistry;
use PHPStan\File\FileHelper;
use PHPStan\Parser\Parser;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\DirectRegistry as DirectRuleRegistry;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\StringType;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\Test;
use SplFileInfo;

use function sys_get_temp_dir;

class ModelPropertyHelperTest extends PHPStanTestCase
{
    use OverridesConfig;

    private ReflectionProvider $reflectionProvider;

    private Parser $parser;

    private FileHelper $fileHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reflectionProvider = $this->createReflectionProvider();
        $this->parser = self::getContainer()->getService('currentPhpVersionSimpleDirectParser');
        $this->fileHelper = self::getContainer()->getByType(FileHelper::class);
    }

    #[Test]
    public function itUsesCustomTableNameFromTablePhpAttributeWhenCheckingDatabaseProperty(): void
    {
        $modelPropertyHelper = $this->buildModelPropertyHelper(
            [__DIR__ . '/data/basic_migration'],
        );

        $classReflection = $this->reflectionProvider->getClass(Member::class);

        // The model uses #[Table(name: 'users')], not the default 'members' derived from
        // the class name. The fix ensures initializeModelAttributes() is called so the correct
        // table is resolved and columns from the users table are found.
        self::assertTrue($modelPropertyHelper->hasDatabaseProperty($classReflection, 'email')->yes());
        self::assertTrue($modelPropertyHelper->hasDatabaseProperty($classReflection, 'nonexistent_column')->no());
    }

    #[Test]
    public function itUsesCustomKeyMetadataFromTablePhpAttribute(): void
    {
        $modelPropertyHelper = $this->buildModelPropertyHelper([__DIR__ . '/data/basic_migration']);
        $classReflection = $this->reflectionProvider->getClass(MemberWithCustomKey::class);

        self::assertTrue($modelPropertyHelper->hasDatabaseProperty($classReflection, 'uuid')->yes());
        self::assertInstanceOf(
            StringType::class,
            $modelPropertyHelper->getDatabaseProperty($classReflection, 'uuid')->getReadableType(),
        );
    }

    #[Test]
    public function itAppliesNonIncrementingStringKeyMetadata(): void
    {
        $modelPropertyHelper = $this->buildModelPropertyHelper([__DIR__ . '/data/basic_migration']);
        $classReflection = $this->reflectionProvider->getClass(MemberWithNonIncrementingStringKey::class);

        self::assertInstanceOf(
            StringType::class,
            $modelPropertyHelper->getDatabaseProperty($classReflection, 'id')->getReadableType(),
        );
    }

    #[Test]
    public function itAppliesAttributesThatDisableTimestamps(): void
    {
        $modelPropertyHelper = $this->buildModelPropertyHelper([__DIR__ . '/data/basic_migration']);

        foreach ([MemberWithoutTimestampsTable::class, MemberWithoutTimestampsAttribute::class] as $modelClass) {
            $classReflection = $this->reflectionProvider->getClass($modelClass);

            self::assertTrue($modelPropertyHelper->hasDatabaseProperty($classReflection, 'created_at')->yes());
            self::assertInstanceOf(
                StringType::class,
                TypeCombinator::removeNull(
                    $modelPropertyHelper->getDatabaseProperty($classReflection, 'created_at')->getReadableType(),
                ),
            );
        }
    }

    /**
     * Attributes are named as the connection's ATTR_CASE fetches the columns, though the written name still names a column.
     */
    #[Test]
    public function itNamesAttributesAsTheConnectionFetchesColumns(): void
    {
        $this->withConfig(['database.connections.' . config('database.default') . '.options' => [PDO::ATTR_CASE => PDO::CASE_UPPER]], function (): void {
            $modelPropertyHelper = $this->buildModelPropertyHelper(
                [__DIR__ . '/data/basic_migration'],
                connectionConfigResolver: new ConnectionConfigResolver,
            );
            $member = $this->reflectionProvider->getClass(Member::class);

            self::assertTrue($modelPropertyHelper->hasDatabaseProperty($member, 'EMAIL')->yes());
            self::assertSame('string', $modelPropertyHelper->getDatabaseProperty($member, 'EMAIL')->getReadableType()->describe(VerbosityLevel::precise()));
            self::assertSame('null', $modelPropertyHelper->getDatabaseProperty($member, 'email')->getReadableType()->describe(VerbosityLevel::precise()));
        });
    }

    /**
     * The key type comes from the memoized instance, so it does not depend on whether casts were read first.
     */
    #[Test]
    public function itReadsAUniqueStringKeyBeforeAnyCasts(): void
    {
        $modelPropertyHelper = $this->buildModelPropertyHelper([]);

        self::assertSame(
            'string',
            $modelPropertyHelper->getDatabaseProperty($this->reflectionProvider->getClass(UuidModel::class), 'id')->getReadableType()->describe(VerbosityLevel::precise()),
        );
    }

    /**
     * Unlike upstream, which reported the columns of unknown tables as missing, a model whose schema is
     * not known has its properties assumed.
     */
    #[Test]
    public function itLoadsMigrationsOnlyOnceWhenNoTablesAreFound(): void
    {
        $migrationHelper = new class($this->parser, ['foobar'], $this->fileHelper, false, $this->reflectionProvider, self::getContainer()->getByType(InitializerExprTypeResolver::class), self::getContainer()->getByType(ConnectionConfigResolver::class)) extends MigrationHelper {
            public int $migrationFileLookups = 0;

            /** @return SplFileInfo[] */
            public function getMigrationFiles(): array
            {
                ++$this->migrationFileLookups;

                return parent::getMigrationFiles();
            }
        };

        $modelPropertyHelper = $this->buildModelPropertyHelper([], $migrationHelper, ['foobar']);
        $member = $this->reflectionProvider->getClass(Member::class);

        self::assertTrue($modelPropertyHelper->hasDatabaseProperty($member, 'email')->maybe());
        $lookupsAfterFirstLoad = $migrationHelper->migrationFileLookups;

        // An empty result still counts as loaded, so later lookups must not scan again.
        self::assertTrue($modelPropertyHelper->hasDatabaseProperty($member, 'name')->maybe());
        self::assertTrue($modelPropertyHelper->hasDatabaseProperty($this->reflectionProvider->getClass(MemberWithCustomKey::class), 'title')->maybe());
        self::assertSame($lookupsAfterFirstLoad, $migrationHelper->migrationFileLookups);
    }

    /**
     * A cast key is an attribute without a column, but casts() that cannot be read do not make every name one.
     */
    #[Test]
    public function itTreatsCastKeysAsAttributes(): void
    {
        $modelPropertyHelper = self::getContainer()->getByType(ModelPropertyHelper::class);

        self::assertTrue($modelPropertyHelper->hasDatabaseProperty($this->reflectionProvider->getClass(VirtualCastModel::class), 'dimensions')->yes());
        self::assertTrue($modelPropertyHelper->hasDatabaseProperty($this->reflectionProvider->getClass(UnknownCastsModel::class), 'misspelled')->no());
    }

    #[Test]
    public function itTracksTheSchemaDirectoriesForFilesUsingAModel(): void
    {
        $container = self::getContainer();
        $collector = $container->getByType(ValueDependencyCollector::class);

        $expected = [];

        foreach ($container->getByType(MigrationHelper::class)->getMigrationPaths() as $directory => $pattern) {
            self::assertSame('*_*.php', $pattern);
            $expected[] = ValueDependencyCollector::getId(DirectoryResultCacheValueExtension::class, $collector->getDirectoryKey($directory, $pattern));
        }

        foreach ($container->getByType(SquashedMigrationHelper::class)->getSchemaDirectories() as $directory) {
            $expected[] = ValueDependencyCollector::getId(DirectoryResultCacheValueExtension::class, $collector->getDirectoryKey($directory, '*'));
        }

        // The application's migration and schema directories, and the workbench migrations a provider loads.
        self::assertCount(3, $expected);

        $usingModel = $this->analyseValueDependencies(__DIR__ . '/data/model-schema-dependency.php');

        foreach ($expected as $id) {
            self::assertContains($id, $usingModel);
        }

        self::assertSame([], $this->analyseValueDependencies(__DIR__ . '/data/no-model-schema-dependency.php'));
    }

    /** @return list<string> the ids of the values the analysis of the file depends on */
    private function analyseValueDependencies(string $file): array
    {
        $file = $this->fileHelper->normalizePath($file);
        $result = self::getContainer()->getByType(FileAnalyser::class)->analyseFile(
            $file,
            [$file => true],
            new DirectRuleRegistry([]),
            new CollectorRegistry([]),
            null,
        );

        self::assertSame([], $result->getErrors());

        return $result->getValueDependencies()['dependents'][$file]['analysis'];
    }

    /**
     * @param string[] $migrationPaths
     * @param string[] $schemaPaths
     */
    private function buildModelPropertyHelper(
        array $migrationPaths,
        ?MigrationHelper $migrationHelper = null,
        array $schemaPaths = [],
        ?ConnectionConfigResolver $connectionConfigResolver = null,
    ): ModelPropertyHelper {
        $connectionConfigResolver ??= self::getContainer()->getByType(ConnectionConfigResolver::class);

        $migrationHelper ??= new MigrationHelper(
            $this->parser,
            $migrationPaths,
            $this->fileHelper,
            false,
            $this->reflectionProvider,
            self::getContainer()->getByType(InitializerExprTypeResolver::class),
            $connectionConfigResolver,
        );

        $squashedMigrationHelper = new SquashedMigrationHelper(
            $schemaPaths,
            $this->fileHelper,
            $connectionConfigResolver,
            false,
        );

        $modelHelper = self::getContainer()->getByType(ModelHelper::class);

        $modelCastHelper = new ModelCastHelper(
            $this->reflectionProvider,
            self::getContainer()->getService('cachedRichParser'),
            false,
            self::getContainer()->getByType(ScopeFactory::class),
            self::getContainer()->getByType(DeclarationDependencyTracker::class),
            $modelHelper,
        );

        return new ModelPropertyHelper(
            self::getContainer()->getByType(TypeStringResolver::class),
            $migrationHelper,
            $squashedMigrationHelper,
            $modelCastHelper,
            $modelHelper,
            new MigrationCache($connectionConfigResolver, sys_get_temp_dir(), false),
            self::getContainer()->getByType(DeclarationDependencyTracker::class),
            $this->reflectionProvider,
            $connectionConfigResolver,
            self::getContainer()->getByType(DataTypeToPhpTypeConverter::class),
            self::getContainer()->getByType(ConfigSourceResolver::class),
        );
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
