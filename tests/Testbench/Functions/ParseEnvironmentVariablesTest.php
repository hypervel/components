<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Functions;

use Dotenv\Parser\Parser;
use Dotenv\Store\StringStore;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\parse_environment_variables;

class ParseEnvironmentVariablesTest extends TestCase
{
    #[Test]
    public function itCanParseEnvironmentVariables(): void
    {
        $given = [
            'APP_KEY' => null,
            'APP_DEBUG' => true,
            'APP_PRODUCTION' => false,
            'APP_NAME' => 'Testbench',
            'APP_EMPTY' => '',
            'APP_ZERO' => '0',
        ];

        $expected = [
            'APP_KEY=(null)',
            'APP_DEBUG=(true)',
            'APP_PRODUCTION=(false)',
            'APP_NAME="Testbench"',
            'APP_EMPTY=(empty)',
            'APP_ZERO="0"',
        ];

        $this->assertSame(
            $expected,
            parse_environment_variables($given)
        );
    }

    #[Test]
    public function itRoundTripsSpecialCharactersThroughTheDotenvParser(): void
    {
        $value = "O'Reilly\\path\" \$HOME\nline\rreturn\fform\ttab\vvertical # hash";
        $environment = parse_environment_variables(['APP_NAME' => $value]);
        $entries = (new Parser)->parse((new StringStore(implode(PHP_EOL, $environment)))->read());

        $this->assertSame($value, $entries[0]->getValue()->get()->getChars());
    }
}
