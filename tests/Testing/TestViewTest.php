<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\TestViewTest;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Testing\TestComponent;
use Hypervel\Testing\TestView;
use Hypervel\Tests\TestCase;
use Hypervel\View\Component;
use Hypervel\View\View;
use Mockery as m;
use PHPUnit\Framework\AssertionFailedError;

class TestViewTest extends TestCase
{
    public function testAssertViewHasAcceptsTheExactKeylessModel(): void
    {
        $model = new TestModel;

        $this->makeTestView(['foo' => $model])->assertViewHas('foo', $model);
    }

    public function testAssertViewHasRejectsADifferentKeylessModel(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->makeTestView(['foo' => new TestModel])->assertViewHas('foo', new TestModel);
    }

    public function testAssertViewHasAcceptsAModelWithTheSameStoredIdentity(): void
    {
        $this->makeTestView(['foo' => new TestModel(['id' => 1])])
            ->assertViewHas('foo', new TestModel(['id' => 1]));
    }

    public function testAssertViewHasAcceptsACollectionContainingTheExactKeylessModels(): void
    {
        $collection = new EloquentCollection([new TestModel, new TestModel]);

        $this->makeTestView(['foos' => $collection])->assertViewHas('foos', $collection);
    }

    public function testAssertViewHasRejectsACollectionContainingDifferentKeylessModels(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->makeTestView([
            'foos' => new EloquentCollection([new TestModel, new TestModel]),
        ])->assertViewHas('foos', new EloquentCollection([new TestModel, new TestModel]));
    }

    public function testAssertViewHasAcceptsACollectionWithTheSameStoredIdentities(): void
    {
        $this->makeTestView([
            'foos' => new EloquentCollection([
                new TestModel(['id' => 1]),
                new TestModel(['id' => 2]),
            ]),
        ])->assertViewHas('foos', new EloquentCollection([
            new TestModel(['id' => 1]),
            new TestModel(['id' => 2]),
        ]));
    }

    public function testAssertViewHasRejectsANonModelAsAnAssertionFailure(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->makeTestView(['foo' => 'not a model'])->assertViewHas('foo', new TestModel);
    }

    public function testAssertViewHasReportsAMissingCollectionKeyAsAnAssertionFailure(): void
    {
        $actual = new EloquentCollection([
            0 => new TestModel(['id' => 1]),
            1 => new TestModel(['id' => 2]),
        ]);
        $expected = new EloquentCollection([
            0 => new TestModel(['id' => 1]),
            2 => new TestModel(['id' => 2]),
        ]);

        $this->expectException(AssertionFailedError::class);

        $this->makeTestView(['foos' => $actual])->assertViewHas('foos', $expected);
    }

    public function testRenderedViewAssertionsSupportArraysHtmlAndNormalizedText(): void
    {
        $view = $this->makeTestView(
            [],
            "<main><p>Hello&nbsp;<strong>beautiful</strong>\u{2003}World</p><span>0</span></main>",
        );

        $view
            ->assertSee(['beautiful', 'World'])
            ->assertSeeHtml(['<main>', '<strong>beautiful</strong>'])
            ->assertSeeHtmlInOrder(['<main>', '<span>0</span>'])
            ->assertSeeText(['Hello beautiful World', '0'])
            ->assertSeeTextInOrder(['Hello', 'beautiful', 'World', '0'])
            ->assertDontSee(['Goodbye', '<footer>'])
            ->assertDontSeeHtml(['<footer>', '<span>1</span>'])
            ->assertDontSeeText(['Goodbye World', '1']);
    }

    public function testRenderedComponentAssertionsSupportArraysHtmlAndNormalizedText(): void
    {
        $component = new TestComponent(
            new TestViewComponent,
            m::mock(View::class, [
                'render' => '<main><p>Hello&nbsp;<strong>World</strong></p><span>0</span></main>',
            ]),
        );

        $component
            ->assertSee(['Hello', 'World'])
            ->assertSeeHtml(['<main>', '<strong>World</strong>'])
            ->assertSeeHtmlInOrder(['<main>', '<span>0</span>'])
            ->assertSeeText(['Hello World', '0'])
            ->assertSeeTextInOrder(['Hello', 'World', '0'])
            ->assertDontSee(['Goodbye', '<footer>'])
            ->assertDontSeeHtml(['<footer>', '<span>1</span>'])
            ->assertDontSeeText(['Goodbye World', '1']);
    }

    public function testRenderedViewTextRetainsMalformedUtf8Matching(): void
    {
        $view = $this->makeTestView([], "<p>Hello \xFF World</p>");

        $view
            ->assertSeeText("Hello \xFF World", escape: false)
            ->assertDontSeeText("Goodbye \xFF World", escape: false);
    }

    /**
     * Create a test view with the given data and rendered content.
     */
    private function makeTestView(array $data, string $rendered = 'hello world'): TestView
    {
        return new TestView(m::mock(View::class, [
            'render' => $rendered,
            'gatherData' => $data,
        ]));
    }
}

class TestModel extends Model
{
    protected array $guarded = [];
}

class TestViewComponent extends Component
{
    /**
     * Get the component view.
     */
    public function render(): string
    {
        return '';
    }
}
