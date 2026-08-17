<?php

namespace App\Labels\Rendering;

use App\Labels\Enums\BarcodeSymbology;
use App\Labels\Enums\QrErrorCorrection;
use Com\Tecnick\Barcode\Barcode;
use InvalidArgumentException;
use Throwable;

final readonly class TcLibBarcodeSvgGenerator implements BarcodeSvgGenerator
{
    public function __construct(private Barcode $barcode = new Barcode) {}

    public function generate(
        BarcodeSymbology $symbology,
        string $value,
        QrErrorCorrection $errorCorrection = QrErrorCorrection::Medium,
    ): string {
        $type = match ($symbology) {
            BarcodeSymbology::Code128 => 'C128',
            BarcodeSymbology::UpcA => 'UPCA',
            BarcodeSymbology::QrCode => 'QRCODE,'.$errorCorrection->zplValue(),
        };
        $padding = $symbology === BarcodeSymbology::QrCode
            ? [-4, -4, -4, -4]
            : [0, 0, 0, 0];

        try {
            return $this->barcode
                ->getBarcodeObj($type, $value, -1, -1, 'black', $padding)
                ->setBackgroundColor('white')
                ->getInlineSvgCode();
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                "Unable to generate {$symbology->value} SVG: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }
}
