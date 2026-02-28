<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\DatabaseEloquentFactoryTest;

use BadMethodCallException;
use Carbon\Carbon;
use Faker\Generator;
use Hypervel\Container\Container;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Attributes\UseFactory;
use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Factories\CrossJoinSequence;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Factories\Sequence;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Database\Laravel\Fixtures\Models\Money\Price;
use Mockery as m;
use ReflectionClass;

/**
 * TODO(laravel-container-port): This test requires Laravel's container to be ported.
 * It relies on Container::setInstance(null) and other Laravel-specific container behaviors
 * that differ from Hyperf's container. Once Laravel's container is ported, remove the
 * markTestSkipped() call in setUp() and this test should work.
 * @internal
 * @coversNothing
 */
class DatabaseEloquentFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        $this->markTestSkipped(
            'Requires Laravel container port - uses Container::setInstance(null) and other Laravel-specific container behaviors'
        );

        $container = Container::getInstance();
        $container->singleton(Generator::class, function ($app, $parameters) {
            return \Faker\Factory::create('en_US');
        });
        $container->instance(Application::class, $app = m::mock(Application::class));
        $app->shouldReceive('getNamespace')->andReturn('App\\');

        $db = new DB();

        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $db->bootEloquent();
        $db->setAsGlobal();

        $this->createSchema();
        Factory::expandRelationshipsByDefault();
    }

    /**
     * Setup the database schema.
     */
    public function createSchema()
    {
        $this->schema()->create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('options')->nullable();
            $table->timestamps();
        });

        $this->schema()->create('posts', function ($table) {
            $table->increments('id');
            $table->foreignId('user_id');
            $table->string('title');
            $table->softDeletes();
            $table->timestamps();
        });

        $this->schema()->create('comments', function ($table) {
            $table->increments('id');
            $table->foreignId('commentable_id');
            $table->string('commentable_type');
            $table->foreignId('user_id');
            $table->string('body');
            $table->softDeletes();
            $table->timestamps();
        });

        $this->schema()->create('roles', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });

        $this->schema()->create('role_user', function ($table) {
            $table->foreignId('role_id');
            $table->foreignId('user_id');
            $table->string('admin')->default('N');
        });
    }

    /**
     * Tear down the database schema.
     */
    protected function tearDown(): void
    {
        $this->schema()->drop('users');

        Container::setInstance(null);

        parent::tearDown();
    }

    public function testBasicModelCanBeCreated()
    {
        $user = UserFactory::new()->create();
        $this->assertInstanceOf(Eloquent::class, $user);

        $user = UserFactory::new()->createOne();
        $this->assertInstanceOf(Eloquent::class, $user);

        $user = UserFactory::new()->create(['name' => 'Taylor Otwell']);
        $this->assertInstanceOf(Eloquent::class, $user);
        $this->assertSame('Taylor Otwell', $user->name);

        $user = UserFactory::new()->set('name', 'Taylor Otwell')->create();
        $this->assertInstanceOf(Eloquent::class, $user);
        $this->assertSame('Taylor Otwell', $user->name);

        $users = UserFactory::new()->createMany([
            ['name' => 'Taylor Otwell'],
            ['name' => 'Jeffrey Way'],
        ]);
        $this->assertInstanceOf(Collection::class, $users);
        $this->assertCount(2, $users);

        $users = UserFactory::new()->createMany(2);
        $this->assertInstanceOf(Collection::class, $users);
        $this->assertCount(2, $users);
        $this->assertInstanceOf(User::class, $users->first());

        $users = UserFactory::times(2)->createMany();
        $this->assertInstanceOf(Collection::class, $users);
        $this->assertCount(2, $users);
        $this->assertInstanceOf(User::class, $users->first());

        $users = UserFactory::times(2)->createMany();
        $this->assertInstanceOf(Collection::class, $users);
        $this->assertCount(2, $users);
        $this->assertInstanceOf(User::class, $users->first());

        $users = UserFactory::times(3)->createMany([
            ['name' => 'Taylor Otwell'],
            ['name' => 'Jeffrey Way'],
        ]);
        $this->assertInstanceOf(Collection::class, $users);
        $this->assertCount(2, $users);
        $this->assertInstanceOf(User::class, $users->first());

        $users = UserFactory::new()->createMany();
        $this->assertInstanceOf(Collection::class, $users);
        $this->assertCount(1, $users);
        $this->assertInstanceOf(User::class, $users->first());

        $users = UserFactory::times(10)->create();
        $this->assertCount(10, $users);
    }

    public function testExpandedClosureAttributesAreResolvedAndPassedToClosures()
    {
        $user = UserFactory::new()->create([
            'name' => function () {
                return 'taylor';
            },
            'options' => function ($attributes) {
                return $attributes['name'] . '-options';
            },
        ]);

        $this->assertSame('taylor-options', $user->options);
    }

    public function testExpandedClosureAttributeReturningAFactoryIsResolved()
    {
        $post = PostFactory::new()->create([
            'title' => 'post',
            'user_id' => fn ($attributes) => UserFactory::new([
                'options' => $attributes['title'] . '-options',
            ]),
        ]);

        $this->assertEquals('post-options', $post->user->options);
    }

    public function testMakeCreatesUnpersistedModelInstance()
    {
        $user = UserFactory::new()->makeOne();
        $this->assertInstanceOf(Eloquent::class, $user);

        $user = UserFactory::new()->make(['name' => 'Taylor Otwell']);

        $this->assertInstanceOf(Eloquent::class, $user);
        $this->assertSame('Taylor Otwell', $user->name);
        $this->assertCount(0, User::all());
    }

    public function testBasicModelAttributesCanBeCreated()
    {
        $user = UserFactory::new()->raw();
        $this->assertIsArray($user);

        $user = UserFactory::new()->raw(['name' => 'Taylor Otwell']);
        $this->assertIsArray($user);
        $this->assertSame('Taylor Otwell', $user['name']);
    }

    public function testExpandedModelAttributesCanBeCreated()
    {
        $post = PostFactory::new()->raw();
        $this->assertIsArray($post);

        $post = PostFactory::new()->raw(['title' => 'Test Title']);
        $this->assertIsArray($post);
        $this->assertIsInt($post['user_id']);
        $this->assertSame('Test Title', $post['title']);
    }

    public function testLazyModelAttributesCanBeCreated()
    {
        $userFunction = UserFactory::new()->lazy();
        $this->assertIsCallable($userFunction);
        $this->assertInstanceOf(Eloquent::class, $userFunction());

        $userFunction = UserFactory::new()->lazy(['name' => 'Taylor Otwell']);
        $this->assertIsCallable($userFunction);

        $user = $userFunction();
        $this->assertInstanceOf(Eloquent::class, $user);
        $this->assertSame('Taylor Otwell', $user->name);
    }

    public function testMultipleModelAttributesCanBeCreated()
    {
        $posts = PostFactory::times(10)->raw();
        $this->assertIsArray($posts);

        $this->assertCount(10, $posts);
    }

    public function testAfterCreatingAndMakingCallbacksAreCalled()
    {
        $user = UserFactory::new()
            ->afterMaking(function ($user) {
                $_SERVER['__test.user.making'] = $user;
            })
            ->afterCreating(function ($user) {
                $_SERVER['__test.user.creating'] = $user;
            })
            ->create();

        $this->assertSame($user, $_SERVER['__test.user.making']);
        $this->assertSame($user, $_SERVER['__test.user.creating']);

        unset($_SERVER['__test.user.making'], $_SERVER['__test.user.creating']);
    }

    public function testHasManyRelationship()
    {
        $users = UserFactory::times(10)
            ->has(
                PostFactory::times(3)
                    ->state(function ($attributes, $user) {
                        // Test parent is passed to child state mutations...
                        $_SERVER['__test.post.state-user'] = $user;

                        return [];
                    })
                    // Test parents passed to callback...
                    ->afterCreating(function ($post, $user) {
                        $_SERVER['__test.post.creating-post'] = $post;
                        $_SERVER['__test.post.creating-user'] = $user;
                    }),
                'posts'
            )
            ->create();

        $this->assertCount(10, User::all());
        $this->assertCount(30, Post::all());
        $this->assertCount(3, User::latest()->first()->posts);

        $this->assertInstanceOf(Eloquent::class, $_SERVER['__test.post.creating-post']);
        $this->assertInstanceOf(Eloquent::class, $_SERVER['__test.post.creating-user']);
        $this->assertInstanceOf(Eloquent::class, $_SERVER['__test.post.state-user']);

        unset($_SERVER['__test.post.creating-post'], $_SERVER['__test.post.creating-user'], $_SERVER['__test.post.state-user']);
    }

    public function testBelongsToRelationship()
    {
        $posts = PostFactory::times(3)
            ->for(UserFactory::new(['name' => 'Taylor Otwell']), 'user')
            ->create();

        $this->assertCount(3, $posts->filter(function ($post) {
            return $post->user->name === 'Taylor Otwell';
        }));

        $this->assertCount(1, User::all());
        $this->assertCount(3, Post::all());
    }

    public function testBelongsToRelationshipWithExistingModelInstance()
    {
        $user = UserFactory::new(['name' => 'Taylor Otwell'])->create();
        $posts = PostFactory::times(3)
            ->for($user, 'user')
            ->create();

        $this->assertCount(3, $posts->filter(function ($post) use ($user) {
            return $post->user->is($user);
        }));

        $this->assertCount(1, User::all());
        $this->assertCount(3, Post::all());
    }

    public function testBelongsToRelationshipWithExistingModelInstanceWithRelationshipNameImpliedFromModel()
    {
        $user = UserFactory::new(['name' => 'Taylor Otwell'])->create();
        $posts = PostFactory::times(3)
            ->for($user)
            ->create();

        $this->assertCount(3, $posts->filter(function ($post) use ($user) {
            return $post->factoryTestUser->is($user);
        }));

        $this->assertCount(1, User::all());
        $this->assertCount(3, Post::all());
    }

    public function testMorphToRelationship()
    {
        $posts = CommentFactory::times(3)
            ->for(PostFactory::new(['title' => 'Test Title']), 'commentable')
            ->create();

        $this->assertSame('Test Title', Post::first()->title);
        $this->assertCount(3, Post::first()->comments);

        $this->assertCount(1, Post::all());
        $this->assertCount(3, Comment::all());
    }

    public function testMorphToRelationshipWithExistingModelInstance()
    {
        $post = PostFactory::new(['title' => 'Test Title'])->create();
        $posts = CommentFactory::times(3)
            ->for($post, 'commentable')
            ->create();

        $this->assertSame('Test Title', Post::first()->title);
        $this->assertCount(3, Post::first()->comments);

        $this->assertCount(1, Post::all());
        $this->assertCount(3, Comment::all());
    }

    public function testBelongsToManyRelationship()
    {
        $users = UserFactory::times(3)
            ->hasAttached(
                RoleFactory::times(3)->afterCreating(function ($role, $user) {
                    $_SERVER['__test.role.creating-role'] = $role;
                    $_SERVER['__test.role.creating-user'] = $user;
                }),
                ['admin' => 'Y'],
                'roles'
            )
            ->create();

        $this->assertCount(9, Role::all());

        $user = User::latest()->first();

        $this->assertCount(3, $user->roles);
        $this->assertSame('Y', $user->roles->first()->pivot->admin);

        $this->assertInstanceOf(Eloquent::class, $_SERVER['__test.role.creating-role']);
        $this->assertInstanceOf(Eloquent::class, $_SERVER['__test.role.creating-user']);

        unset($_SERVER['__test.role.creating-role'], $_SERVER['__test.role.creating-user']);
    }

    public function testBelongsToManyRelationshipRelatedModelsSetOnInstanceWhenTouchingOwner()
    {
        $user = UserFactory::new()->create();
        $role = RoleFactory::new()->hasAttached($user, [], 'users')->create();

        $this->assertCount(1, $role->users);
    }

    public function testRelationCanBeLoadedBeforeModelIsCreated()
    {
        $user = UserFactory::new(['name' => 'Taylor Otwell'])->createOne();

        $post = PostFactory::new()
            ->for($user, 'user')
            ->afterMaking(function (Post $post) {
                $post->load('user');
            })
            ->createOne();

        $this->assertTrue($post->relationLoaded('user'));
        $this->assertTrue($post->user->is($user));

        $this->assertCount(1, User::all());
        $this->assertCount(1, Post::all());
    }

    public function testBelongsToManyRelationshipWithExistingModelInstances()
    {
        $roles = RoleFactory::times(3)
            ->afterCreating(function ($role) {
                $_SERVER['__test.role.creating-role'] = $role;
            })
            ->create();
        UserFactory::times(3)
            ->hasAttached($roles, ['admin' => 'Y'], 'roles')
            ->create();

        $this->assertCount(3, Role::all());

        $user = User::latest()->first();

        $this->assertCount(3, $user->roles);
        $this->assertSame('Y', $user->roles->first()->pivot->admin);

        $this->assertInstanceOf(Eloquent::class, $_SERVER['__test.role.creating-role']);

        unset($_SERVER['__test.role.creating-role']);
    }

    public function testBelongsToManyRelationshipWithExistingModelInstancesUsingArray()
    {
        $roles = RoleFactory::times(3)
            ->afterCreating(function ($role) {
                $_SERVER['__test.role.creating-role'] = $role;
            })
            ->create();
        UserFactory::times(3)
            ->hasAttached($roles->toArray(), ['admin' => 'Y'], 'roles')
            ->create();

        $this->assertCount(3, Role::all());

        $user = User::latest()->first();

        $this->assertCount(3, $user->roles);
        $this->assertSame('Y', $user->roles->first()->pivot->admin);

        $this->assertInstanceOf(Eloquent::class, $_SERVER['__test.role.creating-role']);

        unset($_SERVER['__test.role.creating-role']);
    }

    public function testBelongsToManyRelationshipWithExistingModelInstancesWithRelationshipNameImpliedFromModel()
    {
        $roles = RoleFactory::times(3)
            ->afterCreating(function ($role) {
                $_SERVER['__test.role.creating-role'] = $role;
            })
            ->create();
        UserFactory::times(3)
            ->hasAttached($roles, ['admin' => 'Y'])
            ->create();

        $this->assertCount(3, Role::all());

        $user = User::latest()->first();

        $this->assertCount(3, $user->factoryTestRoles);
        $this->assertSame('Y', $user->factoryTestRoles->first()->pivot->admin);

        $this->assertInstanceOf(Eloquent::class, $_SERVER['__test.role.creating-role']);

        unset($_SERVER['__test.role.creating-role']);
    }

    public function testSequences()
    {
        $users = UserFactory::times(2)->sequence(
            ['name' => 'Taylor Otwell'],
            ['name' => 'Abigail Otwell'],
        )->create();

        $this->assertSame('Taylor Otwell', $users[0]->name);
        $this->assertSame('Abigail Otwell', $users[1]->name);

        $user = UserFactory::new()
            ->hasAttached(
                RoleFactory::times(4),
                new Sequence(['admin' => 'Y'], ['admin' => 'N']),
                'roles'
            )
            ->create();

        $this->assertCount(4, $user->roles);

        $this->assertCount(2, $user->roles->filter(function ($role) {
            return $role->pivot->admin === 'Y';
        }));

        $this->assertCount(2, $user->roles->filter(function ($role) {
            return $role->pivot->admin === 'N';
        }));

        $users = UserFactory::times(2)->sequence(function ($sequence) {
            return ['name' => 'index: ' . $sequence->index];
        })->create();

        $this->assertSame('index: 0', $users[0]->name);
        $this->assertSame('index: 1', $users[1]->name);
    }

    public function testCountedSequence()
    {
        $factory = UserFactory::new()->forEachSequence(
            ['name' => 'Taylor Otwell'],
            ['name' => 'Abigail Otwell'],
            ['name' => 'Dayle Rees']
        );

        $class = new ReflectionClass($factory);
        $prop = $class->getProperty('count');
        $value = $prop->getValue($factory);

        $this->assertSame(3, $value);
    }

    public function testSequenceWithHasManyRelationship()
    {
        $users = UserFactory::times(2)
            ->sequence(
                ['name' => 'Abigail Otwell'],
                ['name' => 'Taylor Otwell'],
            )
            ->has(
                PostFactory::times(3)
                    ->state(['title' => 'Post'])
                    ->sequence(function ($sequence, $attributes, $user) {
                        return ['title' => $user->name . ' ' . $attributes['title'] . ' ' . ($sequence->index % 3 + 1)];
                    }),
                'posts'
            )
            ->create();

        $this->assertCount(2, User::all());
        $this->assertCount(6, Post::all());
        $this->assertCount(3, User::latest()->first()->posts);
        $this->assertEquals(
            Post::orderBy('title')->pluck('title')->all(),
            [
                'Abigail Otwell Post 1',
                'Abigail Otwell Post 2',
                'Abigail Otwell Post 3',
                'Taylor Otwell Post 1',
                'Taylor Otwell Post 2',
                'Taylor Otwell Post 3',
            ]
        );
    }

    public function testCrossJoinSequences()
    {
        $assert = function ($users) {
            $assertions = [
                ['first_name' => 'Thomas', 'last_name' => 'Anderson'],
                ['first_name' => 'Thomas', 'last_name' => 'Smith'],
                ['first_name' => 'Agent', 'last_name' => 'Anderson'],
                ['first_name' => 'Agent', 'last_name' => 'Smith'],
            ];

            foreach ($assertions as $key => $assertion) {
                $this->assertSame(
                    $assertion,
                    $users[$key]->only('first_name', 'last_name'),
                );
            }
        };

        $usersByClass = UserFactory::times(4)
            ->state(
                new CrossJoinSequence(
                    [['first_name' => 'Thomas'], ['first_name' => 'Agent']],
                    [['last_name' => 'Anderson'], ['last_name' => 'Smith']],
                ),
            )
            ->make();

        $assert($usersByClass);

        $usersByMethod = UserFactory::times(4)
            ->crossJoinSequence(
                [['first_name' => 'Thomas'], ['first_name' => 'Agent']],
                [['last_name' => 'Anderson'], ['last_name' => 'Smith']],
            )
            ->make();

        $assert($usersByMethod);
    }

    public function testResolveNestedModelFactories()
    {
        Factory::useNamespace('Factories\\');

        $resolves = [
            'App\Foo' => 'Factories\FooFactory',
            'App\Models\Foo' => 'Factories\FooFactory',
            'App\Models\Nested\Foo' => 'Factories\Nested\FooFactory',
            'App\Models\Really\Nested\Foo' => 'Factories\Really\Nested\FooFactory',
        ];

        foreach ($resolves as $model => $factory) {
            $this->assertEquals($factory, Factory::resolveFactoryName($model));
        }
    }

    public function testResolveNestedModelNameFromFactory()
    {
        Container::getInstance()->instance(Application::class, $app = m::mock(Application::class));
        $app->shouldReceive('getNamespace')->andReturn('Hypervel\Tests\Database\Laravel\Fixtures\\');

        Factory::useNamespace('Hypervel\Tests\Database\Laravel\Fixtures\Factories\\');

        $factory = Price::factory();

        $this->assertSame(Price::class, $factory->modelName());
    }

    public function testResolveNonAppNestedModelFactories()
    {
        Container::getInstance()->instance(Application::class, $app = m::mock(Application::class));
        $app->shouldReceive('getNamespace')->andReturn('Foo\\');

        Factory::useNamespace('Factories\\');

        $resolves = [
            'Foo\Bar' => 'Factories\BarFactory',
            'Foo\Models\Bar' => 'Factories\BarFactory',
            'Foo\Models\Nested\Bar' => 'Factories\Nested\BarFactory',
            'Foo\Models\Really\Nested\Bar' => 'Factories\Really\Nested\BarFactory',
        ];

        foreach ($resolves as $model => $factory) {
            $this->assertEquals($factory, Factory::resolveFactoryName($model));
        }
    }

    public function testModelHasFactory()
    {
        Factory::guessFactoryNamesUsing(function ($model) {
            return $model . 'Factory';
        });

        $this->assertInstanceOf(UserFactory::class, User::factory());
    }

    public function testDynamicHasAndForMethods()
    {
        Factory::guessFactoryNamesUsing(function ($model) {
            return $model . 'Factory';
        });

        $user = UserFactory::new()->hasPosts(3)->create();

        $this->assertCount(3, $user->posts);

        $post = PostFactory::new()
            ->forAuthor(['name' => 'Taylor Otwell'])
            ->hasComments(2)
            ->create();

        $this->assertInstanceOf(User::class, $post->author);
        $this->assertSame('Taylor Otwell', $post->author->name);
        $this->assertCount(2, $post->comments);
    }

    public function testCanBeMacroable()
    {
        $factory = UserFactory::new();
        $factory->macro('getFoo', function () {
            return 'Hello World';
        });

        $this->assertSame('Hello World', $factory->getFoo());
    }

    public function testFactoryCanConditionallyExecuteCode()
    {
        UserFactory::new()
            ->when(true, function () {
                $this->assertTrue(true);
            })
            ->when(false, function () {
                $this->fail('Unreachable code that has somehow been reached.');
            })
            ->unless(false, function () {
                $this->assertTrue(true);
            })
            ->unless(true, function () {
                $this->fail('Unreachable code that has somehow been reached.');
            });
    }

    public function testDynamicTrashedStateForSoftdeletesModels()
    {
        $now = Carbon::create(2020, 6, 7, 8, 9);
        Carbon::setTestNow($now);
        $post = PostFactory::new()->trashed()->create();

        $this->assertTrue($post->deleted_at->equalTo($now->subDay()));

        $deleted_at = Carbon::create(2020, 1, 2, 3, 4, 5);
        $post = PostFactory::new()->trashed($deleted_at)->create();

        $this->assertTrue($deleted_at->equalTo($post->deleted_at));

        Carbon::setTestNow();
    }

    public function testDynamicTrashedStateRespectsExistingState()
    {
        $now = Carbon::create(2020, 6, 7, 8, 9);
        Carbon::setTestNow($now);
        $comment = CommentFactory::new()->trashed()->create();

        $this->assertTrue($comment->deleted_at->equalTo($now->subWeek()));

        Carbon::setTestNow();
    }

    public function testDynamicTrashedStateThrowsExceptionWhenNotASoftdeletesModel()
    {
        $this->expectException(BadMethodCallException::class);
        UserFactory::new()->trashed()->create();
    }

    public function testModelInstancesCanBeUsedInPlaceOfNestedFactories()
    {
        Factory::guessFactoryNamesUsing(function ($model) {
            return $model . 'Factory';
        });

        $user = UserFactory::new()->create();
        $post = PostFactory::new()
            ->recycle($user)
            ->hasComments(2)
            ->create();

        $this->assertSame(1, User::count());
        $this->assertEquals($user->id, $post->user_id);
        $this->assertEquals($user->id, $post->comments[0]->user_id);
        $this->assertEquals($user->id, $post->comments[1]->user_id);
    }

    public function testForMethodRecyclesModels()
    {
        Factory::guessFactoryNamesUsing(function ($model) {
            return $model . 'Factory';
        });

        $user = UserFactory::new()->create();
        $post = PostFactory::new()
            ->recycle($user)
            ->for(UserFactory::new())
            ->create();

        $this->assertSame(1, User::count());
    }

    public function testHasMethodDoesNotReassignTheParent()
    {
        Factory::guessFactoryNamesUsing(function ($model) {
            return $model . 'Factory';
        });

        $post = PostFactory::new()->create();
        $user = UserFactory::new()
            ->recycle($post)
            // The recycled post already belongs to a user, so it shouldn't be recycled here.
            ->has(PostFactory::new(), 'posts')
            ->create();

        $this->assertSame(2, Post::count());
    }

    public function testMultipleModelsCanBeProvidedToRecycle()
    {
        Factory::guessFactoryNamesUsing(function ($model) {
            return $model . 'Factory';
        });

        $users = UserFactory::new()->count(3)->create();

        $posts = PostFactory::new()
            ->recycle($users)
            ->for(UserFactory::new())
            ->has(CommentFactory::new()->count(5), 'comments')
            ->count(2)
            ->create();

        $this->assertSame(3, User::count());
    }

    public function testRecycledModelsCanBeCombinedWithMultipleCalls()
    {
        Factory::guessFactoryNamesUsing(function ($model) {
            return $model . 'Factory';
        });

        $users = UserFactory::new()
            ->count(2)
            ->create();
        $posts = PostFactory::new()
            ->recycle($users)
            ->count(2)
            ->create();
        $additionalUser = UserFactory::new()
            ->create();
        $additionalPost = PostFactory::new()
            ->recycle($additionalUser)
            ->create();

        $this->assertSame(3, User::count());
        $this->assertSame(3, Post::count());

        $comments = CommentFactory::new()
            ->recycle($users)
            ->recycle($posts)
            ->recycle([$additionalUser, $additionalPost])
            ->count(5)
            ->create();

        $this->assertSame(3, User::count());
        $this->assertSame(3, Post::count());
    }

    public function testNoModelsCanBeProvidedToRecycle()
    {
        Factory::guessFactoryNamesUsing(function ($model) {
            return $model . 'Factory';
        });

        $posts = PostFactory::new()
            ->recycle([])
            ->count(2)
            ->create();

        $this->assertSame(2, Post::count());
        $this->assertSame(2, User::count());
    }

    public function testCanDisableRelationships()
    {
        $post = PostFactory::new()
            ->withoutParents()
            ->make();

        $this->assertNull($post->user_id);
    }

    public function testCanDisableRelationshipsExplicitlyByModelName()
    {
        $comment = CommentFactory::new()
            ->withoutParents([User::class])
            ->make();

        $this->assertNull($comment->user_id);
        $this->assertNotNull($comment->commentable->id);
    }

    public function testCanDisableRelationshipsExplicitlyByAttributeName()
    {
        $comment = CommentFactory::new()
            ->withoutParents(['user_id'])
            ->make();

        $this->assertNull($comment->user_id);
        $this->assertNotNull($comment->commentable->id);
    }

    public function testCanDisableRelationshipsExplicitlyByBothAttributeNameAndModelName()
    {
        $comment = CommentFactory::new()
            ->withoutParents(['user_id', Post::class])
            ->make();

        $this->assertNull($comment->user_id);
        $this->assertNull($comment->commentable->id);
    }

    public function testCanDefaultToWithoutParents()
    {
        PostFactory::dontExpandRelationshipsByDefault();

        $post = PostFactory::new()->make();
        $this->assertNull($post->user_id);

        PostFactory::expandRelationshipsByDefault();
        $postWithParents = PostFactory::new()->create();
        $this->assertNotNull($postWithParents->user_id);
    }

    public function testFactoryModelNamesCorrect()
    {
        $this->assertEquals(UseFactoryAttribute::factory()->modelName(), UseFactoryAttribute::class);
        $this->assertEquals(GuessModel::factory()->modelName(), GuessModel::class);
    }

    public function testFactoryGlobalModelResolver()
    {
        Factory::guessModelNamesUsing(function ($factory) {
            return __NAMESPACE__ . '\\' . Str::replaceLast('Factory', '', class_basename($factory::class));
        });

        $this->assertEquals(GuessModel::factory()->modelName(), GuessModel::class);
        $this->assertEquals(UseFactoryAttribute::factory()->modelName(), UseFactoryAttribute::class);

        $this->assertEquals(UseFactoryAttributeFactory::new()->modelName(), UseFactoryAttribute::class);
        $this->assertEquals(GuessModelFactory::new()->modelName(), GuessModel::class);
    }

    public function testFactoryModelHasManyRelationshipHasPendingAttributes()
    {
        User::factory()->has(new PostFactory(), 'postsWithFooBarBazAsTitle')->create();

        $this->assertEquals('foo bar baz', Post::first()->title);
    }

    public function testFactoryModelHasManyRelationshipHasPendingAttributesOverride()
    {
        User::factory()->has((new PostFactory())->state(['title' => 'other title']), 'postsWithFooBarBazAsTitle')->create();

        $this->assertEquals('other title', Post::first()->title);
    }

    public function testFactoryModelHasOneRelationshipHasPendingAttributes()
    {
        User::factory()->has(new PostFactory(), 'postWithFooBarBazAsTitle')->create();

        $this->assertEquals('foo bar baz', Post::first()->title);
    }

    public function testFactoryModelHasOneRelationshipHasPendingAttributesOverride()
    {
        User::factory()->has((new PostFactory())->state(['title' => 'other title']), 'postWithFooBarBazAsTitle')->create();

        $this->assertEquals('other title', Post::first()->title);
    }

    public function testFactoryModelBelongsToManyRelationshipHasPendingAttributes()
    {
        User::factory()->has(new RoleFactory(), 'rolesWithFooBarBazAsName')->create();

        $this->assertEquals('foo bar baz', Role::first()->name);
    }

    public function testFactoryModelBelongsToManyRelationshipHasPendingAttributesOverride()
    {
        User::factory()->has((new RoleFactory())->state(['name' => 'other name']), 'rolesWithFooBarBazAsName')->create();

        $this->assertEquals('other name', Role::first()->name);
    }

    public function testFactoryModelMorphManyRelationshipHasPendingAttributes()
    {
        (new PostFactory())->has(new CommentFactory(), 'commentsWithFooBarBazAsBody')->create();

        $this->assertEquals('foo bar baz', Comment::first()->body);
    }

    public function testFactoryModelMorphManyRelationshipHasPendingAttributesOverride()
    {
        (new PostFactory())->has((new CommentFactory())->state(['body' => 'other body']), 'commentsWithFooBarBazAsBody')->create();

        $this->assertEquals('other body', Comment::first()->body);
    }

    public function testFactoryCanInsert()
    {
        (new PostFactory())
            ->count(5)
            ->recycle([
                (new UserFactory())->create(['name' => Name::Taylor]),
                (new UserFactory())->create(['name' => Name::Shad, 'created_at' => now()]),
            ])
            ->state(['title' => 'hello'])
            ->insert();
        $this->assertCount(5, $posts = Post::query()->where('title', 'hello')->get());
        $this->assertEquals(strtoupper($posts[0]->user->name), $posts[0]->upper_case_name);
        $this->assertEquals(
            2,
            ($users = User::query()->get())->count()
        );
        $this->assertCount(1, $users->where('name', 'totwell'));
        $this->assertCount(1, $users->where('name', 'shaedrich'));
    }

    public function testFactoryCanInsertWithHidden()
    {
        (new UserFactory())->forEachSequence(['name' => Name::Taylor, 'options' => 'abc'])->insert();
        $user = DB::table('users')->sole();
        $this->assertEquals('abc', $user->options);
        $userModel = User::query()->sole();
        $this->assertEquals('abc', $userModel->options);
    }

    public function testFactoryCanInsertWithArrayCasts()
    {
        (new UserWithArrayFactory())->count(2)->insert();
        $users = DB::table('users')->get();
        foreach ($users as $user) {
            $this->assertEquals(['rtj'], json_decode($user->options, true));
            $createdAt = Carbon::parse($user->created_at);
            $updatedAt = Carbon::parse($user->updated_at);
            $this->assertEquals($updatedAt, $createdAt);
        }
    }

    /**
     * Get a database connection instance.
     *
     * @return \Hypervel\Database\ConnectionInterface
     */
    protected function connection()
    {
        return Eloquent::getConnectionResolver()->connection();
    }

    /**
     * Get a schema builder instance.
     *
     * @return \Hypervel\Database\Schema\Builder
     */
    protected function schema()
    {
        return $this->connection()->getSchemaBuilder();
    }
}

