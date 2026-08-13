<?php

namespace App\Labels\Enums;

enum QrErrorCorrection: string
{
    case Low = 'low';
    case Medium = 'medium';
    case Quartile = 'quartile';
    case High = 'high';

    public function zplValue(): string
    {
        return match ($this) {
            self::Low => 'L',
            self::Medium => 'M',
            self::Quartile => 'Q',
            self::High => 'H',
        };
    }
}
