<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\ModelSerializationTest;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Attributes\Boot;
use Hypervel\Database\Eloquent\Attributes\Initialize;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\ModelIdentifier;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Queue\Attributes\WithoutRelations;
use Hypervel\Queue\SerializesModels;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use LogicException;
use Override;

/**
 * @internal
 * @coversNothing
 */
class ModelSerializationTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app['config']->set('database.connections.custom', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Model::preventLazyLoading(false);

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
        });

        Schema::connection('custom')->create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('lines', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('order_id');
            $table->unsignedInteger('product_id');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('role_id');
        });
    }

    #[Override]
    protected function tearDown(): void
    {
        Relation::morphMap([], false);
        ModelIdentifier::useMorphMap(false);

        parent::tearDown();
    }

    public function testItSerializeUserOnDefaultConnection()
    {
        $defaultConnection = config('database.default');

        $user = ModelSerializationTestUser::create([
            'email' => 'mohamed@laravel.com',
        ]);

        ModelSerializationTestUser::create([
            'email' => 'taylor@laravel.com',
        ]);

        $serialized = serialize(new ModelSerializationTestClass($user));

        $unSerialized = unserialize($serialized);

        $this->assertSame($defaultConnection, $unSerialized->user->getConnectionName());
        $this->assertSame('mohamed@laravel.com', $unSerialized->user->email);

        $serialized = serialize(new CollectionSerializationTestClass(ModelSerializationTestUser::on($defaultConnection)->get()));

        $unSerialized = unserialize($serialized);

        $this->assertSame($defaultConnection, $unSerialized->users[0]->getConnectionName());
        $this->assertSame('mohamed@laravel.com', $unSerialized->users[0]->email);
        $this->assertSame($defaultConnection, $unSerialized->users[1]->getConnectionName());
        $this->assertSame('taylor@laravel.com', $unSerialized->users[1]->email);
    }

    public function testItSerializeUserOnDifferentConnection()
    {
        $user = ModelSerializationTestUser::on('custom')->create([
            'email' => 'mohamed@laravel.com',
        ]);

        ModelSerializationTestUser::on('custom')->create([
            'email' => 'taylor@laravel.com',
        ]);

        $serialized = serialize(new ModelSerializationTestClass($user));

        $unSerialized = unserialize($serialized);

        $this->assertSame('custom', $unSerialized->user->getConnectionName());
        $this->assertSame('mohamed@laravel.com', $unSerialized->user->email);

        $serialized = serialize(new CollectionSerializationTestClass(ModelSerializationTestUser::on('custom')->get()));

        $unSerialized = unserialize($serialized);

        $this->assertSame('custom', $unSerialized->users[0]->getConnectionName());
        $this->assertSame('mohamed@laravel.com', $unSerialized->users[0]->email);
        $this->assertSame('custom', $unSerialized->users[1]->getConnectionName());
        $this->assertSame('taylor@laravel.com', $unSerialized->users[1]->email);
    }

    public function testItFailsIfModelsOnMultiConnections()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Queueing collections with multiple model connections is not supported.');

        $user = ModelSerializationTestUser::on('custom')->create([
            'email' => 'mohamed@laravel.com',
        ]);

        $user2 = ModelSerializationTestUser::create([
            'email' => 'taylor@laravel.com',
        ]);

        $serialized = serialize(new CollectionSerializationTestClass(
            new Collection([$user, $user2])
        ));

        unserialize($serialized);
    }

    public function testItReloadsRelationships()
    {
        $order = tap(Order::create(), function (Order $order) {
            $order->wasRecentlyCreated = false;
        });

        $product1 = Product::create();
        $product2 = Product::create();

        Line::create(['order_id' => $order->id, 'product_id' => $product1->id]);
        Line::create(['order_id' => $order->id, 'product_id' => $product2->id]);

        $order->load('line', 'lines', 'products');

        $serialized = serialize(new ModelRelationSerializationTestClass($order));
        $unSerialized = unserialize($serialized);

        $this->assertEquals($unSerialized->order->getRelations(), $order->getRelations());
    }

    public function testItReloadsRelationshipsOnlyOnce()
    {
        $order = tap(ModelSerializationTestCustomOrder::create(), function (ModelSerializationTestCustomOrder $order) {
            $order->wasRecentlyCreated = false;
        });

        $product1 = Product::create();
        $product2 = Product::create();

        Line::create(['order_id' => $order->id, 'product_id' => $product1->id]);
        Line::create(['order_id' => $order->id, 'product_id' => $product2->id]);

        $order->load('line', 'lines', 'products');

        $this->expectsDatabaseQueryCount(4);

        $serialized = serialize(new ModelRelationSerializationTestClass($order));
        $unSerialized = unserialize($serialized);

        $this->assertEquals($unSerialized->order->getRelations(), $order->getRelations());
    }

    public function testItReloadsNestedRelationships()
    {
        $order = tap(Order::create(), function (Order $order) {
            $order->wasRecentlyCreated = false;
        });

        $product1 = Product::create();
        $product2 = Product::create();

        Line::create(['order_id' => $order->id, 'product_id' => $product1->id]);
        Line::create(['order_id' => $order->id, 'product_id' => $product2->id]);

        $order->load('line.product', 'lines', 'lines.product', 'products');

        $nestedSerialized = serialize(new ModelRelationSerializationTestClass($order));
        $nestedUnSerialized = unserialize($nestedSerialized);

        $this->assertEquals($nestedUnSerialized->order->getRelations(), $order->getRelations());
    }

    public function testItReloadsRelationshipsForCollections()
    {
        $order1 = tap(Order::create(), function (Order $order) {
            $order->wasRecentlyCreated = false;
        });

        $order2 = tap(Order::create(), function (Order $order) {
            $order->wasRecentlyCreated = false;
        });

        $product1 = Product::create();
        $product2 = Product::create();

        Line::create(['order_id' => $order1->id, 'product_id' => $product1->id]);
        Line::create(['order_id' => $order2->id, 'product_id' => $product2->id]);

        $orders = Order::with('line', 'lines', 'products')->get();

        $serialized = serialize(new CollectionRelationSerializationTestClass($orders));
        $unSerialized = unserialize($serialized);

        $this->assertCount(2, $unSerialized->orders);
        $this->assertTrue($unSerialized->orders[0]->relationLoaded('line'));
        $this->assertTrue($unSerialized->orders[0]->relationLoaded('lines'));
        $this->assertTrue($unSerialized->orders[0]->relationLoaded('products'));
        $this->assertTrue($unSerialized->orders[1]->relationLoaded('line'));
        $this->assertTrue($unSerialized->orders[1]->relationLoaded('lines'));
        $this->assertTrue($unSerialized->orders[1]->relationLoaded('products'));
    }

    public function testItReloadsNestedRelationshipsForCollections()
    {
        $order1 = tap(Order::create(), function (Order $order) {
            $order->wasRecentlyCreated = false;
        });

        $order2 = tap(Order::create(), function (Order $order) {
            $order->wasRecentlyCreated = false;
        });

        $product1 = Product::create();
        $product2 = Product::create();

        Line::create(['order_id' => $order1->id, 'product_id' => $product1->id]);
        Line::create(['order_id' => $order2->id, 'product_id' => $product2->id]);

        $orders = Order::with('line.product', 'lines.product')->get();

        $serialized = serialize(new CollectionRelationSerializationTestClass($orders));
        $unSerialized = unserialize($serialized);

        $this->assertCount(2, $unSerialized->orders);
        $this->assertTrue($unSerialized->orders[0]->relationLoaded('line'));
        $this->assertTrue($unSerialized->orders[0]->line->relationLoaded('product'));
        $this->assertTrue($unSerialized->orders[0]->relationLoaded('lines'));
        $this->assertTrue($unSerialized->orders[0]->lines->first()->relationLoaded('product'));
        $this->assertTrue($unSerialized->orders[1]->relationLoaded('line'));
        $this->assertTrue($unSerialized->orders[1]->line->relationLoaded('product'));
        $this->assertTrue($unSerialized->orders[1]->relationLoaded('lines'));
        $this->assertTrue($unSerialized->orders[1]->lines->first()->relationLoaded('product'));
    }

    public function testItCanRunModelBootsAndTraitInitializations()
    {
        $model = new ModelBootTestWithTraitInitialization;

        $this->assertTrue($model->fooBar);
        $this->assertTrue($model->initializedViaAttributeInClass);
        $this->assertTrue($model->initializedViaAttributeInTrait);
        $this->assertTrue($model::hasGlobalScope('foo_bar'));
        $this->assertTrue($model::hasGlobalScope('booted_attr_in_class'));
        $this->assertTrue($model::hasGlobalScope('booted_attr_in_trait'));

        $model::clearBootedModels();

        $this->assertFalse($model::hasGlobalScope('foo_bar'));
        $this->assertFalse($model::hasGlobalScope('booted_attr_in_class'));
        $this->assertFalse($model::hasGlobalScope('booted_attr_in_trait'));

        $unSerializedModel = unserialize(serialize($model));

        $this->assertFalse($unSerializedModel->fooBar);
        $this->assertFalse($unSerializedModel->initializedViaAttributeInClass);
        $this->assertFalse($unSerializedModel->initializedViaAttributeInTrait);
        $this->assertTrue($model::hasGlobalScope('foo_bar'));
        $this->assertTrue($model::hasGlobalScope('booted_attr_in_class'));
        $this->assertTrue($model::hasGlobalScope('booted_attr_in_trait'));
    }

    /**
     * Regression test for https://github.com/laravel/framework/issues/23068.
     */
    public function testItCanUnserializeNestedRelationshipsWithoutPivot()
    {
        $user = tap(User::create([
            'email' => 'taylor@laravel.com',
        ]), function (User $user) {
            $user->wasRecentlyCreated = false;
        });

        $role1 = Role::create();
        $role2 = Role::create();

        RoleUser::create(['user_id' => $user->id, 'role_id' => $role1->id]);
        RoleUser::create(['user_id' => $user->id, 'role_id' => $role2->id]);

        $user->roles->each(function ($role) {
            $role->pivot->load('user', 'role');
        });

        $serialized = serialize(new ModelSerializationTestClass($user));
        unserialize($serialized);
    }

    public function testItSerializesAnEmptyCollection()
    {
        $serialized = serialize(new CollectionSerializationTestClass(
            new Collection([])
        ));

        unserialize($serialized);
    }

    public function testItSerializesACollectionInCorrectOrder()
    {
        ModelSerializationTestUser::create(['email' => 'mohamed@laravel.com']);
        ModelSerializationTestUser::create(['email' => 'taylor@laravel.com']);

        $serialized = serialize(new CollectionSerializationTestClass(
            ModelSerializationTestUser::orderByDesc('email')->get()
        ));

        $unserialized = unserialize($serialized);

        $this->assertSame('taylor@laravel.com', $unserialized->users->first()->email);
        $this->assertSame('mohamed@laravel.com', $unserialized->users->last()->email);
    }

    public function testItCanUnserializeACollectionInCorrectOrderAndHandleDeletedModels()
    {
        ModelSerializationTestUser::create(['email' => '2@laravel.com']);
        ModelSerializationTestUser::create(['email' => '3@laravel.com']);
        ModelSerializationTestUser::create(['email' => '1@laravel.com']);

        $serialized = serialize(new CollectionSerializationTestClass(
            ModelSerializationTestUser::orderByDesc('email')->get()
        ));

        ModelSerializationTestUser::where(['email' => '2@laravel.com'])->delete();

        $unserialized = unserialize($serialized);

        $this->assertCount(2, $unserialized->users);

        $this->assertSame('3@laravel.com', $unserialized->users->first()->email);
        $this->assertSame('1@laravel.com', $unserialized->users->last()->email);
    }

    public function testItCanUnserializeCustomCollection()
    {
        ModelSerializationTestCustomUser::create(['email' => 'mohamed@laravel.com']);
        ModelSerializationTestCustomUser::create(['email' => 'taylor@laravel.com']);

        $serialized = serialize(new CollectionSerializationTestClass(
            ModelSerializationTestCustomUser::all()
        ));

        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(ModelSerializationTestCustomUserCollection::class, $unserialized->users);
    }

    public function testItSerializesTypedProperties()
    {
        require_once __DIR__ . '/typed-properties.php';

        $defaultConnection = config('database.default');

        $user = ModelSerializationTestUser::create([
            'email' => 'mohamed@laravel.com',
        ]);

        ModelSerializationTestUser::create([
            'email' => 'taylor@laravel.com',
        ]);

        $serialized = serialize(new TypedPropertyTestClass($user, 5, ['James', 'Taylor', 'Mohamed']));

        $unSerialized = unserialize($serialized);

        $this->assertSame($defaultConnection, $unSerialized->user->getConnectionName());
        $this->assertSame('mohamed@laravel.com', $unSerialized->user->email);
        $this->assertSame(5, $unSerialized->getId());
        $this->assertSame(['James', 'Taylor', 'Mohamed'], $unSerialized->getNames());

        $serialized = serialize(new TypedPropertyCollectionTestClass(ModelSerializationTestUser::on($defaultConnection)->get()));

        $unSerialized = unserialize($serialized);

        $this->assertSame($defaultConnection, $unSerialized->users[0]->getConnectionName());
        $this->assertSame('mohamed@laravel.com', $unSerialized->users[0]->email);
        $this->assertSame($defaultConnection, $unSerialized->users[1]->getConnectionName());
        $this->assertSame('taylor@laravel.com', $unSerialized->users[1]->email);
    }

    #[WithConfig('database.default', 'testing')]
    public function testModelSerializationStructure()
    {
        $user = ModelSerializationTestUser::create([
            'email' => 'taylor@laravel.com',
        ]);

        $serialized = serialize(new ModelSerializationParentAccessibleTestClass($user, $user, $user));

        $this->assertSame($this->expectedParentAccessibleSerialization(), $serialized);
    }

    #[WithConfig('database.default', 'testing')]
    public function testItRespectsWithoutRelationsAttribute()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
        ])->load(['roles']);

        $serialized = serialize(new ModelSerializationWithoutRelations($user));

        $this->assertSame($this->expectedWithoutRelationsSerialization(), $serialized);
    }

    #[WithConfig('database.default', 'testing')]
    public function testItRespectsWithoutRelationsAttributeAppliedToClass()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
        ])->load(['roles']);

        $serialized = serialize(new ModelSerializationAttributeTargetsClassTestClass($user, new DataValueObject('hello')));

        $this->assertSame($this->expectedAttributeTargetsClassSerialization(), $serialized);

        /** @var ModelSerializationAttributeTargetsClassTestClass $unserialized */
        $unserialized = unserialize($serialized);

        $this->assertFalse($unserialized->user->relationLoaded('roles'));
        $this->assertEquals('hello', $unserialized->value->value);
    }

    public function testSerializationTypesEmptyCustomEloquentCollection()
    {
        $class = new ModelSerializationTypedCustomCollectionTestClass(
            new ModelSerializationTestCustomUserCollection
        );

        $serialized = serialize($class);

        unserialize($serialized);

        $this->assertTrue(true);
    }

    #[WithConfig('database.default', 'testing')]
    public function testItUsersMorphmapForSerialization()
    {
        Relation::morphMap([
            'user' => User::class,
        ]);
        ModelIdentifier::useMorphMap();

        $user = User::create([
            'email' => 'taylor@laravel.com',
        ]);

        $serialized = serialize(new ModelSerializationAttributeTargetsClassTestClass(
            $user,
            new DataValueObject('hello')
        ));

        $this->assertSame($this->expectedAttributeTargetsClassSerialization('user'), $serialized);

        /** @var ModelSerializationAttributeTargetsClassTestClass $unserialized */
        $unserialized = unserialize($serialized);

        $this->assertTrue($unserialized->user->is($user));
    }

    private function expectedParentAccessibleSerialization(): string
    {
        $class = ModelSerializationParentAccessibleTestClass::class;
        $modelIdentifier = ModelIdentifier::class;
        $userClass = ModelSerializationTestUser::class;

        return sprintf(
            'O:%d:"%s":2:{s:4:"user";O:%d:"%s":5:{s:5:"class";s:%d:"%s";s:2:"id";i:1;s:9:"relations";a:0:{}s:10:"connection";s:7:"testing";s:15:"collectionClass";N;}s:8:"%s";O:%d:"%s":5:{s:5:"class";s:%d:"%s";s:2:"id";i:1;s:9:"relations";a:0:{}s:10:"connection";s:7:"testing";s:15:"collectionClass";N;}}',
            strlen($class),
            $class,
            strlen($modelIdentifier),
            $modelIdentifier,
            strlen($userClass),
            $userClass,
            "\0*\0user2",
            strlen($modelIdentifier),
            $modelIdentifier,
            strlen($userClass),
            $userClass,
        );
    }

    private function expectedWithoutRelationsSerialization(): string
    {
        $class = ModelSerializationWithoutRelations::class;
        $modelIdentifier = ModelIdentifier::class;
        $userClass = User::class;

        return sprintf(
            'O:%d:"%s":1:{s:4:"user";O:%d:"%s":5:{s:5:"class";s:%d:"%s";s:2:"id";i:1;s:9:"relations";a:0:{}s:10:"connection";s:7:"testing";s:15:"collectionClass";N;}}',
            strlen($class),
            $class,
            strlen($modelIdentifier),
            $modelIdentifier,
            strlen($userClass),
            $userClass,
        );
    }

    private function expectedAttributeTargetsClassSerialization(string $userClass = User::class): string
    {
        $class = ModelSerializationAttributeTargetsClassTestClass::class;
        $modelIdentifier = ModelIdentifier::class;
        $valueClass = DataValueObject::class;

        return sprintf(
            'O:%d:"%s":2:{s:4:"user";O:%d:"%s":5:{s:5:"class";s:%d:"%s";s:2:"id";i:1;s:9:"relations";a:0:{}s:10:"connection";s:7:"testing";s:15:"collectionClass";N;}s:5:"value";O:%d:"%s":1:{s:5:"value";s:5:"hello";}}',
            strlen($class),
            $class,
            strlen($modelIdentifier),
            $modelIdentifier,
            strlen($userClass),
            $userClass,
            strlen($valueClass),
            $valueClass,
        );
    }
}

