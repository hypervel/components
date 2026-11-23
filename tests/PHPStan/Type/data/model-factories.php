<?php

namespace ModelFactories;

use App\Account;
use App\Post;
use App\Shapes\Circle;
use App\Shapes\Cube;
use App\Shapes\Ellipse;
use App\Shapes\Hexagon;
use App\Shapes\Oval;
use App\Shapes\Polygon;
use App\Shapes\Rhombus;
use App\Shapes\Shape;
use App\Shapes\Square;
use App\Shapes\Wedge;
use App\User;
use Database\Factories\UserFactory;

use Hypervel\Database\Eloquent\Attributes\UseFactory;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Factories\Sequence;
use Hypervel\Database\Eloquent\Model;
use function PHPStan\Testing\assertType;

/**
 * @param class-string<User> $userClass
 */
function test(?int $foo, bool $many, UserFactory $plainFactory, string $userClass, Tag|Gizmo $tagOrGizmo, User|Account $userOrAccount): void {
    assertType('Database\Factories\UserFactory', User::factory());
    assertType('Database\Factories\UserFactory', User::factory()->new());
    assertType('App\User', User::factory()->createOne());
    assertType('App\User', User::factory()->createOneQuietly());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory()->createMany([]));
    assertType('App\User', User::factory()->makeOne());

    assertType('App\User', User::factory()->create());
    assertType('App\User', User::factory()->createQuietly());
    assertType('App\User', User::factory()->make());

    assertType('App\User', User::factory()->configure()->create());
    assertType('App\User', User::factory()->configure()->createQuietly());
    assertType('App\User', User::factory()->configure()->make());

    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory(2)->create());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory(2)->createQuietly());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory(2)->make());
    assertType('App\User', User::factory(2)->createOneQuietly());

    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory()->count(1)->create());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory()->count(1)->createQuietly());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory()->count(1)->make());
    assertType('App\User', User::factory()->count(2)->createOneQuietly());

    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory()->times(1)->create());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory()->times(1)->createQuietly());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory()->times(1)->make());
    assertType('App\User', User::factory()->times(2)->createOneQuietly());

    assertType('App\User', User::factory(2)->count(null)->create());
    assertType('App\User', User::factory(2)->count(null)->createQuietly());
    assertType('App\User', User::factory(2)->count(null)->createOneQuietly());
    assertType('App\User', User::factory(2)->count(null)->make());

    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory(2)->times(3)->create());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory(2)->times(3)->createQuietly());
    assertType('App\User', User::factory(2)->times(3)->createOneQuietly());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory(2)->times(3)->make());

    assertType('App\User', User::factory(2)->state(['foo'])->count(null)->create());
    assertType('App\User', User::factory(2)->state(['foo'])->count(null)->createQuietly());
    assertType('App\User', User::factory(2)->state(['foo'])->count(null)->createOneQuietly());
    assertType('App\User', User::factory(2)->state(['foo'])->count(null)->make());

    assertType('Database\Factories\UserFactory', User::factory()->hasPosts());
    assertType('Database\Factories\UserFactory', User::factory()->hasPosts(3));
    assertType('Database\Factories\UserFactory', User::factory()->hasPosts(['active' => 0]));
    assertType('App\User', User::factory()->hasPosts(3, ['active' => 0])->createOne());
    assertType('Database\Factories\UserFactory', User::factory()->forParent());
    assertType('Database\Factories\UserFactory', User::factory()->forParent(['meta' => ['foo']]));

    assertType('Database\Factories\UserFactory', User::factory()->unverified());
    assertType('Database\Factories\UserFactory', User::factory()->afterMaking(fn (User $user) => $user));
    assertType('Database\Factories\UserFactory', User::factory()->afterCreating(fn (User $user) => $user));
    assertType('Database\Factories\Domain\Foo\UserFactory', \App\Domain\Foo\User::factory());

    assertType('Database\Factories\Post\PostFactory', Post::factory());
    assertType('Database\Factories\Post\PostFactory', Post::factory()->new());
    assertType('App\Post', Post::factory()->createOne());
    assertType('App\Post', Post::factory()->createOneQuietly());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\Post>', Post::factory()->createMany([]));
    assertType('App\Post', Post::factory()->makeOne());
    assertType('Database\Factories\Post\PostFactory', Post::factory()->afterMaking(fn (Post $post) => $post));
    assertType('Database\Factories\Post\PostFactory', Post::factory()->afterCreating(fn (Post $post) => $post));

    assertType('App\User|Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory()->count($foo)->create());
    assertType('App\User|Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory()->count($foo)->createQuietly());
    assertType('App\User|Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory()->count($foo)->make());

    assertType('App\User|Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory($foo)->create());
    assertType('App\User|Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory($foo)->createQuietly());
    assertType('App\User|Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory($foo)->make());

    assertType('Database\Factories\UserFactory', User::factory()->trashed());
    assertType('*ERROR*', Post::factory()->trashed());

    assertType('ModelFactories\TagFactory', Tag::factory());
    assertType('ModelFactories\Tag', Tag::factory()->create());
    assertType('Hypervel\Database\Eloquent\Collection<int, ModelFactories\Tag>', Tag::factory(3)->create());
    assertType('ModelFactories\TagFactory', SpecialTag::factory());
    assertType('ModelFactories\VideoFactory', Video::factory());
    assertType('ModelFactories\Video', Video::factory()->create());
    assertType('ModelFactories\ImageFactory', Image::factory());

    assertType('Database\Factories\Shapes\ShapeFactory', Shape::factory());
    assertType('Database\Factories\Shapes\CircleFactory', Circle::factory());
    assertType('App\Shapes\Circle', Circle::factory()->create());
    assertType('Database\Factories\Shapes\ShapeFactory', Square::factory());
    // Hypervel: a generic factory keeps its template arguments.
    assertType('Database\Factories\Shapes\PolygonFactory<App\Shapes\Polygon>', Polygon::factory());
    assertType('Database\Factories\Shapes\HexagonFactory', Hexagon::factory());
    assertType('Database\Factories\Shapes\EllipseFactory', Ellipse::factory());
    assertType('Database\Factories\Shapes\OvalFactory', Oval::factory());
    assertType('Database\Factories\Shapes\TriangleFactory', Wedge::factory());
    assertType('Database\Factories\Shapes\ShapeFactory', Rhombus::factory());
    assertType('Database\Factories\Shapes\RectangleFactory', Cube::factory());

    // Hypervel: merged branches with different counts keep both.
    $factory = User::factory();
    if ($many) {
        $factory = $factory->count(3);
    }
    assertType('App\User|Hypervel\Database\Eloquent\Collection<int, App\User>', $factory->create());

    $factory = User::factory(3);
    if ($many) {
        $factory = User::factory();
    }
    assertType('App\User|Hypervel\Database\Eloquent\Collection<int, App\User>', $factory->create());

    $factory = User::factory();
    if ($many) {
        $factory = $plainFactory;
    }
    assertType('App\User|Hypervel\Database\Eloquent\Collection<int, App\User>', $factory->create());

    // Hypervel: new() starts without a count, forEachSequence() sets one, and so do the static entry points.
    assertType('App\User', User::factory(3)->new()->create());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory()->forEachSequence(['name' => 'a'], ['name' => 'b'])->create());
    assertType('App\User', UserFactory::new()->create());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', UserFactory::times(3)->create());

    // Hypervel: createMany(), createManyQuietly() and makeMany() clear the receiver's count, but a call
    // receiver returns a new factory each time.
    $factory = User::factory(2);
    $users = $factory->createMany(1);
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users);
    assertType('App\User', $factory->create());
    $factory = User::factory(2);
    $factory->createManyQuietly(1);
    assertType('App\User', $factory->createQuietly());
    $factory = User::factory(2);
    $factory->makeMany(1);
    assertType('App\User', $factory->make());
    User::factory(2)->createMany(1);
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory(2)->create());

    // Hypervel: a class-string receiver names its model, and an unpacked count is unknown.
    assertType('Database\Factories\UserFactory', $userClass::factory());
    assertType('App\User|Hypervel\Database\Eloquent\Collection<int, App\User>', User::factory(...[3])->create());

    // Hypervel: newFactory() selects a static $factory default, then the model's own #[UseFactory].
    assertType('ModelFactories\GadgetFactory', Gadget::factory());
    assertType('ModelFactories\Gadget', Gadget::factory()->create());
    assertType('ModelFactories\WidgetFactory', Widget::factory());
    assertType('Hypervel\Database\Eloquent\Factories\Factory', $tagOrGizmo::factory());
    // Hypervel: a receiver union with a model that has no factory() keeps PHPStan's own handling.
    assertType('Hypervel\Database\Eloquent\Factories\Factory', $userOrAccount::factory());

    // Hypervel: only a method returning the factory's own class keeps the factory.
    assertType('mixed', Gadget::factory()->payload());

    // Hypervel: a factory's own count() may set the count differently.
    assertType('App\User|Hypervel\Database\Eloquent\Collection<int, App\User>', CountingUserFactory::new()->count(2)->create());

    // Hypervel: a factory that declares its own templates keeps its generic type and declared results, so
    // merged factories keep each model, and a selected one keeps its class and default model.
    assertType('ModelFactories\GenericFactory<ModelFactories\Sprocket>', Sprocket::factory());
    assertType('Hypervel\Database\Eloquent\Collection<int, ModelFactories\Sprocket>|ModelFactories\Sprocket', Sprocket::factory()->make());
    $genericFactory = Sprocket::factory();
    if ($many) {
        $genericFactory = Cog::factory();
    }
    assertType('Hypervel\Database\Eloquent\Collection<int, ModelFactories\Cog>|Hypervel\Database\Eloquent\Collection<int, ModelFactories\Sprocket>|ModelFactories\Cog|ModelFactories\Sprocket', $genericFactory->make());
    assertType('Hypervel\Database\Eloquent\Collection<int, ModelFactories\Lever>|ModelFactories\Lever', Lever::factory()->polished()->make());
    assertType('Hypervel\Database\Eloquent\Collection<int, ModelFactories\Lever>|ModelFactories\Lever', LeverFactory::new()->make());
}

class Comment extends Model
{
    use HasFactory;

    /** @return CommentFactory */
    protected static function newFactory(): Factory
    {
        return CommentFactory::new();
    }

    private function test(): void
    {
        assertType('ModelFactories\CommentFactory', static::factory());
    }
}

/** @extends Factory<Comment> */
class CommentFactory extends Factory
{
    public function definition(): array
    {
        return [];
    }
}

class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    protected static string $factory = TagFactory::class;
}

class SpecialTag extends Tag
{
}

/** @extends Factory<Tag> */
class TagFactory extends Factory
{
    protected ?string $model = Tag::class;

    public function definition(): array
    {
        return [];
    }
}

#[UseFactory(VideoFactory::class)]
class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory;
}

