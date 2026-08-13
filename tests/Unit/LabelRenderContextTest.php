<?php

use App\Labels\Rendering\LabelRenderContext;
use App\Models\LabelStock;

test('millimeters are converted to printer dots for supported resolutions', function (int $dpi, int $dots) {
    $context = new LabelRenderContext(101.6, 50.8, $dpi);

    expect($context->millimetersToDots(25.4))->toBe($dots)
        ->and($context->widthInDots())->toBe($dots * 4)
        ->and($context->heightInDots())->toBe($dots * 2);
})->with([
    '203 DPI' => [203, 203],
    '300 DPI' => [300, 300],
]);

test('a render context can be created from label stock', function () {
    $stock = new LabelStock([
        'name' => '4 × 2 Thermal Label',
        'width' => '101.600',
        'height' => '50.800',
        'media_type' => 'gap',
        'is_active' => true,
    ]);

    $context = LabelRenderContext::fromStock($stock, 203);

    expect($context->widthInMillimeters)->toBe(101.6)
        ->and($context->heightInMillimeters)->toBe(50.8);
});

test('unsupported printer resolutions are rejected', function () {
    new LabelRenderContext(101.6, 50.8, 600);
})->throws(InvalidArgumentException::class);