trait TraitBootsAndInitializersTest
{
    public bool $initializedViaAttributeInTrait = false;

    public bool $fooBar = false;

    public function initializeTraitBootsAndInitializersTest(): void
    {
        $this->fooBar = ! $this->fooBar;
    }

    public static function bootTraitBootsAndInitializersTest(): void
    {
        static::addGlobalScope('foo_bar', function () {
        });
    }

    #[Boot]
    public static function nonConventionalBootFunctionInTrait(): void
    {
        static::addGlobalScope('booted_attr_in_trait', function () {
        });
    }

    #[Initialize]
    public function nonConventionalInitFunctionInTrait(): void
    {
        $this->initializedViaAttributeInTrait = ! $this->initializedViaAttributeInTrait;
    }
}

class ModelBootTestWithTraitInitialization extends Model
{
    use TraitBootsAndInitializersTest;

    public static bool $bootedViaAttributeInClass = false;

    public bool $initializedViaAttributeInClass = false;

    #[Boot]
    public static function nonConventionalBootFunctionInClass(): void
    {
        static::addGlobalScope('booted_attr_in_class', function () {
        });
    }

    #[Initialize]
    public function nonConventionalInitFunctionInClass(): void
    {
        $this->initializedViaAttributeInClass = ! $this->initializedViaAttributeInClass;
    }
}

