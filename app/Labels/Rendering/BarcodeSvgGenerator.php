<?php

namespace App\Labels\Rendering;

use App\Labels\Enums\BarcodeSymbology;
use App\Labels\Enums\QrErrorCorrection;

interface BarcodeSvgGenerator
{
    public function generate(
        BarcodeSymbology $symbology,
        string $value,
        QrErrorCorrection $errorCorrection = QrErrorCorrection::Medium,
    ): string;
}
