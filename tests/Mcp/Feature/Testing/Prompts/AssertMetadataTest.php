<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Prompts\AssertMetadataTest;

use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\ExpectationFailedException;

class GarageP extends Server
{
    protected array $prompts = [
        InspectionPrompt::class,
    ];
}

class InspectionPrompt extends Prompt
{
    protected string $name = 'car/inspection';

    protected string $title = 'Car Inspection';

    protected string $description = 'Schedule a car inspection';

    /**
     * Return the inspection confirmation.
     */
    public function handle(): string
    {
        return 'Your car inspection is scheduled!';
    }
}

class AssertMetadataTest extends TestCase
{
    public function testMayAssertTheTitle(): void
    {
        $response = GarageP::prompt(InspectionPrompt::class);

        $response->assertName('car/inspection')
            ->assertTitle('Car Inspection')
            ->assertDescription('Schedule a car inspection');
    }

    public function testFailsToAssertTheNameIsWrong(): void
    {
        $response = GarageP::prompt(InspectionPrompt::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertName('wrong/name');
    }

    public function testFailsToAssertTheTitleIsWrong(): void
    {
        $response = GarageP::prompt(InspectionPrompt::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertTitle('Wrong Title');
    }

    public function testFailsToAssertTheDescriptionIsWrong(): void
    {
        $response = GarageP::prompt(InspectionPrompt::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertDescription('Wrong description');
    }
}
