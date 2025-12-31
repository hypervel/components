<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Eloquent\Factories;

use BadMethodCallException;
use Carbon\Carbon;
use Hyperf\Database\Model\SoftDeletes;
use Hypervel\Database\Eloquent\Attributes\UseFactory;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Factories\CrossJoinSequence;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Factories\Sequence;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Contracts\Application;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Core\Database\Fixtures\Models\Price;
use Mockery as m;
use ReflectionClass;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEloquentFactoryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => $this->shouldSeed(),
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/migrations',
        ];
    }

    /**
     * Tear down the database schema.
     */
    protected function tearDown(): void
    {
        m::close();
        Factory::flushState();

        parent::tearDown();
    }

    public function testBasicModelCanBeCreated()
    {
        $user = FactoryTestUserFactory::new()->create();
        $this->assertInstanceOf(Model::class, $user);

        $user = FactoryTestUserFactory::new()->createOne();
        $this->assertInstanceOf(Model::class, $user);

        $user = FactoryTestUserFactory::new()->create(['name' => 'Taylor Otwell']);
        $this->assertInstanceOf(Model::class, $user);
        $this->assertSame('Taylor Otwell', $user->name);

        $user = FactoryTestUserFactory::new()->set('name', 'Taylor Otwell')->create();
        $this->assertInstanceOf(Model::class, $user);
        $this->assertSame('Taylor Otwell', $user->name);

        $users = FactoryTestUserFactory::new()->createMany([
            ['name' => 'Taylor Otwell'],
            ['name' => 'Jeffrey Way'],
        ]);
        $this->assertInstanceOf(Collection::class, $users);
        $this->assertCount(2, $users);
        $this->assertSame('Taylor Otwell', $users[0]->name);
        $this->assertSame('Jeffrey Way', $users[1]->name);

        $users = FactoryTestUserFactory::new()->createMany(2);
        $this->assertInstanceOf(Collection::class, $users);
        $this->assertCount(2, $users);
        $this->assertInstanceOf(FactoryTestUser::class, $users->first());

        $users = FactoryTestUserFactory::times(2)->createMany();
        $this->assertInstanceOf(Collection::class, $users);
        $this->assertCount(2, $users);
        $this->assertInstanceOf(FactoryTestUser::class, $users->first());

        $users = FactoryTestUserFactory::times(2)->createMany();
        $this->assertInstanceOf(Collection::class, $users);
        $this->assertCount(2, $users);
        $this->assertInstanceOf(FactoryTestUser::class, $users->first());

        $users = FactoryTestUserFactory::times(3)->createMany([
            ['name' => 'Taylor Otwell'],
            ['name' => 'Jeffrey Way'],
        ]);
        $this->assertInstanceOf(Collection::class, $users);
        $this->assertCount(2, $users);
        $this->assertInstanceOf(FactoryTestUser::class, $users->first());

        $users = FactoryTestUserFactory::new()->createMany();
        $this->assertInstanceOf(Collection::class, $users);
        $this->assertCount(1, $users);
        $this->assertInstanceOf(FactoryTestUser::class, $users->first());

        $users = FactoryTestUserFactory::times(10)->create();
        $this->assertCount(10, $users);
    }

    public function testExpandedClosureAttributesAreResolvedAndPassedToClosures()
    {
        $user = FactoryTestUserFactory::new()->create([
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
        $post = FactoryTestPostFactory::new()->create([
            'title' => 'post',
            'user_id' => fn ($attributes) => FactoryTestUserFactory::new([
                'options' => $attributes['title'] . '-options',
            ]),
        ]);

        $this->assertEquals('post-options', $post->user->options);
    }

    public function testMakeCreatesUnpersistedModelInstance()
    {
        $user = FactoryTestUserFactory::new()->makeOne();
        $this->assertInstanceOf(Model::class, $user);

        $user = FactoryTestUserFactory::new()->make(['name' => 'Taylor Otwell']);

        $this->assertInstanceOf(Model::class, $user);
        $this->assertSame('Taylor Otwell', $user->name);
        $this->assertCount(0, FactoryTestUser::all());
    }

    public function testBasicModelAttributesCanBeCreated()
    {
        $user = FactoryTestUserFactory::new()->raw();
        $this->assertIsArray($user);

        $user = FactoryTestUserFactory::new()->raw(['name' => 'Taylor Otwell']);
        $this->assertIsArray($user);
        $this->assertSame('Taylor Otwell', $user['name']);
    }

    public function testExpandedModelAttributesCanBeCreated()
    {
        $post = FactoryTestPostFactory::new()->raw();
        $this->assertIsArray($post);

        $post = FactoryTestPostFactory::new()->raw(['title' => 'Test Title']);
        $this->assertIsArray($post);
        $this->assertIsInt($post['user_id']);
        $this->assertSame('Test Title', $post['title']);
    }

    public function testLazyModelAttributesCanBeCreated()
    {
        $userFunction = FactoryTestUserFactory::new()->lazy();
        $this->assertIsCallable($userFunction);
        $this->assertInstanceOf(Model::class, $userFunction());

        $userFunction = FactoryTestUserFactory::new()->lazy(['name' => 'Taylor Otwell']);
        $this->assertIsCallable($userFunction);

        $user = $userFunction();
        $this->assertInstanceOf(Model::class, $user);
        $this->assertSame('Taylor Otwell', $user->name);
    }

    public function testMultipleModelAttributesCanBeCreated()
    {
        $posts = FactoryTestPostFactory::times(10)->raw();
        $this->assertIsArray($posts);

        $this->assertCount(10, $posts);
    }

    public function testAfterCreatingAndMakingCallbacksAreCalled()
    {
        $scope = [];

        $user = FactoryTestUserFactory::new()
            ->afterMaking(function ($user) use (&$scope) {
                $scope['__test.user.making'] = $user;
            })
            ->afterCreating(function ($user) use (&$scope) {
                $scope['__test.user.creating'] = $user;
            })
            ->create();

        $this->assertSame($user, $scope['__test.user.making']);
        $this->assertSame($user, $scope['__test.user.creating']);
    }

    public function testHasManyRelationship()
    {
        $scope = [];

        $users = FactoryTestUserFactory::times(10)
            ->has(
                FactoryTestPostFactory::times(3)
                    ->state(function ($attributes, $user) use (&$scope) {
                        // Test parent is passed to child state mutations...
                        $scope['__test.post.state-user'] = $user;

                        return [];
                    })
                    // Test parents passed to callback...
                    ->afterCreating(function ($post, $user) use (&$scope) {
                        $scope['__test.post.creating-post'] = $post;
                        $scope['__test.post.creating-user'] = $user;
                    }),
                'posts'
            )
            ->create();

        $this->assertCount(10, FactoryTestUser::all());
        $this->assertCount(30, FactoryTestPost::all());
        $this->assertCount(3, FactoryTestUser::latest()->first()->posts);

        $this->assertInstanceOf(Model::class, $scope['__test.post.creating-post']);
        $this->assertInstanceOf(Model::class, $scope['__test.post.creating-user']);
        $this->assertInstanceOf(Model::class, $scope['__test.post.state-user']);
    }

    public function testBelongsToRelationship()
    {
        $posts = FactoryTestPostFactory::times(3)
            ->for(FactoryTestUserFactory::new(['name' => 'Taylor Otwell']), 'user')
            ->create();

        $this->assertCount(3, $posts->filter(function ($post) {
            return $post->user->name === 'Taylor Otwell';
        }));

        $this->assertCount(1, FactoryTestUser::all());
        $this->assertCount(3, FactoryTestPost::all());
    }

    public function testBelongsToRelationshipWithExistingModelInstance()
    {
        $user = FactoryTestUserFactory::new(['name' => 'Taylor Otwell'])->create();
        $posts = FactoryTestPostFactory::times(3)
            ->for($user, 'user')
            ->create();

        $this->assertCount(3, $posts->filter(function ($post) use ($user) {
            return $post->user->is($user);
        }));

        $this->assertCount(1, FactoryTestUser::all());
        $this->assertCount(3, FactoryTestPost::all());
    }

    public function testBelongsToRelationshipWithExistingModelInstanceWithRelationshipNameImpliedFromModel()
    {
        $user = FactoryTestUserFactory::new(['name' => 'Taylor Otwell'])->create();
        $posts = FactoryTestPostFactory::times(3)
            ->for($user)
            ->create();

        $this->assertCount(3, $posts->filter(function ($post) use ($user) {
            return $post->factoryTestUser->is($user);
        }));

        $this->assertCount(1, FactoryTestUser::all());
        $this->assertCount(3, FactoryTestPost::all());
    }

    public function testMorphToRelationship()
    {
        $posts = FactoryTestCommentFactory::times(3)
            ->for(FactoryTestPostFactory::new(['title' => 'Test Title']), 'commentable')
            ->create();

        $this->assertSame('Test Title', FactoryTestPost::first()->title);
        $this->assertCount(3, FactoryTestPost::first()->comments);

        $this->assertCount(1, FactoryTestPost::all());
        $this->assertCount(3, FactoryTestComment::all());
    }

    public function testMorphToRelationshipWithExistingModelInstance()
    {
        $post = FactoryTestPostFactory::new(['title' => 'Test Title'])->create();
        $comments = FactoryTestCommentFactory::times(3)
            ->for($post, 'commentable')
            ->create();

        $this->assertSame('Test Title', $post->title);
        $this->assertCount(3, $post->comments);

        $this->assertCount(1, FactoryTestPost::all());
        $this->assertCount(3, FactoryTestComment::all());
    }

    public function testBelongsToManyRelationship()
    {
        $scope = [];

        $users = FactoryTestUserFactory::times(3)
            ->hasAttached(
                FactoryTestRoleFactory::times(3)->afterCreating(function ($role, $user) use (&$scope) {
                    $scope['__test.role.creating-role'] = $role;
                    $scope['__test.role.creating-user'] = $user;
                }),
                ['admin' => 'Y'],
                'roles'
            )
            ->create();

        $this->assertCount(9, FactoryTestRole::all());

        $user = FactoryTestUser::latest()->first();

        $this->assertCount(3, $user->roles);
        $this->assertSame('Y', $user->roles->first()->pivot->admin);

        $this->assertInstanceOf(FactoryTestRole::class, $scope['__test.role.creating-role']);
        $this->assertInstanceOf(FactoryTestUser::class, $scope['__test.role.creating-user']);
    }

    public function testBelongsToManyRelationshipRelatedModelsSetOnInstanceWhenTouchingOwner()
    {
        $user = FactoryTestUserFactory::new()->create();
        $role = FactoryTestRoleFactory::new()->hasAttached($user, [], 'users')->create();

        $this->assertCount(1, $role->users);
    }

    public function testRelationCanBeLoadedBeforeModelIsCreated()
    {
        $user = FactoryTestUserFactory::new(['name' => 'Taylor Otwell'])->createOne();

        $post = FactoryTestPostFactory::new()
            ->for($user, 'user')
            ->afterMaking(function (FactoryTestPost $post) {
                $post->load('user');
            })
            ->createOne();

        $this->assertTrue($post->relationLoaded('user'));
        $this->assertTrue($post->user->is($user));

        $this->assertCount(1, FactoryTestUser::all());
        $this->assertCount(1, FactoryTestPost::all());
    }

    public function testBelongsToManyRelationshipWithExistingModelInstances()
    {
        $scope = [];

        $roles = FactoryTestRoleFactory::times(3)
            ->afterCreating(function ($role) use (&$scope) {
                $scope['__test.role.creating-role'] = $role;
            })
            ->create();
        FactoryTestUserFactory::times(3)
            ->hasAttached($roles, ['admin' => 'Y'], 'roles')
            ->create();

        $this->assertCount(3, FactoryTestRole::all());

        $user = FactoryTestUser::latest()->first();

        $this->assertCount(3, $user->roles);
        $this->assertSame('Y', $user->roles->first()->pivot->admin);

        $this->assertInstanceOf(FactoryTestRole::class, $scope['__test.role.creating-role']);
    }

    public function testBelongsToManyRelationshipWithExistingModelInstancesUsingArray()
    {
        $scope = [];

        $roles = FactoryTestRoleFactory::times(3)
            ->afterCreating(function ($role) use (&$scope) {
                $scope['__test.role.creating-role'] = $role;
            })
            ->create();
        FactoryTestUserFactory::times(3)
            ->hasAttached($roles->toArray(), ['admin' => 'Y'], 'roles')
            ->create();

        $this->assertCount(3, FactoryTestRole::all());

        $user = FactoryTestUser::latest()->first();

        $this->assertCount(3, $user->roles);
        $this->assertSame('Y', $user->roles->first()->pivot->admin);

        $this->assertInstanceOf(FactoryTestRole::class, $scope['__test.role.creating-role']);
    }

    public function testBelongsToManyRelationshipWithExistingModelInstancesWithRelationshipNameImpliedFromModel()
    {
        $scope = [];

        $roles = FactoryTestRoleFactory::times(3)
            ->afterCreating(function ($role) use (&$scope) {
                $scope['__test.role.creating-role'] = $role;
            })
            ->create();
        FactoryTestUserFactory::times(3)
            ->hasAttached($roles, ['admin' => 'Y'])
            ->create();

        $this->assertCount(3, FactoryTestRole::all());

        $user = FactoryTestUser::latest()->first();

        $this->assertCount(3, $user->factoryTestRoles);
        $this->assertSame('Y', $user->factoryTestRoles->first()->pivot->admin);

        $this->assertInstanceOf(FactoryTestRole::class, $scope['__test.role.creating-role']);
    }

    public function testSequences()
    {
        $users = FactoryTestUserFactory::times(2)->sequence(
            ['name' => 'Taylor Otwell'],
            ['name' => 'Abigail Otwell'],
        )->create();

        $this->assertSame('Taylor Otwell', $users[0]->name);
        $this->assertSame('Abigail Otwell', $users[1]->name);

        $user = FactoryTestUserFactory::new()
            ->hasAttached(
                FactoryTestRoleFactory::times(4),
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

        $users = FactoryTestUserFactory::times(2)->sequence(function ($sequence) {
            return ['name' => 'index: ' . $sequence->index];
        })->create();

        $this->assertSame('index: 0', $users[0]->name);
        $this->assertSame('index: 1', $users[1]->name);
    }

    public function testCountedSequence()
    {
        $factory = FactoryTestUserFactory::new()->forEachSequence(
            ['name' => 'Taylor Otwell'],
            ['name' => 'Abigail Otwell'],
            ['name' => 'Dayle Rees']
        );

        $class = new ReflectionClass($factory);
        $prop = $class->getProperty('count');
        $value = $prop->getValue($factory);

        $this->assertSame(3, $value);
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

        $usersByClass = FactoryTestUserFactory::times(4)
            ->state(
                new CrossJoinSequence(
                    [['first_name' => 'Thomas'], ['first_name' => 'Agent']],
                    [['last_name' => 'Anderson'], ['last_name' => 'Smith']],
                ),
            )
            ->make();

        $assert($usersByClass);

        $usersByMethod = FactoryTestUserFactory::times(4)
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
        $application = $this->mock(Application::class);
        $application->shouldReceive('getNamespace')->andReturn('Hypervel\Tests\Core\Database\Fixtures\\');

        Factory::useNamespace('Hypervel\Tests\Core\Database\Fixtures\Factories\\');

        $factory = Price::factory();

        $this->assertSame(Price::class, $factory->modelName());
    }

    public function testResolveNonAppNestedModelFactories()
    {
        $application = $this->mock(Application::class);
        $application->shouldReceive('getNamespace')->andReturn('Foo\\');

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

        $this->assertInstanceOf(FactoryTestUserFactory::class, FactoryTestUser::factory());
    }

    public function testDynamicHasAndForMethods()
    {
        Factory::guessFactoryNamesUsing(function ($model) {
            return $model . 'Factory';
        });

        $user = FactoryTestUserFactory::new()->hasPosts(3)->create();

        $this->assertCount(3, $user->posts);

        $post = FactoryTestPostFactory::new()
            ->forAuthor(['name' => 'Taylor Otwell'])
            ->hasComments(2)
            ->create();

        $this->assertInstanceOf(FactoryTestUser::class, $post->author);
        $this->assertSame('Taylor Otwell', $post->author->name);
        $this->assertCount(2, $post->comments);
    }

    public function testCanBeMacroable()
    {
        $factory = FactoryTestUserFactory::new();
        $factory->macro('getFoo', function () {
            return 'Hello World';
        });

        $this->assertSame('Hello World', $factory->getFoo());
    }

    public function testFactoryCanConditionallyExecuteCode()
    {
        FactoryTestUserFactory::new()
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
        $post = FactoryTestPostFactory::new()->trashed()->create();

        $this->assertTrue($post->deleted_at->equalTo($now->subDay()));

        $deleted_at = Carbon::create(2020, 1, 2, 3, 4, 5);
        $post = FactoryTestPostFactory::new()->trashed($deleted_at)->create();

        $this->assertTrue($deleted_at->equalTo($post->deleted_at));

        Carbon::setTestNow();
    }

    public function testDynamicTrashedStateRespectsExistingState()
    {
        $now = Carbon::create(2020, 6, 7, 8, 9);
        Carbon::setTestNow($now);
        $comment = FactoryTestCommentFactory::new()->trashed()->create();

        $this->assertTrue($comment->deleted_at->equalTo($now->subWeek()));

        Carbon::setTestNow();
    }

    public function testDynamicTrashedStateThrowsExceptionWhenNotASoftdeletesModel()
    {
        $this->expectException(BadMethodCallException::class);
        FactoryTestUserFactory::new()->trashed()->create();
    }

    public function testModelInstancesCanBeUsedInPlaceOfNestedFactories()
    {
        Factory::guessFactoryNamesUsing(function ($model) {
            return $model . 'Factory';
        });

        $user = FactoryTestUserFactory::new()->create();
        $post = FactoryTestPostFactory::new()
            ->recycle($user)
            ->hasComments(2)
            ->create();

        $this->assertSame(1, FactoryTestUser::count());
        $this->assertEquals($user->id, $post->user_id);
        $this->assertEquals($user->id, $post->comments[0]->user_id);
        $this->assertEquals($user->id, $post->comments[1]->user_id);
    }

    public function testForMethodRecyclesModels()
    {
        Factory::guessFactoryNamesUsing(function ($model) {
            return $model . 'Factory';
        });

        $user = FactoryTestUserFactory::new()->create();
        $post = FactoryTestPostFactory::new()
            ->recycle($user)
            ->for(FactoryTestUserFactory::new())
            ->create();

        $this->assertSame(1, FactoryTestUser::count());
    }

    public function testMultipleModelsCanBeProvidedToRecycle()
    {
        Factory::guessFactoryNamesUsing(function ($model) {
            return $model . 'Factory';
        });

        $users = FactoryTestUserFactory::new()->count(3)->create();

        $posts = FactoryTestPostFactory::new()
            ->recycle($users)
            ->for(FactoryTestUserFactory::new())
            ->has(FactoryTestCommentFactory::new()->count(5), 'comments')
            ->count(2)
            ->create();

        $this->assertSame(3, FactoryTestUser::count());
    }

    public function testRecycledModelsCanBeCombinedWithMultipleCalls()
    {
        Factory::guessFactoryNamesUsing(function ($model) {
            return $model . 'Factory';
        });

        $users = FactoryTestUserFactory::new()
            ->count(2)
            ->create();
        $posts = FactoryTestPostFactory::new()
            ->recycle($users)
            ->count(2)
            ->create();
        $additionalUser = FactoryTestUserFactory::new()
            ->create();
        $additionalPost = FactoryTestPostFactory::new()
            ->recycle($additionalUser)
            ->create();

        $this->assertSame(3, FactoryTestUser::count());
        $this->assertSame(3, FactoryTestPost::count());

        $comments = FactoryTestCommentFactory::new()
            ->recycle($users)
            ->recycle($posts)
            ->recycle([$additionalUser, $additionalPost])
            ->count(5)
            ->create();

        $this->assertSame(3, FactoryTestUser::count());
        $this->assertSame(3, FactoryTestPost::count());
    }

    public function testNoModelsCanBeProvidedToRecycle()
    {
        Factory::guessFactoryNamesUsing(function ($model) {
            return $model . 'Factory';
        });

        $posts = FactoryTestPostFactory::new()
            ->recycle([])
            ->count(2)
            ->create();

        $this->assertSame(2, FactoryTestPost::count());
        $this->assertSame(2, FactoryTestUser::count());
    }

    public function testCanDisableRelationships()
    {
        $post = FactoryTestPostFactory::new()
            ->withoutParents()
            ->make();

        $this->assertNull($post->user_id);
    }

    public function testUseFactoryAttributeResolvesFactory()
    {
        $factory = FactoryTestModelWithUseFactory::factory();

        $this->assertInstanceOf(FactoryTestModelWithUseFactoryFactory::class, $factory);
    }

    public function testUseFactoryAttributeResolvesCorrectModelName()
    {
        $factory = FactoryTestModelWithUseFactory::factory();

        $this->assertSame(FactoryTestModelWithUseFactory::class, $factory->modelName());
    }

    public function testUseFactoryAttributeWorksWithCount()
    {
        $models = FactoryTestModelWithUseFactory::factory(3)->make();

        $this->assertCount(3, $models);
        $this->assertInstanceOf(FactoryTestModelWithUseFactory::class, $models->first());
    }

    public function testStaticFactoryPropertyTakesPrecedenceOverUseFactoryAttribute()
    {
        $factory = FactoryTestModelWithStaticFactoryAndAttribute::factory();

        // Should use the static $factory property, not the attribute
        $this->assertInstanceOf(FactoryTestModelWithStaticFactory::class, $factory);
    }

    public function testModelWithoutUseFactoryFallsBackToConvention()
    {
        Factory::guessFactoryNamesUsing(fn ($model) => $model . 'Factory');

        $factory = FactoryTestUser::factory();

        $this->assertInstanceOf(FactoryTestUserFactory::class, $factory);
    }

    public function testPerClassModelNameResolverIsolation()
    {
        // Set up per-class resolvers for different factories
        FactoryTestUserFactory::guessModelNamesUsing(fn () => 'ResolvedUserModel');
        FactoryTestPostFactory::guessModelNamesUsing(fn () => 'ResolvedPostModel');

        // Create factories without explicit $model property
        $factoryWithoutModel = new FactoryTestFactoryWithoutModel();

        // The factory-specific resolver should be isolated
        // FactoryTestFactoryWithoutModel has no resolver set, so it should use default convention
        // We need to set a resolver for it specifically
        FactoryTestFactoryWithoutModel::guessModelNamesUsing(fn () => 'ResolvedFactoryWithoutModel');

        $this->assertSame('ResolvedFactoryWithoutModel', $factoryWithoutModel->modelName());
    }

    public function testPerClassResolversDoNotInterfere()
    {
        // Each factory class maintains its own resolver
        FactoryTestUserFactory::guessModelNamesUsing(fn () => 'UserModelResolved');

        // Create a user factory instance
        $userFactory = FactoryTestUserFactory::new();

        // The user factory should use its specific resolver
        $this->assertSame(FactoryTestUser::class, $userFactory->modelName());

        // But if we set a resolver for a factory without a $model property...
        FactoryTestFactoryWithoutModel::guessModelNamesUsing(fn () => 'FactoryWithoutModelResolved');

        $factoryWithoutModel = new FactoryTestFactoryWithoutModel();
        $this->assertSame('FactoryWithoutModelResolved', $factoryWithoutModel->modelName());
    }

    public function testFlushStateResetsAllResolvers()
    {
        FactoryTestUserFactory::guessModelNamesUsing(fn () => 'CustomModel');
        Factory::useNamespace('Custom\Namespace\\');

        Factory::flushState();

        // After flush, namespace should be reset
        $this->assertSame('Database\Factories\\', Factory::$namespace);
    }
}

class FactoryTestUserFactory extends Factory
{
    protected $model = FactoryTestUser::class;

    public function definition()
    {
        return [
            'name' => $this->faker->name(),
            'options' => null,
        ];
    }
}

class FactoryTestUser extends Model
{
    use HasFactory;

    protected ?string $table = 'users';

    protected array $fillable = ['name', 'options'];

    public function posts()
    {
        return $this->hasMany(FactoryTestPost::class, 'user_id');
    }

    public function roles()
    {
        return $this->belongsToMany(FactoryTestRole::class, 'role_user', 'user_id', 'role_id')->withPivot('admin');
    }

    public function factoryTestRoles()
    {
        return $this->belongsToMany(FactoryTestRole::class, 'role_user', 'user_id', 'role_id')->withPivot('admin');
    }
}

class FactoryTestPostFactory extends Factory
{
    protected $model = FactoryTestPost::class;

    public function definition()
    {
        return [
            'user_id' => FactoryTestUserFactory::new(),
            'title' => $this->faker->name(),
        ];
    }
}

class FactoryTestPost extends Model
{
    use SoftDeletes;

    protected ?string $table = 'posts';

    public function user()
    {
        return $this->belongsTo(FactoryTestUser::class, 'user_id');
    }

    public function factoryTestUser()
    {
        return $this->belongsTo(FactoryTestUser::class, 'user_id');
    }

    public function author()
    {
        return $this->belongsTo(FactoryTestUser::class, 'user_id');
    }

    public function comments()
    {
        return $this->morphMany(FactoryTestComment::class, 'commentable');
    }
}

class FactoryTestCommentFactory extends Factory
{
    protected $model = FactoryTestComment::class;

    public function definition()
    {
        return [
            'commentable_id' => FactoryTestPostFactory::new(),
            'commentable_type' => FactoryTestPost::class,
            'user_id' => fn () => FactoryTestUserFactory::new(),
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

class FactoryTestComment extends Model
{
    use SoftDeletes;

    protected ?string $table = 'comments';

    public function commentable()
    {
        return $this->morphTo();
    }
}

class FactoryTestRoleFactory extends Factory
{
    protected $model = FactoryTestRole::class;

    public function definition()
    {
        return [
            'name' => $this->faker->name(),
        ];
    }
}

class FactoryTestRole extends Model
{
    use HasFactory;

    protected ?string $table = 'roles';

    protected array $touches = ['users'];

    public function users()
    {
        return $this->belongsToMany(FactoryTestUser::class, 'role_user', 'role_id', 'user_id')->withPivot('admin');
    }
}

// UseFactory attribute test fixtures

class FactoryTestModelWithUseFactoryFactory extends Factory
{
    public function definition()
    {
        return [
            'name' => $this->faker->name(),
        ];
    }
}

#[UseFactory(FactoryTestModelWithUseFactoryFactory::class)]
class FactoryTestModelWithUseFactory extends Model
{
    use HasFactory;

    protected ?string $table = 'users';

    protected array $fillable = ['name'];
}

// Factory for testing static $factory property precedence
class FactoryTestModelWithStaticFactory extends Factory
{
    protected $model = FactoryTestModelWithStaticFactoryAndAttribute::class;

    public function definition()
    {
        return [
            'name' => $this->faker->name(),
        ];
    }
}

// Alternative factory for the attribute (should NOT be used)
class FactoryTestAlternativeFactory extends Factory
{
    public function definition()
    {
        return [
            'name' => 'alternative',
        ];
    }
}

#[UseFactory(FactoryTestAlternativeFactory::class)]
class FactoryTestModelWithStaticFactoryAndAttribute extends Model
{
    use HasFactory;

    protected static string $factory = FactoryTestModelWithStaticFactory::class;

    protected ?string $table = 'users';

    protected array $fillable = ['name'];
}

// Factory without explicit $model property for testing resolver isolation
class FactoryTestFactoryWithoutModel extends Factory
{
    public function definition()
    {
        return [];
    }
}