class ModelSerializationTestUser extends Model
{
    protected ?string $table = 'users';

    protected array $guarded = [];

    public bool $timestamps = false;
}

class ModelSerializationTestCustomUserCollection extends Collection
{
}

class ModelSerializationTypedCustomCollectionTestClass
{
    use SerializesModels;

    public ModelSerializationTestCustomUserCollection $collection;

    public function __construct(ModelSerializationTestCustomUserCollection $collection)
    {
        $this->collection = $collection;
    }
}

class ModelSerializationTestCustomUser extends Model
{
    protected ?string $table = 'users';

    protected array $guarded = [];

    public bool $timestamps = false;

    public function newCollection(array $models = []): ModelSerializationTestCustomUserCollection
    {
        return new ModelSerializationTestCustomUserCollection($models);
    }
}

class ModelSerializationTestCustomOrder extends Model
{
    protected ?string $table = 'orders';

    protected array $guarded = [];

    public bool $timestamps = false;

    protected array $with = ['line', 'lines', 'products'];

    public function line(): HasOne
    {
        return $this->hasOne(Line::class, 'order_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(Line::class, 'order_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'lines', 'order_id');
    }
}

class Order extends Model
{
    protected array $guarded = [];

    public bool $timestamps = false;

    public function line(): HasOne
    {
        return $this->hasOne(Line::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(Line::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'lines');
    }
}

class Line extends Model
{
    protected array $guarded = [];

