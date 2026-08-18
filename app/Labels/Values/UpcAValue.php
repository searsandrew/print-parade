<?php

namespace App\Labels\Values;

use InvalidArgumentException;

final class UpcAValue
{
    public static function normalize(string $name, string $value): string
    {
        if (preg_match('/\A\d{11,12}\z/', $value) !== 1) {
            throw new InvalidArgumentException("Field {$name} must contain 11 or 12 UPC-A digits.");
        }

        $data = substr($value, 0, 11);
        $checkDigit = self::checkDigit($data);

        if (strlen($value) === 12 && $value[11] !== $checkDigit) {
            throw new InvalidArgumentException("Field {$name} has an invalid UPC-A check digit.");
        }

        return $data.$checkDigit;
    }

    private static function checkDigit(string $data): string
    {
        $sum = 0;

        for ($index = 0; $index < 11; $index++) {
            $digit = (int) $data[$index];
            $sum += $index % 2 === 0 ? $digit * 3 : $digit;
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }
}
