<?php

declare(strict_types=1);

namespace Tests\Zangra\SyliusBarcodePrintPlugin\Unit\Label;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zangra\SyliusBarcodePrintPlugin\Label\Ean13;

final class Ean13Test extends TestCase
{
    #[DataProvider('provideValues')]
    public function testIsValid(string $value, bool $expected): void
    {
        self::assertSame($expected, Ean13::isValid($value));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function provideValues(): iterable
    {
        yield 'valid' => ['5415249002848', true];
        yield 'another valid' => ['4006381333931', true];
        yield 'wrong check digit' => ['5415249002849', false];
        yield 'too short' => ['541524900284', false];
        yield 'too long' => ['54152490028480', false];
        yield 'letters' => ['541524900284A', false];
        yield 'empty' => ['', false];
    }
}