class UserFactory extends Factory
{
    protected ?string $model = User::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'options' => null,
        ];
    }
}

class User extends Eloquent
{
    use HasFactory;

    protected ?string $table = 'users';

    protected array $hidden = ['options'];

    protected array $withCount = ['posts'];

    protected array $with = ['posts'];

    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id');
    }

    public function postsWithFooBarBazAsTitle()
    {
        return $this->hasMany(Post::class, 'user_id')->withAttributes(['title' => 'foo bar baz']);
    }

    public function postWithFooBarBazAsTitle()
    {
        return $this->hasOne(Post::class, 'user_id')->withAttributes(['title' => 'foo bar baz']);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id')->withPivot('admin');
    }

    public function rolesWithFooBarBazAsName()
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id')->withPivot('admin')->withAttributes(['name' => 'foo bar baz']);
    }

    public function factoryTestRoles()
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id')->withPivot('admin');
    }
}

class PostFactory extends Factory
{
    protected ?string $model = Post::class;

    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
            'title' => $this->faker->name(),
        ];
    }
}

class Post extends Eloquent
{
    use SoftDeletes;

    protected ?string $table = 'posts';

    protected array $appends = ['upper_case_name'];

    public function upperCaseName(): Attribute
    {
        return Attribute::get(fn ($attr) => Str::upper($this->user->name));
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function factoryTestUser()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function commentsWithFooBarBazAsBody()
    {
        return $this->morphMany(Comment::class, 'commentable')->withAttributes(['body' => 'foo bar baz']);
    }
}

class CommentFactory extends Factory
{
    protected ?string $model = Comment::class;

