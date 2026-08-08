<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Callout;
use Hypervel\Prompts\Elements\BulletedList;
use Hypervel\Prompts\Elements\Element;
use Hypervel\Prompts\Elements\KeyValueList;
use Hypervel\Prompts\Elements\NumberedList;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\Support\Utils;
use Hypervel\Prompts\Themes\Default\CalloutRenderer;
use Hypervel\Tests\TestCase;

use function Hypervel\Prompts\callout;

class CalloutTest extends TestCase
{
    public function testRendersACalloutWithALabelAndStringContent(): void
    {
        Prompt::fake();

        callout('My Title', 'Hello, World!');

        Prompt::assertOutputContains('My Title');
        Prompt::assertOutputContains('Hello, World!');
    }

    public function testRendersAWarningCallout(): void
    {
        Prompt::fake();

        callout('Deprecation Notice', 'This will be removed.', 'warning');

        Prompt::assertOutputContains('⚠ Deprecation Notice');
        Prompt::assertOutputContains('This will be removed.');
    }

    public function testRendersAnErrorCallout(): void
    {
        Prompt::fake();

        callout('Connection Failed', 'Could not connect.', 'error');

        Prompt::assertOutputContains('⚠ Connection Failed');
        Prompt::assertOutputContains('Could not connect.');
    }

    public function testRendersACalloutWithInfoFooter(): void
    {
        Prompt::fake();

        callout('Deploy', 'Deployed successfully.', info: 'deploy-id: abc123');

        Prompt::assertOutputContains('Deploy');
        Prompt::assertOutputContains('Deployed successfully.');
        Prompt::assertOutputContains('deploy-id: abc123');
    }

    public function testRendersACalloutWithABulletedList(): void
    {
        Prompt::fake();

        callout('Summary', [
            'Changes made:',
            Element::bulletedList([
                'First item',
                'Second item',
            ]),
        ]);

        Prompt::assertOutputContains('Summary');
        Prompt::assertOutputContains('Changes made:');
        Prompt::assertStrippedOutputContains('· First item');
        Prompt::assertStrippedOutputContains('· Second item');
    }

    public function testRendersACalloutWithANumberedList(): void
    {
        Prompt::fake();

        callout('Steps', [
            'Follow these steps:',
            Element::numberedList([
                'Step one',
                'Step two',
                'Step three',
            ]),
        ]);

        Prompt::assertOutputContains('Steps');
        Prompt::assertStrippedOutputContains('1. Step one');
        Prompt::assertStrippedOutputContains('2. Step two');
        Prompt::assertStrippedOutputContains('3. Step three');
    }

    public function testRendersACalloutWithAKeyValueList(): void
    {
        Prompt::fake();

        callout('Details', [
            'Connection info:',
            Element::keyValueList([
                'Host' => '127.0.0.1',
                'Port' => '3306',
            ]),
        ]);

        Prompt::assertOutputContains('Details');
        Prompt::assertStrippedOutputContains('Host  127.0.0.1');
        Prompt::assertStrippedOutputContains('Port  3306');
    }

    public function testRendersACalloutWithAHeading(): void
    {
        Prompt::fake();

        callout('Report', [
            'Summary of changes.',
            Element::heading('What Changed'),
            Element::bulletedList(['Item one']),
        ]);

        Prompt::assertOutputContains('Report');
        Prompt::assertOutputContains('What Changed');
        Prompt::assertOutputContains('Item one');
    }

    public function testRendersACalloutWithMixedContent(): void
    {
        Prompt::fake();

        callout('Deployment', [
            'Deployed to production.',
            Element::heading('Changes'),
            Element::bulletedList(['Migration ran', 'Cache cleared']),
            Element::heading('Next Steps'),
            Element::numberedList(['Check health endpoint', 'Monitor errors']),
        ], info: 'deploy-id: xyz');

        Prompt::assertOutputContains('Deployment');
        Prompt::assertOutputContains('Deployed to production.');
        Prompt::assertOutputContains('Changes');
        Prompt::assertOutputContains('Migration ran');
        Prompt::assertOutputContains('Next Steps');
        Prompt::assertOutputContains('Check health endpoint');
        Prompt::assertOutputContains('deploy-id: xyz');
    }