    public bool $timestamps = false;

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

class Product extends Model
{
    protected array $guarded = [];

    public bool $timestamps = false;
}

class User extends Model
{
    protected array $guarded = [];

    public bool $timestamps = false;

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->using(RoleUser::class);
    }
}

class Role extends Model
{
    protected array $guarded = [];

    public bool $timestamps = false;

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(RoleUser::class);
    }
}

class RoleUser extends Pivot
{
    protected array $guarded = [];

    public bool $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}

class ModelSerializationTestClass
{
    use SerializesModels;

    public ModelSerializationTestUser|User $user;

    public function __construct(ModelSerializationTestUser|User $user)
    {
        $this->user = $user;
    }
}

class ModelSerializationAccessibleTestClass
{
    use SerializesModels;

    public ModelSerializationTestUser $user;

    protected ModelSerializationTestUser $user2;

    private ModelSerializationTestUser $user3;

    public function __construct(ModelSerializationTestUser $user, ModelSerializationTestUser $user2, ModelSerializationTestUser $user3)
    {
        $this->user = $user;
        $this->user2 = $user2;
        $this->user3 = $user3;
    }
}

class ModelSerializationParentAccessibleTestClass extends ModelSerializationAccessibleTestClass
{
}

class ModelSerializationWithoutRelations
{
    use SerializesModels;

    #[WithoutRelations]
    public User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }
}

#[WithoutRelations]
class ModelSerializationAttributeTargetsClassTestClass
{
    use SerializesModels;

    public function __construct(public User $user, public DataValueObject $value)
    {
    }
}

class ModelRelationSerializationTestClass
{
    use SerializesModels;

    public Order|ModelSerializationTestCustomOrder $order;

    public function __construct(Order|ModelSerializationTestCustomOrder $order)
    {
        $this->order = $order;
    }
}

class CollectionSerializationTestClass
{
    use SerializesModels;

    public Collection $users;

    public function __construct(Collection $users)
    {
        $this->users = $users;
    }
}

class CollectionRelationSerializationTestClass
{
    use SerializesModels;

    public Collection $orders;

    public function __construct(Collection $orders)
    {
        $this->orders = $orders;
    }
}

class DataValueObject
{
    public function __construct(public string|int $value = 1)
    {
    }
}
