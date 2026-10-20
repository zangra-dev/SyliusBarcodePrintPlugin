<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Label;

final class Ean13
{
    public static function isValid(string $value): bool
    {
        if (1 !== preg_match('/^\d{13}$/', $value)) {
            return false;
        }

        return (int) $value[12] === self::checkDigit(substr($value, 0, 12));
    }

    public static function checkDigit(string $first12Digits): int
    {
        $sum = 0;
        for ($i = 0; $i < 12; ++$i) {
            $sum += (int) $first12Digits[$i] * (0 === $i % 2 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }
}
