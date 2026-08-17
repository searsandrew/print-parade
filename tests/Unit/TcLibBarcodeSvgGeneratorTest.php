<?php

use App\Labels\Enums\BarcodeSymbology;
use App\Labels\Enums\QrErrorCorrection;
use App\Labels\Rendering\BarcodeSvgGenerator;
use App\Labels\Rendering\TcLibBarcodeSvgGenerator;

test('tc lib generates real svg for each supported symbology', function (BarcodeSymbology $symbology, string $value) {
    $generator = new TcLibBarcodeSvgGenerator;

    $svg = $generator->generate($symbology, $value, QrErrorCorrection::Medium);

    expect($generator)->toBeInstanceOf(BarcodeSvgGenerator::class)
        ->and($svg)->toStartWith('<svg')
        ->and($svg)->toContain('<desc>'.$value.'</desc>')
        ->and($svg)->toContain('<rect');
})->with([
    'Code 128' => [BarcodeSymbology::Code128, 'ABC-123'],
    'UPC-A' => [BarcodeSymbology::UpcA, '036000291452'],
    'QR code' => [BarcodeSymbology::QrCode, 'https://example.com'],
]);

test('invalid barcode values are surfaced through the application adapter', function () {
    (new TcLibBarcodeSvgGenerator)->generate(BarcodeSymbology::UpcA, 'invalid');
})->throws(InvalidArgumentException::class, 'Unable to generate upc_a SVG');