    public function testRendersACalloutWithASpacedBulletedList(): void
    {
        Prompt::fake();

        callout('Summary', [
            Element::bulletedList([
                'First item',
                'Second item',
                'Third item',
            ], spaced: true),
        ]);

        $content = Prompt::strippedContent();

        $this->assertStringContainsString('· First item', $content);
        $this->assertStringContainsString('· Second item', $content);
        $this->assertStringContainsString('· Third item', $content);
        $this->assertMatchesRegularExpression('/· First item.*\n(.*)\n.*· Second item/s', $content);
    }

    public function testRendersACalloutWithASpacedNumberedList(): void
    {
        Prompt::fake();

        callout('Steps', [
            Element::numberedList([
                'Step one',
                'Step two',
                'Step three',
            ], spaced: true),
        ]);

        $content = Prompt::strippedContent();

        $this->assertStringContainsString('1. Step one', $content);
        $this->assertStringContainsString('2. Step two', $content);
        $this->assertStringContainsString('3. Step three', $content);
        $this->assertMatchesRegularExpression('/1\. Step one.*\n(.*)\n.*2\. Step two/s', $content);
    }

    public function testRendersACalloutWithALinkElement(): void
    {
        Prompt::fake();

        callout('Info', [
            'Visit the dashboard:',
            Element::link('https://example.com', 'Dashboard'),
        ]);

        Prompt::assertOutputContains('Info');
        Prompt::assertOutputContains('Dashboard');
        Prompt::assertOutputContains("\e]8;;https://example.com\e\\");
    }

    public function testRendersACalloutWithAnInlineLink(): void
    {
        Prompt::fake();

        callout('Info', [
            'Go here: ' . Element::link('https://example.com', 'My Link'),
        ]);

        Prompt::assertOutputContains('Go here:');
        Prompt::assertOutputContains('My Link');
        Prompt::assertOutputContains("\e]8;;https://example.com\e\\");
    }

    public function testRendersAnEmptyKeyValueList(): void
    {
        Prompt::fake();

        callout('Details', [Element::keyValueList([])]);

        Prompt::assertOutputContains('Details');
    }

    public function testSparseBulletedListsUseDisplayOrderForSpacing(): void
    {
        $renderer = new CalloutTestRenderer(new Callout('Summary', ''));

        $lines = array_map(Utils::stripEscapeSequences(...), $renderer->renderBulletedListForTest(
            new BulletedList([2 => 'First item', 8 => 'Second item'], spaced: true),
        ));

        $this->assertSame(['· First item', PHP_EOL . '· Second item'], $lines);
    }

    public function testSparseNumberedListsUseConsecutiveDisplayNumbers(): void
    {
        $renderer = new CalloutTestRenderer(new Callout('Steps', ''));

        $lines = array_map(Utils::stripEscapeSequences(...), $renderer->renderNumberedListForTest(
            new NumberedList([2 => 'First step', 8 => 'Second step'], spaced: true),
        ));

        $this->assertSame(['1. First step', PHP_EOL . '2. Second step'], $lines);
    }

    public function testNumericKeyValueKeysAreRenderedAsText(): void
    {
        $renderer = new CalloutTestRenderer(new Callout('Details', ''));

        $lines = array_map(Utils::stripEscapeSequences(...), $renderer->renderKeyValueListForTest(
            new KeyValueList(['123' => 'Value']),
        ));

        $this->assertSame(['123  Value'], $lines);
    }

    public function testCanFallBack(): void
    {
        Prompt::fallbackWhen(true);
        $invoked = false;

        Callout::fallbackUsing(function (Callout $callout) use (&$invoked): bool {
            $invoked = true;
            $this->assertSame('Test', $callout->label);
            $this->assertSame('Content', $callout->content);

            return true;
        });

        (new Callout('Test', 'Content'))->display();

        $this->assertTrue($invoked);
    }
}

class CalloutTestRenderer extends CalloutRenderer
{
    /**
     * Render a bulleted list for testing.
     *
     * @return array<int, string>
     */
    public function renderBulletedListForTest(BulletedList $part): array
    {
        return $this->renderBulletedList($part);
    }

    /**
     * Render a numbered list for testing.
     *
     * @return array<int, string>
     */
    public function renderNumberedListForTest(NumberedList $part): array
    {
        return $this->renderNumberedList($part);
    }

    /**
     * Render a key-value list for testing.
     *
     * @return array<int, string>
     */
    public function renderKeyValueListForTest(KeyValueList $part): array
    {
        return $this->renderKeyValueList($part);
    }
}
