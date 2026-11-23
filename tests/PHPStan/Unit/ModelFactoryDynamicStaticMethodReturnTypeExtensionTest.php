<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use App\User;
use Generator;
use Hypervel\PHPStan\ReturnTypes\ModelFactoryDynamicStaticMethodReturnTypeExtension;
use Hypervel\PHPStan\Types\Factory\ModelFactoryType;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Accessory\AccessoryNumericStringType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantFloatType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ModelFactoryDynamicStaticMethodReturnTypeExtensionTest extends PHPStanTestCase
{
    private ReflectionProvider $reflectionProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reflectionProvider = $this->createReflectionProvider();
    }

    #[Test]
    public function itSetsTheIsSingleModelFlagToTrueIfNoArgsGiven(): void
    {
        $class = new Name(User::class);

        $scope = $this->createMock(Scope::class);
        $scope->expects($this->once())->method('resolveName')->with($class)->willReturn(User::class);

        $extension = self::getContainer()->getByType(ModelFactoryDynamicStaticMethodReturnTypeExtension::class);

        $type = $extension->getTypeFromStaticMethodCall(
            $this->reflectionProvider->getClass(User::class)->getNativeMethod('factory'),
            new StaticCall($class, 'factory', []),
            $scope,
        );

        $this->assertInstanceOf(ModelFactoryType::class, $type);
        $this->assertTrue(TrinaryLogic::createYes()->equals($type->isSingleModel()));
    }

    #[Test]
    #[DataProvider('argumentProvider')]
    public function itSetsTheIsSingleModelFlagCorrectly(Type $phpstanType, TrinaryLogic $expected): void
    {
        $class = new Name(User::class);

        $scope = $this->createMock(Scope::class);
        $scope->expects($this->once())->method('resolveName')->with($class)->willReturn(User::class);
        $scope->method('getType')->willReturn($phpstanType);

        $extension = self::getContainer()->getByType(ModelFactoryDynamicStaticMethodReturnTypeExtension::class);

        $type = $extension->getTypeFromStaticMethodCall(
            $this->reflectionProvider->getClass(User::class)->getNativeMethod('factory'),
            new StaticCall($class, 'factory', [new Arg(new Int_(1))]), // args doesn't matter
            $scope,
        );

        $this->assertInstanceOf(ModelFactoryType::class, $type);
        $this->assertSame($expected->describe(), $type->isSingleModel()->describe());
    }

    public static function argumentProvider(): Generator
    {
        yield [new ConstantIntegerType(1), TrinaryLogic::createNo()];
        yield [new ConstantIntegerType(0), TrinaryLogic::createNo()];
        yield [new ConstantIntegerType(2), TrinaryLogic::createNo()];
        yield [new ConstantIntegerType(-1), TrinaryLogic::createNo()];
        yield [new ConstantFloatType(1.2), TrinaryLogic::createNo()];
        yield [new IntegerType, TrinaryLogic::createNo()];
        yield [new FloatType, TrinaryLogic::createNo()];
        yield [
            new IntersectionType([
                new ConstantStringType('1'),
                new AccessoryNumericStringType,
            ]),
            TrinaryLogic::createNo(),
        ];

        yield [new UnionType([new FloatType, new ConstantIntegerType(1)]), TrinaryLogic::createNo()];

        yield [new NullType, TrinaryLogic::createYes()];
        yield [new ObjectType('App\User'), TrinaryLogic::createYes()];
        yield [new ConstantStringType('foo'), TrinaryLogic::createYes()];

        yield [new MixedType, TrinaryLogic::createMaybe()];
        yield [new UnionType([new NullType, new ConstantIntegerType(1)]), TrinaryLogic::createMaybe()];
        yield [new UnionType([new ArrayType(new IntegerType, new IntegerType), new IntegerType]), TrinaryLogic::createMaybe()];
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
