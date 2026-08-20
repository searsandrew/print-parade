<?php

use App\Labels\Definitions\ResolvedLabelDefinition;
use App\Labels\Rendering\LabelRenderContext;
use App\Labels\Rendering\ZplRenderer;

test('upc a uses canonical data and renders human-readable text at the element bottom', function () {
    $element = zplBarcodeElement('upc_a', '036000291452', [
        'width' => 38,
        'height' => 25,
        'bar_height' => 15,
    ]);

    $zpl = renderBarcode($element);

    expect($zpl)
        ->toContain('^BY2,2,120^FO79,92^BUN,120,Y,N^FD03600029145^FS');
});

test('shortening upc bars preserves the human-readable baseline and moves the bar origin down', function () {
    $tall = renderBarcode(zplBarcodeElement('upc_a', '036000291452', [
        'width' => 38,
        'height' => 25,
        'bar_height' => 15,
    ]));
    $short = renderBarcode(zplBarcodeElement('upc_a', '036000291452', [
        'width' => 38,
        'height' => 25,
        'bar_height' => 10,
    ]));

    expect($tall)->toContain('^FO79,92^BUN,120')
        ->and($short)->toContain('^FO79,132^BUN,80')
        ->and($tall)->toContain(',Y,N^FD03600029145^FS')
        ->and($short)->toContain(',Y,N^FD03600029145^FS');
});

test('linear barcode text is shown by default and can be disabled', function () {
    $withText = renderBarcode(zplBarcodeElement('code128', 'ABC-123'));
    $withoutText = renderBarcode(zplBarcodeElement('code128', 'ABC-123', ['show_text' => false]));

    expect($withText)->toContain('^FDABC-123^FS')
        ->and($withoutText)->not->toContain('^FDABC-123^FS');
});

test('a designer can override barcode module width when it still fits', function () {
    $zpl = renderBarcode(zplBarcodeElement('upc_a', '036000291452', [
        'width' => 50,
        'module_width' => 0.375,
    ]));

    expect($zpl)->toContain('^BY3,2');
});

test('barcodes fail when the assigned width cannot provide scan-safe modules', function () {
    renderBarcode(zplBarcodeElement('upc_a', '036000291452', ['width' => 20]));
})->throws(InvalidArgumentException::class, 'too narrow for a scan-safe barcode module width');

test('barcodes fail when a designer module override exceeds the assigned width', function () {
    renderBarcode(zplBarcodeElement('upc_a', '036000291452', [
        'width' => 38,
        'module_width' => 0.5,
    ]));
})->throws(InvalidArgumentException::class, 'barcode does not fit its assigned width');

test('linear barcode bars fail below the reliability minimum', function () {
    renderBarcode(zplBarcodeElement('upc_a', '036000291452', ['bar_height' => 3]));
})->throws(InvalidArgumentException::class, 'barcode bars are too short for reliable scanning');

test('qr codes default to medium error correction and automatic whole-dot sizing', function () {
    $zpl = renderBarcode(zplBarcodeElement('qr_code', 'https://example.com', [
        'width' => 25,
        'height' => 25,
    ]));

    expect($zpl)->toContain('^BQN,2,6,M,7^FH\\^FDMA,https://example.com^FS');
});

test('qr codes fail rather than using modules below the scan-safe minimum', function () {
    renderBarcode(zplBarcodeElement('qr_code', str_repeat('A', 100), [
        'width' => 10,
        'height' => 10,
    ]));
})->throws(InvalidArgumentException::class, 'too narrow for a scan-safe barcode module width');

test('rotated barcodes use orientation aware field origins', function () {
    $zpl = renderBarcode(zplBarcodeElement('upc_a', '036000291452', [
        'rotation' => 90,
        'width' => 38,
    ]));

    expect($zpl)
        ->toContain('^BUR,')
        ->toContain('^FO40,79^BUR,172,Y,N');
});

function renderBarcode(array $element): string
{
    return (new ZplRenderer)->render(
        new ResolvedLabelDefinition([$element], []),
        new LabelRenderContext(101.6, 50.8, 203),
    );
}

function zplBarcodeElement(string $symbology, string $value, array $overrides = []): array
{
    return [
        'id' => '01K00000000000000000000000',
        'type' => 'barcode',
        'x' => 5,
        'y' => 5,
        'width' => 50,
        'height' => 25,
        'rotation' => 0,
        'symbology' => $symbology,
        'value' => $value,
        ...$overrides,
    ];
}
