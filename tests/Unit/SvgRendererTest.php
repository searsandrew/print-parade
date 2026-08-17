<?php

use App\Labels\Definitions\ResolvedLabelDefinition;
use App\Labels\Examples\CalibrationLabel;
use App\Labels\Rendering\LabelRenderContext;
use App\Labels\Rendering\LabelRenderer;
use App\Labels\Rendering\SvgRenderer;

test('the calibration label renders as a stock-sized svg preview', function () {
    $label = CalibrationLabel::definition()->resolve(
        CalibrationLabel::sampleInput(),
        CalibrationLabel::sampleSystemValues(),
    );
    $renderer = new SvgRenderer;

    $svg = $renderer->render(
        $label,
        new LabelRenderContext(
            CalibrationLabel::WIDTH_IN_MILLIMETERS,
            CalibrationLabel::HEIGHT_IN_MILLIMETERS,
            203,
        ),
    );

    expect($renderer)->toBeInstanceOf(LabelRenderer::class)
        ->and($svg)->toStartWith('<svg')
        ->and($svg)->toContain('width="101.6mm" height="50.8mm"')
        ->and($svg)->toContain('data-preview="approximate" data-dpi="203"')
        ->and($svg)->toContain('PART: ABC-123')
        ->and($svg)->toContain('MADE IN USA')
        ->and($svg)->toContain('CMM023 (0826) | A7K29Q4M')
        ->and($svg)->toContain('data-barcode-symbology="code128"')
        ->and($svg)->toContain('data-barcode-symbology="upc_a"')
        ->and($svg)->toContain('data-barcode-symbology="qr_code"')
        ->and(substr_count($svg, 'data:image/svg+xml;base64,'))->toBe(3)
        ->and($svg)->not->toContain('{{');
});

test('linear barcode bars shorten upward while text remains anchored to the bottom', function () {
    $base = svgBarcodeElement(['bar_height' => 14]);
    $short = svgBarcodeElement(['bar_height' => 10]);
    $context = new LabelRenderContext(101.6, 50.8, 203);
    $renderer = new SvgRenderer;

    $tallSvg = $renderer->render(new ResolvedLabelDefinition([$base], []), $context);
    $shortSvg = $renderer->render(new ResolvedLabelDefinition([$short], []), $context);

    expect($tallSvg)->toContain('<image x="5" y="10.5" width="38" height="14"')
        ->and($shortSvg)->toContain('<image x="5" y="14.5" width="38" height="10"')
        ->and($tallSvg)->toContain('<text x="24" y="27.7"')
        ->and($shortSvg)->toContain('<text x="24" y="27.7"');
});

test('preview text is escaped as xml content', function () {
    $element = [
        'id' => '01K00000000000000000000000',
        'type' => 'text',
        'x' => 5,
        'y' => 5,
        'width' => 50,
        'height' => 10,
        'rotation' => 0,
        'value' => '<unsafe & text>',
        'style' => [
            'font_family' => 'sans',
            'font_size' => 4,
            'font_weight' => 'normal',
            'alignment' => 'left',
        ],
    ];

    $svg = (new SvgRenderer)->render(
        new ResolvedLabelDefinition([$element], []),
        new LabelRenderContext(101.6, 50.8, 203),
    );

    expect($svg)->toContain('&lt;unsafe &amp; text&gt;')
        ->not->toContain('<unsafe & text>');
});

function svgBarcodeElement(array $overrides = []): array
{
    return [
        'id' => '01K00000000000000000000000',
        'type' => 'barcode',
        'x' => 5,
        'y' => 5,
        'width' => 38,
        'height' => 23,
        'rotation' => 0,
        'symbology' => 'upc_a',
        'value' => '036000291452',
        ...$overrides,
    ];
}
