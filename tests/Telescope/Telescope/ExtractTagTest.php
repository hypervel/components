<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Telescope;

use Hypervel\Mail\Mailable;
use Hypervel\Telescope\Database\Factories\EntryModelFactory;
use Hypervel\Telescope\ExtractTags;
use Hypervel\Telescope\FormatModel;
use Hypervel\Tests\Telescope\FeatureTestCase;

class ExtractTagTest extends FeatureTestCase
{
    public function testExtractTagFromArrayContainingFlatCollection()
    {
        $flatCollection = EntryModelFactory::new()->create();

        $tag = FormatModel::given($flatCollection->first());
        $extractedTag = ExtractTags::fromArray([$flatCollection]);

        $this->assertSame($tag, $extractedTag[0]);
    }

    public function testExtractTagFromArrayContainingDeepCollection()
    {
        $deepCollection = EntryModelFactory::times(1)->create()->groupBy('type');

        $tag = FormatModel::given($deepCollection->first()->first());
        $extractedTag = ExtractTags::fromArray([$deepCollection]);

        $this->assertSame($tag, $extractedTag[0]);
    }

    public function testExtractTagFromMailable()
    {
        $deepCollection = EntryModelFactory::times(1)->create()->groupBy('type');
        $mailable = new DummyMailableWithData($deepCollection);

        $tag = FormatModel::given($deepCollection->first()->first());
        $extractedTag = ExtractTags::from($mailable);

        $this->assertSame($tag, $extractedTag[0]);
    }
}

class DummyMailableWithData extends Mailable
{
    private $mailData;

    public function __construct($mailData)
    {
        $this->mailData = $mailData;
    }

    public function build()
    {
        return $this->from('from@hypervel.org')
            ->to('to@hypervel.org')
            ->view(['raw' => 'simple text content'])
            ->with([
                'mailData' => $this->mailData,
            ]);
    }
}
