<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use App\Casts\BackedEnumeration;
use App\MemberWithCustomKey;
use Hypervel\Database\Eloquent\Casts\AsEnumCollection;
use Hypervel\PHPStan\Properties\ModelCastHelper;
use Hypervel\PHPStan\Properties\ModelHelper;
use PHPStan\Analyser\DeclarationDependencyTracker;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\MixedType;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\Test;

class ModelCastHelperTest extends PHPStanTestCase
{
    private ReflectionProvider $reflectionProvider;

    private Parser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reflectionProvider = $this->createReflectionProvider();
        $this->parser = self::getContainer()->getService('cachedRichParser');
    }

    #[Test]
    public function itUsesCustomPrimaryKeyFromTablePhpAttributeWhenResolvingCasts(): void
    {
        $modelCastHelper = new ModelCastHelper(
            $this->reflectionProvider,
            $this->parser,
            false,
            self::getContainer()->getByType(ScopeFactory::class),
            self::getContainer()->getByType(DeclarationDependencyTracker::class),
            self::getContainer()->getByType(ModelHelper::class),
        );

        $classReflection = $this->reflectionProvider->getClass(MemberWithCustomKey::class);

        // The model uses #[Table(key: 'uuid', keyType: 'string')], so getCasts() returns
        // ['uuid' => 'string']. Without initializeModelAttributes(), primaryKey stays 'id'
        // and the cast for 'uuid' is never registered.
        self::assertSame('string', $modelCastHelper->getCastForProperty($classReflection, 'uuid'));
        self::assertNull($modelCastHelper->getCastForProperty($classReflection, 'id'));
    }

    /**
     * Unlike upstream, the backing values the cast stores as they are are accepted too, as AsEnumCollection declares.
     */
    #[Test]
    public function itResolvesTheEnumClassWhenWritingAnEnumCollectionCast(): void
    {
        $modelCastHelper = new ModelCastHelper(
            $this->reflectionProvider,
            $this->parser,
            false,
            self::getContainer()->getByType(ScopeFactory::class),
            self::getContainer()->getByType(DeclarationDependencyTracker::class),
            self::getContainer()->getByType(ModelHelper::class),
        );

        $writeableType = $modelCastHelper->getWriteableType(AsEnumCollection::class . ':' . BackedEnumeration::class, new MixedType);

        self::assertSame('iterable<App\Casts\BackedEnumeration|int|string>|null', $writeableType->describe(VerbosityLevel::precise()));
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