    public function definition(): array
    {
        return [
            'commentable_id' => PostFactory::new(),
            'commentable_type' => Post::class,
            'user_id' => fn () => UserFactory::new(),
            'body' => $this->faker->name(),
        ];
    }

    public function trashed()
    {
        return $this->state([
            'deleted_at' => Carbon::now()->subWeek(),
        ]);
    }
}

class Comment extends Eloquent
{
    use SoftDeletes;

    protected ?string $table = 'comments';

    public function commentable()
    {
        return $this->morphTo();
    }
}

class RoleFactory extends Factory
{
    protected ?string $model = Role::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
        ];
    }
}

class Role extends Eloquent
{
    protected ?string $table = 'roles';

    protected array $touches = ['users'];

    public function users()
    {
        return $this->belongsToMany(User::class, 'role_user', 'role_id', 'user_id')->withPivot('admin');
    }
}

class GuessModelFactory extends Factory
{
    protected static function appNamespace(): string
    {
        return __NAMESPACE__ . '\\';
    }

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
        ];
    }
}

class GuessModel extends Eloquent
{
    use HasFactory;

    protected static $factory = GuessModelFactory::class;
}

class UseFactoryAttributeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
        ];
    }
}

#[UseFactory(UseFactoryAttributeFactory::class)]
class UseFactoryAttribute extends Eloquent
{
    use HasFactory;
}

class UserWithArray extends Eloquent
{
    protected ?string $table = 'users';

    protected function casts(): array
    {
        return ['options' => 'array'];
    }
}

class UserWithArrayFactory extends Factory
{
    protected ?string $model = UserWithArray::class;

    public function definition(): array
    {
        return [
            'name' => 'killer mike',
            'options' => ['rtj'],
        ];
    }
}

enum Name: string
{
    case Taylor = 'totwell';
    case Shad = 'shaedrich';
}
