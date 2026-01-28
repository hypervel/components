<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Tests\TestCase;
use Mockery as m;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEloquentBelongsToManyWithoutTouchingTest extends TestCase
{
    public function testItWillNotTouchRelatedModelsWhenUpdatingChild(): void
    {
        /** @var BelongsToManyWithoutTouchingArticle $related */
        $related = m::mock(BelongsToManyWithoutTouchingArticle::class)->makePartial();
        $related->shouldReceive('getUpdatedAtColumn')->never();
        $related->shouldReceive('freshTimestampString')->never();

        $this->assertFalse($related::isIgnoringTouch());

        Model::withoutTouching(function () use ($related) {
            $this->assertTrue($related::isIgnoringTouch());

            $builder = m::mock(Builder::class);
            $builder->shouldReceive('join');
            $parent = m::mock(BelongsToManyWithoutTouchingUser::class);

            $parent->shouldReceive('getAttribute')->with('id')->andReturn(1);
            $builder->shouldReceive('getModel')->andReturn($related);
            $builder->shouldReceive('where');
            $builder->shouldReceive('getQuery')->andReturn(
                m::mock(stdClass::class, ['getGrammar' => m::mock(Grammar::class, ['isExpression' => false])])
            );
            $relation = new BelongsToMany($builder, $parent, 'article_users', 'user_id', 'article_id', 'id', 'id');
            $builder->shouldReceive('update')->never();

            $relation->touch();
        });

        $this->assertFalse($related::isIgnoringTouch());
    }
}

class BelongsToManyWithoutTouchingUser extends Model
{
    protected ?string $table = 'users';

    protected array $fillable = ['id', 'email'];

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(BelongsToManyWithoutTouchingArticle::class, 'article_user', 'user_id', 'article_id');
    }
}

class BelongsToManyWithoutTouchingArticle extends Model
{
    protected ?string $table = 'articles';

    protected array $fillable = ['id', 'title'];

    protected array $touches = ['user'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(BelongsToManyWithoutTouchingUser::class, 'article_user', 'article_id', 'user_id');
    }
}
