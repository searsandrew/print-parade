<?php

use App\Labels\Examples\CalibrationLabel;
use App\Labels\Rendering\LabelRenderContext;
use App\Labels\Rendering\ZplRenderer;

test('the calibration label exercises all supported zpl elements', function () {
    $resolved = CalibrationLabel::definition()->resolve(
        CalibrationLabel::sampleInput(),
        CalibrationLabel::sampleSystemValues(),
    );

    expect(array_column($resolved->elements(), 'type'))
        ->toBe([
            'rectangle',
            'text',
            'text',
            'barcode',
            'line',
            'job_identifier',
            'barcode',
            'barcode',
        ])
        ->and($resolved->values()['country_of_origin'])->toBe('USA');
});

test('the optional mixed replacement text appears when supplied', function () {
    $input = [
        ...CalibrationLabel::sampleInput(),
        'replacement_part_number' => 'OLD-456',
    ];

    $resolved = CalibrationLabel::definition()->resolve($input, CalibrationLabel::sampleSystemValues());

    expect(array_column($resolved->elements(), 'value'))
        ->toContain('Replacement for OLD-456');
});

test('the calibration label renders at supported printer resolutions', function (int $dpi, string $width, string $upcModule, string $code128Module) {
    $resolved = CalibrationLabel::definition()->resolve(
        CalibrationLabel::sampleInput(),
        CalibrationLabel::sampleSystemValues(),
    );
    $context = new LabelRenderContext(
        CalibrationLabel::WIDTH_IN_MILLIMETERS,
        CalibrationLabel::HEIGHT_IN_MILLIMETERS,
        $dpi,
    );

    $zpl = (new ZplRenderer)->render($resolved, $context);

    expect($zpl)
        ->toStartWith("^XA\n^CI28\n^PW{$width}")
        ->toContain("^BY{$code128Module},2")
        ->toContain("^BY{$upcModule},2")
        ->toContain('Y,N^FD03600029145^FS')
        ->toContain('^FDMA,https://example.com/p/ABC-123^FS')
        ->toContain('^FDCMM023 (0826) | A7K29Q4M^FS')
        ->not->toContain('{{');
})->with([
    '203 DPI' => [203, '812', '2', '3'],
    '300 DPI' => [300, '1200', '3', '4'],
]);