/** @extends Factory<Video> */
class VideoFactory extends Factory
{
    protected ?string $model = Video::class;

    public function definition(): array
    {
        return [];
    }
}

class Image extends Model
{
    /** @use HasFactory<ImageFactory> */
    use HasFactory;

    protected static function newFactory(): ImageFactory|null
    {
        return null;
    }
}

/** @extends Factory<Image> */
class ImageFactory extends Factory
{
    protected ?string $model = Image::class;

    public function definition(): array
    {
        return [];
    }
}

// Hypervel: no @use generic, so the attribute decides.
#[UseFactory(GadgetFactory::class)]
class Gadget extends Model
{
    use HasFactory;
}

/** @extends Factory<Gadget> */
class GadgetFactory extends Factory
{
    protected ?string $model = Gadget::class;

    public function definition(): array
    {
        return [];
    }

    public function payload(): mixed
    {
        return [];
    }
}

// Hypervel: the static $factory default comes before the attribute.
#[UseFactory(GadgetFactory::class)]
class Widget extends Model
{
    use HasFactory;

    protected static string $factory = WidgetFactory::class;
}

/** @extends Factory<Widget> */
class WidgetFactory extends Factory
{
    protected ?string $model = Widget::class;

    public function definition(): array
    {
        return [];
    }
}

// Hypervel: no factory is known, so factory() keeps its declaration.
class Gizmo extends Model
{
    use HasFactory;
}

class CountingUserFactory extends UserFactory
{
    public function count(?int $count): static
    {
        return parent::count($count);
    }
}

/**
 * @template TModel of Model
 *
 * @extends Factory<TModel>
 */
class GenericFactory extends Factory
{
    public function definition(): array
    {
        return [];
    }
}

class Sprocket extends Model
{
    use HasFactory;

    /** @return GenericFactory<static> */
    protected static function newFactory(): GenericFactory
    {
        return GenericFactory::new()->useModel(static::class);
    }
}

class Cog extends Model
{
    use HasFactory;

    /** @return GenericFactory<static> */
    protected static function newFactory(): GenericFactory
    {
        return GenericFactory::new()->useModel(static::class);
    }
}

#[UseFactory(LeverFactory::class)]
class Lever extends Model
{
    use HasFactory;
}

/**
 * @template TModel of Model = Lever
 *
 * @extends Factory<TModel>
 */
class LeverFactory extends Factory
{
    public function definition(): array
    {
        return [];
    }

    public function polished(): static
    {
        return $this;
    }
}
